<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/**
 * 报表统一筛选。
 *
 * 所有报表入口（dashboard / P90 / P94 / 导出）共用同一套规范化与数据范围
 * 叠加逻辑：先由 QuoteDataScopeService 校验并叠加数据权限，再按白名单字段
 * 追加业务筛选，维度之间均为 AND 交集。
 */
class ReportFilterService
{
    /** 允许的筛选字段白名单。 */
    const ALLOWED_KEYS = [
        'company', 'sales_org_id', 'product_line', 'region_id', 'owner_id',
        'currency', 'created_from', 'created_to', 'status', 'quote_ids',
    ];

    /** 报价状态白名单，与 cpq_quote.status 枚举保持一致。 */
    const QUOTE_STATUSES = [
        'draft', 'submitted', 'approved', 'sent', 'accepted', 'rejected',
        'withdrawn', 'returned', 'cancelled', 'expired', 'revised',
    ];

    /** @var QuoteDataScopeService */
    private $scope;

    public function __construct(QuoteDataScopeService $scope)
    {
        $this->scope = $scope;
    }

    /** @return QuoteDataScopeService */
    public function scope()
    {
        return $this->scope;
    }

    /**
     * 规范化筛选入参：仅接受白名单字段，非法取值立即抛出异常。
     *
     * @param array $filters
     * @return array
     */
    public function normalize(array $filters)
    {
        $normalized = [];

        if (array_key_exists('company', $filters) && $filters['company'] !== null && $filters['company'] !== '') {
            $company = trim((string)$filters['company']);
            if ($company === '' || mb_strlen($company) > 100) {
                throw new InvalidArgumentException('company 筛选不合法');
            }
            $normalized['company'] = $company;
        }

        foreach (['sales_org_id', 'region_id', 'owner_id'] as $idKey) {
            if (array_key_exists($idKey, $filters) && $filters[$idKey] !== null && $filters[$idKey] !== '') {
                $normalized[$idKey] = $this->normalizePositiveId($filters[$idKey], $idKey);
            }
        }

        if (array_key_exists('product_line', $filters) && $filters['product_line'] !== null && $filters['product_line'] !== '') {
            $line = trim((string)$filters['product_line']);
            if ($line === '' || mb_strlen($line) > 64 || !preg_match('/^[A-Za-z0-9_.\-]+$/', $line)) {
                throw new InvalidArgumentException('product_line 筛选不合法');
            }
            $normalized['product_line'] = $line;
        }

        if (array_key_exists('currency', $filters) && $filters['currency'] !== null && $filters['currency'] !== '') {
            $currency = strtoupper(trim((string)$filters['currency']));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new InvalidArgumentException('currency 必须为大写三位字母');
            }
            $normalized['currency'] = $currency;
        }

