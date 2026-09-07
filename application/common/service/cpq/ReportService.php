<?php

namespace app\common\service\cpq;

use think\Db;

/**
 * P90-P93 报表中心聚合服务（GYTAI-78）。
 *
 *  - funnel                 P90 报价漏斗（六段 + 转化率 + 段内 top 报价）
 *  - discountMargin         P91 折扣与毛利分析（产品线→型号聚合，按角色脱敏）
 *  - approvalEfficiency     P92 审批效率（节点/审批人两维，SLA 超时与拒绝率）
 *  - configurationAnalysis  P93 配置分析（top 型号、选项频次、校验失败统计）
 *
 * 所有入口统一经 ReportFilterService 规范化筛选 + 数据范围；金额一律输出
 * Decimal 字符串，前端不做浮点运算。
 */
class ReportService
{
    /** 漏斗固定六段顺序（与 DashboardService 一致）。 */
    const FUNNEL_STATUSES = ['draft', 'submitted', 'approved', 'sent', 'accepted', 'expired'];

    /** P91 行级解析上限（毛利需有界解析冻结快照 JSON，无法纯 SQL 聚合）。 */
    const MARGIN_ROW_LIMIT = 20000;

    /** P93 配置 JSON 采样上限。 */
    const CONFIG_SAMPLE_LIMIT = 500;

    /** @var int */
    private $adminId;

    /**
     * @param int $adminId
     */
    public function __construct($adminId)
    {
        $this->adminId = (int)$adminId;
    }

    /**
     * 规范化原始筛选入参。
     *
     * @param array $raw
     * @return array [规范化筛选, ReportFilterService]
     */
    public function normalize(array $raw)
    {
        $filterService = new ReportFilterService(QuoteDataScopeService::forAdmin($this->adminId));
        return [$filterService->normalize($raw), $filterService];
    }

    // ------------------------------------------------------------------
    // P90 报价漏斗
    // ------------------------------------------------------------------

    /**
     * @param array $filters 规范化筛选
     * @return array
     */
    public function funnel(array $filters)
    {
        $filterService = $this->filterService();

        $counts = [];
        foreach ($this->quoteQuery($filters, $filterService)
            ->field('q.status, COUNT(*) AS cnt')->group('q.status')->select() as $row) {
            $counts[$row['status']] = (int)$row['cnt'];
        }

        $amounts = [];
        foreach ($this->withCurrentSnapshot($this->quoteQuery($filters, $filterService))
            ->field('q.status, q.currency, SUM(p.total_amount) AS amount')
            ->group('q.status, q.currency')->select() as $row) {
            $status = (string)$row['status'];
            if (!isset($amounts[$status])) {
                $amounts[$status] = [];
            }
            $amounts[$status][(string)$row['currency']] = (string)$row['amount'];
        }

        $segments = [];
        $previousCount = null;
        foreach (self::FUNNEL_STATUSES as $status) {
            $count = isset($counts[$status]) ? $counts[$status] : 0;
            // 相邻转化率：上一段为分母，分母为 0 时给 '0.000000'
            $conversionRate = ($previousCount !== null && $previousCount > 0)
                ? bcdiv((string)$count, (string)$previousCount, 6)
                : '0.000000';
            $segmentFilters = $filters;
            $segmentFilters['status'] = $status;
            $segments[] = [
                'status' => $status,
                'count' => $count,
                'amounts' => isset($amounts[$status]) ? (object)$amounts[$status] : new \stdClass(),
                'conversion_rate' => $previousCount === null ? '' : $conversionRate,
                'top_quotes' => $this->topQuotes($segmentFilters, $filterService),
                'drilldown' => ['status' => $status],
            ];
            $previousCount = $count;
        }
        return ['segments' => $segments];
    }