        foreach (['created_from', 'created_to'] as $dateKey) {
            if (array_key_exists($dateKey, $filters) && $filters[$dateKey] !== null && $filters[$dateKey] !== '') {
                $normalized[$dateKey] = $this->normalizeDate($filters[$dateKey], $dateKey);
            }
        }
        if (isset($normalized['created_from'], $normalized['created_to'])
            && $normalized['created_from'] > $normalized['created_to']) {
            throw new InvalidArgumentException('created_from 不得晚于 created_to');
        }

        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $normalized['status'] = $this->normalizeStatuses($filters['status']);
        }

        if (array_key_exists('quote_ids', $filters) && $filters['quote_ids'] !== null && $filters['quote_ids'] !== '') {
            $normalized['quote_ids'] = $this->normalizeIdList($filters['quote_ids'], 'quote_ids');
        }

        // 越权校验前移到规范化阶段：显式越权筛选立即抛 InvalidArgumentException，
        // 各入口统一映射 CPQ_FILTER_INVALID，不再延迟到查询组装。
        $this->scope->assertFilters($normalized);

        return $normalized;
    }

    /**
     * 将统一筛选叠加到包含报价（q）与客户（c）别名的 Query Builder。
     *
     * @param object $query
     * @param array  $filters      必须为 normalize 后的结果
     * @param string $quoteAlias
     * @param string $customerAlias
     * @return object
     */
    public function applyToQuoteQuery($query, array $filters, $quoteAlias = 'q', $customerAlias = 'c')
    {
        $this->assertAlias($quoteAlias);
        $this->assertAlias($customerAlias);

        $this->scope->assertFilters($filters);
        $query = $this->scope->applyToQuoteQuery($query, $quoteAlias, $customerAlias);

        if (isset($filters['company'])) {
            $query->where($quoteAlias . '.company', $filters['company']);
        }
        if (isset($filters['product_line'])) {
            $query->where($quoteAlias . '.product_line', $filters['product_line']);
        }
        if (isset($filters['sales_org_id'])) {
            $query->where(
                $quoteAlias . '.sales_org_id',
                'in',
                $this->expandTree('cpq_sales_org', (int)$filters['sales_org_id'])
            );
        }
        if (isset($filters['region_id'])) {
            $query->where(
                $customerAlias . '.region_id',
                'in',
                $this->expandTree('cpq_region', (int)$filters['region_id'])
            );
        }
        if (isset($filters['owner_id'])) {
            $query->where($quoteAlias . '.owner_id', (int)$filters['owner_id']);
        }
        if (isset($filters['currency'])) {
            $query->where($quoteAlias . '.currency', $filters['currency']);
        }
        if (isset($filters['created_from'])) {
            $query->where($quoteAlias . '.createtime', '>=', strtotime($filters['created_from'] . ' 00:00:00'));
        }
        if (isset($filters['created_to'])) {
            $query->where($quoteAlias . '.createtime', '<=', strtotime($filters['created_to'] . ' 23:59:59'));
        }
        if (isset($filters['status'])) {
            $query->where($quoteAlias . '.status', 'in', $filters['status']);
        }
        if (isset($filters['quote_ids'])) {
            $query->where($quoteAlias . '.id', 'in', $filters['quote_ids']);
        }

        // TP5 原生查询在终结操作后清空状态；包装为 ReportQuery 以支持
        // 报表场景 select 后继续 sum/column 等链式聚合。
        return ReportQuery::fromQuery($query);
    }

    /**
     * @param mixed $value
     * @param string $name
     * @return int
     */
    private function normalizePositiveId($value, $name)
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            $id = (int)$value;
        } else {
            throw new InvalidArgumentException($name . ' 必须为正整数');
        }
        if ($id <= 0) {
            throw new InvalidArgumentException($name . ' 必须为正整数');
        }
        return $id;
    }

    /**
     * @param mixed $value
     * @param string $name
     * @return string
     */
    private function normalizeDate($value, $name)
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException($name . ' 必须为 Y-m-d 日期');
        }
        $value = trim($value);
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($name . ' 必须为 Y-m-d 日期');
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function normalizeStatuses($value)
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $statuses = [];
        foreach ($items as $item) {
            $status = trim((string)$item);
            if ($status === '') {
                continue;
            }
            if (!in_array($status, self::QUOTE_STATUSES, true)) {
                throw new InvalidArgumentException('status 包含非法报价状态：' . $status);
            }
            $statuses[] = $status;
        }
        $statuses = array_values(array_unique($statuses));
        if ($statuses === []) {
            throw new InvalidArgumentException('status 筛选不能为空');
        }
        return $statuses;
    }

    /**
     * @param mixed $value
     * @param string $name
     * @return array
     */
    private function normalizeIdList($value, $name)
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $ids = [];
        foreach ($items as $item) {
            if ($item === '' || $item === null) {
                continue;
            }
            $ids[] = $this->normalizePositiveId(is_string($item) ? trim($item) : $item, $name);
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw new InvalidArgumentException($name . ' 筛选不能为空');
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * 展开树节点自身及全部下级（基于物化路径）。
     *
     * @param string $table
     * @param int    $rootId
     * @return array
     */
    private function expandTree($table, $rootId)
    {
        $ids = Db::name($table)
            ->where('id', $rootId)
            ->whereOr('path', 'like', '%/' . $rootId . '/%')
            ->column('id');
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            $ids = [$rootId];
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * @param string $alias
     * @return void
     */
    private function assertAlias($alias)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$alias)) {
            throw new InvalidArgumentException('非法查询别名');
        }
    }
}