    /**
     * 段内 top 报价（当前版本快照总额降序，前 10）。
     *
     * @param array               $filters
     * @param ReportFilterService $filterService
     * @return array
     */
    private function topQuotes(array $filters, ReportFilterService $filterService)
    {
        $rows = $this->withCurrentSnapshot($this->quoteQuery($filters, $filterService))
            ->field('q.id, q.code, q.name, c.name AS customer_name, q.currency, SUM(p.total_amount) AS amount')
            ->group('q.id, q.code, q.name, c.name, q.currency')
            ->order('amount desc')
            ->limit(10)
            ->select();
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => (int)$row['id'],
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'customer_name' => (string)$row['customer_name'],
                'currency' => (string)$row['currency'],
                'amount' => (string)$row['amount'],
            ];
        }
        return $list;
    }

    // ------------------------------------------------------------------
    // P91 折扣与毛利分析
    // ------------------------------------------------------------------

    /**
     * 按产品线→型号聚合折扣与毛利。毛利口径与 ExportJobService::snapshotPricing
     * 一致（从 policy_snapshot_json/price_trace_json 有界解析成本）；
     * 返回行经 SensitiveFieldService 递归脱敏，无权角色的成本/毛利字段被移除，
     * 前端按返回键是否存在渲染列。
     *
     * @param array $filters 规范化筛选
     * @param array $roles   当前用户角色编码集合
     * @return array
     */
    public function discountMargin(array $filters, array $roles)
    {
        $filterService = $this->filterService();
        $rows = $this->withCurrentSnapshot($this->quoteQuery($filters, $filterService))
            ->field('q.product_line, p.model_code, p.quantity, p.manual_discount,'
                . ' p.untaxed_amount, p.total_amount, p.policy_snapshot_json, p.price_trace_json')
            ->order('q.product_line asc, p.model_code asc')
            ->limit(self::MARGIN_ROW_LIMIT)
            ->select();

        $groups = [];
        foreach ($rows as $row) {
            $key = (string)$row['product_line'] . '|' . (string)$row['model_code'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'product_line' => (string)$row['product_line'],
                    'model_code' => (string)$row['model_code'],
                    'line_count' => 0,
                    '_discount_sum' => '0',
                    '_untaxed_sum' => '0',
                    '_total_sum' => '0',
                    '_margin_sum' => '0',
                    '_margin_untaxed_sum' => '0',
                ];
            }
            $group = &$groups[$key];
            $group['line_count']++;
            $discount = (string)($row['manual_discount'] === null || $row['manual_discount'] === '' ? '1.000000' : $row['manual_discount']);
            $group['_discount_sum'] = bcadd($group['_discount_sum'], $discount, 6);
            $untaxed = (string)$row['untaxed_amount'];
            $group['_untaxed_sum'] = bcadd($group['_untaxed_sum'], $untaxed, 4);
            $group['_total_sum'] = bcadd($group['_total_sum'], (string)$row['total_amount'], 4);
            $margin = $this->snapshotMargin($row);
            if ($margin !== null) {
                $group['_margin_sum'] = bcadd($group['_margin_sum'], $margin, 4);
                // 毛利率分母只统计有成本的行：无成本行没有毛利口径，
                // 把其未税金额混入分母会稀释毛利率，得出偏低的错误结论。
                $group['_margin_untaxed_sum'] = bcadd($group['_margin_untaxed_sum'], $untaxed, 4);
            }
            unset($group);
        }

        $result = [];
        foreach ($groups as $group) {
            $marginRate = '';
            if (bccomp($group['_margin_untaxed_sum'], '0', 4) > 0) {
                $marginRate = bcdiv($group['_margin_sum'], $group['_margin_untaxed_sum'], 4);
            }
            $result[] = [
                'product_line' => $group['product_line'],
                'model_code' => $group['model_code'],
                'line_count' => $group['line_count'],
                'avg_discount' => $group['line_count'] > 0
                    ? bcdiv($group['_discount_sum'], (string)$group['line_count'], 6)
                    : '1.000000',
                'untaxed_amount' => $group['_untaxed_sum'],
                'total_amount' => $group['_total_sum'],
                'gross_margin_amount' => $group['_margin_sum'],
                'gross_margin_rate' => $marginRate,
            ];
        }
        $result = (new SensitiveFieldService())->maskRows($result, $roles);
        return ['rows' => $result];
    }

    /**
     * 从冻结快照推导单快照行毛利额（未税金额 - 成本×数量），
     * 口径与 ExportJobService::snapshotPricing 一致；快照缺成本时返回 null。
     *
     * @param array $row 含 policy_snapshot_json/price_trace_json/untaxed_amount/quantity 的行
     * @return string|null
     */
    private function snapshotMargin(array $row)
    {
        $policy = json_decode((string)($row['policy_snapshot_json'] ?? ''), true);
        if (!is_array($policy)) {
            $policy = [];
        }
        $cost = isset($policy['cost']) && is_scalar($policy['cost']) ? (string)$policy['cost'] : '';
        if ($cost === '') {
            $trace = json_decode((string)($row['price_trace_json'] ?? ''), true);
            if (is_array($trace) && isset($trace['margin']['cost']) && is_scalar($trace['margin']['cost'])) {
                $cost = (string)$trace['margin']['cost'];
            }
        }
        $untaxed = (string)($row['untaxed_amount'] ?? '');
        $quantity = (string)($row['quantity'] ?? '');
        if ($cost === '' || $untaxed === '' || !is_numeric($cost) || !is_numeric($untaxed)) {
            return null;
        }
        $costTotal = is_numeric($quantity) && bccomp($quantity, '0', 4) > 0
            ? bcmul($cost, $quantity, 4)
            : $cost;
        return bcsub($untaxed, $costTotal, 4);
    }

    // ------------------------------------------------------------------
    // P92 审批效率
    // ------------------------------------------------------------------

    /**
     * 节点与审批人两维效率：只统计 arrived_at 非空的任务。
     *
     * @param array $filters 规范化筛选
     * @return array
     */
    public function approvalEfficiency(array $filters)
    {
        $filterService = $this->filterService();
        $now = time();
        $overdueCase = "CASE WHEN (t.status='pending' AND t.sla_deadline IS NOT NULL AND t.sla_deadline < {$now})"
            . " OR (t.acted_at IS NOT NULL AND t.sla_deadline IS NOT NULL AND t.acted_at > t.sla_deadline) THEN 1 ELSE 0 END";

        $nodeRows = $this->taskQuery($filters, $filterService)
            ->field("t.node, COUNT(*) AS task_count,"
                . " AVG(CASE WHEN t.acted_at IS NOT NULL THEN t.acted_at - t.arrived_at END) AS avg_seconds,"
                . " SUM({$overdueCase}) AS overdue_count,"
                . " SUM(CASE WHEN t.action='reject' OR t.status='rejected' THEN 1 ELSE 0 END) AS reject_count")
            ->group('t.node')
            ->order('t.node asc')
            ->select();
        $byNode = [];
        foreach ($nodeRows as $row) {
            $byNode[] = $this->efficiencyRow([
                'node' => (string)$row['node'],
            ], $row);
        }

        $assigneeRows = $this->taskQuery($filters, $filterService)
            ->join('__ADMIN__ a', 'a.id=t.assignee_id', 'LEFT')
            ->field("t.assignee_id, a.nickname AS assignee_name, COUNT(*) AS task_count,"
                . " AVG(CASE WHEN t.acted_at IS NOT NULL THEN t.acted_at - t.arrived_at END) AS avg_seconds,"
                . " SUM({$overdueCase}) AS overdue_count,"
                . " SUM(CASE WHEN t.action='reject' OR t.status='rejected' THEN 1 ELSE 0 END) AS reject_count")
            ->group('t.assignee_id, a.nickname')
            ->order('task_count desc')
            ->limit(20)
            ->select();
        $byAssignee = [];
        foreach ($assigneeRows as $row) {
            $byAssignee[] = $this->efficiencyRow([
                'assignee_id' => (int)$row['assignee_id'],
                'assignee_name' => (string)$row['assignee_name'],
            ], $row);
        }

        return ['by_node' => $byNode, 'by_assignee' => $byAssignee];
    }

    /**
     * 组装一行效率指标（字符串化比率与时长）。
     *
     * @param array $identity 维度键值
     * @param array $row      聚合行
     * @return array
     */
    private function efficiencyRow(array $identity, array $row)
    {
        $taskCount = (int)$row['task_count'];
        $rejectCount = (int)$row['reject_count'];
        $avgHours = ($row['avg_seconds'] === null || $row['avg_seconds'] === '')
            ? '0.00'
            : number_format(((float)$row['avg_seconds']) / 3600, 2, '.', '');
        return array_merge($identity, [
            'task_count' => $taskCount,
            'avg_hours' => $avgHours,
            'overdue_count' => (int)$row['overdue_count'],
            'reject_rate' => $taskCount > 0 ? bcdiv((string)$rejectCount, (string)$taskCount, 6) : '0.000000',
        ]);
    }

    // ------------------------------------------------------------------
    // P93 配置分析
    // ------------------------------------------------------------------

    /**
     * @param array $filters 规范化筛选
     * @return array
     */
    public function configurationAnalysis(array $filters)
    {
        $filterService = $this->filterService();

        $topModelRows = $this->configQuery($filters, $filterService)
            ->field('s.model_code, COUNT(*) AS line_count, COUNT(DISTINCT q.id) AS quote_count')
            ->group('s.model_code')
            ->order('line_count desc')
            ->limit(20)
            ->select();
        $topModels = [];
        foreach ($topModelRows as $row) {
            $topModels[] = [
                'model_code' => (string)$row['model_code'],
                'line_count' => (int)$row['line_count'],
                'quote_count' => (int)$row['quote_count'],
            ];
        }

        $validatedTotal = (int)$this->configQuery($filters, $filterService)->count();
        $invalidCount = (int)$this->configQuery($filters, $filterService)->where('s.is_valid', 0)->count();

        // 选项频次：有界采样（最多 500 行，JSON 深度 ≤3）
        $sample = $this->configQuery($filters, $filterService)
            ->field('s.configuration')
            ->order('s.id desc')
            ->limit(self::CONFIG_SAMPLE_LIMIT)
            ->select();
        $counter = [];
        foreach ($sample as $row) {
            $configuration = json_decode((string)$row['configuration'], true);
            if (is_array($configuration)) {
                $this->collectOptions($configuration, 1, $counter);
            }
        }
        $options = array_values($counter);
        usort($options, function ($a, $b) {
            return $b['count'] - $a['count'];
        });
        $options = array_slice($options, 0, 30);

        return [
            'top_models' => $topModels,
            'option_frequency' => $options,
            'invalid_count' => $invalidCount,
            'validated_total' => $validatedTotal,
        ];
    }

    /**
     * 递归统计配置键值对（深度 ≤3，仅标量值）。
     *
     * @param array $node
     * @param int   $depth
     * @param array $counter
     * @return void
     */
    private function collectOptions(array $node, $depth, array &$counter)
    {
        if ($depth > 3) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->collectOptions($value, $depth + 1, $counter);
                continue;
            }
            if (!is_scalar($value) || $value === '' || $value === null) {
                continue;
            }
            $key = mb_substr((string)$key, 0, 100);
            $value = mb_substr((string)$value, 0, 100);
            $mapKey = $key . "\0" . $value;
            if (!isset($counter[$mapKey])) {
                $counter[$mapKey] = ['key' => $key, 'value' => $value, 'count' => 0];
            }
            $counter[$mapKey]['count']++;
        }
    }

    // ------------------------------------------------------------------
    // 查询构造
    // ------------------------------------------------------------------

    /** @return ReportFilterService */
    private function filterService()
    {
        return new ReportFilterService(QuoteDataScopeService::forAdmin($this->adminId));
    }

    /**
     * @param array               $filters 规范化筛选
     * @param ReportFilterService $filterService
     * @return ReportQuery
     */
    private function quoteQuery(array $filters, ReportFilterService $filterService)
    {
        $query = Db::name('cpq_quote')->alias('q')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT');
        return $filterService->applyToQuoteQuery($query, $filters, 'q', 'c');
    }

    /**
     * @param object $query
     * @return object
     */
    private function withCurrentSnapshot($query)
    {
        return $query
            ->join('__CPQ_QUOTE_REVISION__ r', 'r.quote_id=q.id AND r.revision_no=q.current_revision_no')
            ->join('__CPQ_QUOTE_PRICE_SNAPSHOT__ p', 'p.revision_id=r.id');
    }

    /**
     * 审批任务基查询（统一筛选施加于 q/c 别名）。
     *
     * @param array               $filters
     * @param ReportFilterService $filterService
     * @return ReportQuery
     */
    private function taskQuery(array $filters, ReportFilterService $filterService)
    {
        $query = Db::name('cpq_approval_task')->alias('t')
            ->join('__CPQ_APPROVAL_INSTANCE__ i', 'i.id=t.instance_id')
            ->join('__CPQ_QUOTE__ q', 'q.id=t.quote_id')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT')
            ->whereNotNull('t.arrived_at');
        return $filterService->applyToQuoteQuery($query, $filters, 'q', 'c');
    }

    /**
     * 配置快照基查询（统一筛选施加于 q/c 别名）。
     *
     * @param array               $filters
     * @param ReportFilterService $filterService
     * @return ReportQuery
     */
    private function configQuery(array $filters, ReportFilterService $filterService)
    {
        $query = Db::name('cpq_quote_config_snapshot')->alias('s')
            ->join('__CPQ_QUOTE_REVISION__ r', 'r.id=s.revision_id')
            ->join('__CPQ_QUOTE__ q', 'q.id=r.quote_id')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT');
        return $filterService->applyToQuoteQuery($query, $filters, 'q', 'c');
    }
}
