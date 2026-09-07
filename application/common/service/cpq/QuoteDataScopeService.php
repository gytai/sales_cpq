<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/**
 * 报价报表数据范围。
 *
 * 产品线、销售组织、客户区域和负责人四个维度始终取交集。普通销售在
 * 组织/区域范围上继续附加 owner-only；销售经理可查看其负责组织及下级
 * 的团队报价。公司价格管理员、主数据管理员与审计员按方案角色表为
 * “全公司”范围（审计只读由菜单/按钮权限约束）；产线价格管理员、产品
 * 经理与财务审核只按授权产品线收窄，不叠加组织/区域/负责人维度
 * （P91 折扣毛利分析的目标读者即产线/公司价格管理员与财务）。
 * 除上述角色外，缺少任一必要授权均关闭范围。
 */
class QuoteDataScopeService
{
    /** 数据范围为全公司的角色（见方案 2 用户角色与数据权限表）。 */
    const COMPANY_WIDE_ROLES = ['system_admin', 'auditor', 'company_pricer', 'master_data_admin'];

    /** 仅按授权产品线收窄的角色（不叠加组织/区域/负责人维度）。 */
    const PRODUCT_LINE_ONLY_ROLES = ['line_pricer', 'product_manager', 'finance_reviewer'];

    /** @var int */
    private $adminId;

    /** @var array */
    private $roles = [];

    /** @var bool */
    private $unrestricted = false;

    /** @var bool */
    private $ownerOnly = false;

    /** @var bool 仅按产品线收窄（不叠加组织/区域/负责人维度） */
    private $productLineOnly = false;

    /** @var array|null null 表示产品线不受限 */
    private $allowedProductLines;

    /** @var array */
    private $allowedSalesOrgIds = [];

    /** @var array */
    private $allowedRegionIds = [];

    /** @var array */
    private $allowedOwnerIds = [];

    /** @var array */
    private $activeSalesOrgs = [];

    /**
     * @param int $adminId
     */
    public function __construct($adminId)
    {
        $this->adminId = (int)$adminId;
        $this->roles = SensitiveFieldService::rolesOfAdmin($this->adminId);
        $this->unrestricted = (bool)array_intersect(self::COMPANY_WIDE_ROLES, $this->roles);

        if ($this->unrestricted) {
            $this->allowedProductLines = null;
            return;
        }

        $productScope = ProductLineScopeService::forAdmin($this->adminId);
        $this->allowedProductLines = $productScope->getAllowedLines();
        $this->ownerOnly = in_array('sales', $this->roles, true)
            && !in_array('sales_manager', $this->roles, true);

        if ($this->adminId <= 0 || $this->roles === []) {
            $this->allowedProductLines = [];
            return;
        }

        // 兼具销售/销售经理角色时仍按组织子树收窄（交集取最严）；
        // 仅含产线价格/产品经理/财务角色时只按产品线收窄。
        $this->productLineOnly = !$this->ownerOnly
            && !in_array('sales_manager', $this->roles, true)
            && (bool)array_intersect(self::PRODUCT_LINE_ONLY_ROLES, $this->roles);
        if ($this->productLineOnly) {
            return;
        }

        $this->activeSalesOrgs = $this->loadActiveSalesOrgs();
        $rootIds = $this->loadAuthorizedOrgRoots();
        $this->allowedSalesOrgIds = $this->expandSalesOrgTree($rootIds);
        $this->allowedRegionIds = $this->loadAllowedRegionIds($this->allowedSalesOrgIds);
        $this->allowedOwnerIds = $this->loadAllowedOwnerIds();
    }

    /**
     * @param int $adminId
     * @return self
     */
    public static function forAdmin($adminId)
    {
        return new self($adminId);
    }

    /** @return bool */
    public function isUnrestricted()
    {
        return $this->unrestricted;
    }

    /** @return bool */
    public function isOwnerOnly()
    {
        return $this->ownerOnly;
    }

    /** @return array|null */
    public function getAllowedProductLines()
    {
        return $this->allowedProductLines;
    }

    /** @return array */
    public function getAllowedSalesOrgIds()
    {
        return $this->allowedSalesOrgIds;
    }

    /** @return array */
    public function getAllowedRegionIds()
    {
        return $this->allowedRegionIds;
    }

    /** @return array */
    public function getAllowedOwnerIds()
    {
        return $this->allowedOwnerIds;
    }

    /**
     * 对显式数据范围筛选做越权校验。
     *
     * @param array $filters 已规范化筛选
     * @return void
     */
    public function assertFilters(array $filters)
    {
        if ($this->unrestricted) {
            return;
        }

        if (isset($filters['product_line'])
            && !($this->allowedProductLines !== null
                && in_array($filters['product_line'], $this->allowedProductLines, true))) {
            throw new InvalidArgumentException('无权筛选该产品线');
        }
        if ($this->productLineOnly) {
            // 仅产品线收窄的角色不按组织/区域/负责人维度授权，
            // 这些维度对其是合法的分析筛选，不做越权校验。
            return;
        }
        if (isset($filters['sales_org_id'])
            && !in_array((int)$filters['sales_org_id'], $this->allowedSalesOrgIds, true)) {
            throw new InvalidArgumentException('无权筛选该销售组织');
        }
        if (isset($filters['region_id'])
            && !in_array((int)$filters['region_id'], $this->allowedRegionIds, true)) {
            throw new InvalidArgumentException('无权筛选该区域');
        }
        if (isset($filters['owner_id'])
            && !in_array((int)$filters['owner_id'], $this->allowedOwnerIds, true)) {
            throw new InvalidArgumentException('无权筛选该报价负责人');
        }
    }

    /**
     * 将数据权限附加到已包含报价和客户别名的 Query Builder。
     *
     * @param object $query
     * @param string $quoteAlias
     * @param string $customerAlias
     * @return object
     */
    public function applyToQuoteQuery($query, $quoteAlias = 'q', $customerAlias = 'c')
    {
        $this->assertAlias($quoteAlias);
        $this->assertAlias($customerAlias);

        if ($this->unrestricted) {
            return $query;
        }

        if (!$this->hasUsableScope()) {
            return $query->where($quoteAlias . '.id', 0);
        }

        if ($this->allowedProductLines !== null) {
            $query->where($quoteAlias . '.product_line', 'in', $this->allowedProductLines);
        }
        if ($this->productLineOnly) {
            return $query;
        }
        $query->where($quoteAlias . '.sales_org_id', 'in', $this->allowedSalesOrgIds);
        $query->where($customerAlias . '.region_id', 'in', $this->allowedRegionIds);
        if ($this->ownerOnly) {
            $query->where($quoteAlias . '.owner_id', $this->adminId);
        }
        return $query;
    }

    /** @return bool */
    private function hasUsableScope()
    {
        if ($this->roles === []) {
            return false;
        }
        if ($this->allowedProductLines !== null && $this->allowedProductLines === []) {
            return false;
        }
        if ($this->productLineOnly) {
            return true;
        }
        return $this->allowedSalesOrgIds !== []
            && $this->allowedRegionIds !== [];
    }

    /** @return array */
    private function loadActiveSalesOrgs()
    {
        $rows = Db::name('cpq_sales_org')
            ->where('status', 'normal')
            ->field('id,parent_id,path,manager_id,effective_date,expiry_date')
            ->select();
        $active = [];
        foreach ($rows as $row) {
            if ($this->isEffective($row)) {
                $active[(int)$row['id']] = $row;
            }
        }
        return $active;
    }

    /** @return array */
    private function loadAuthorizedOrgRoots()
    {
        $isManager = in_array('sales_manager', $this->roles, true);
        $roots = [];
        if ($isManager) {
            $memberRows = Db::name('cpq_sales_org_member')
                ->where('admin_id', $this->adminId)
                ->where('role', 'sales_manager')
                ->where('status', 'normal')
                ->field('org_id,effective_date,expiry_date')
                ->select();
            foreach ($memberRows as $row) {
                $orgId = (int)$row['org_id'];
                if (isset($this->activeSalesOrgs[$orgId]) && $this->isEffective($row)) {
                    $roots[] = $orgId;
                }
            }
            foreach ($this->activeSalesOrgs as $orgId => $org) {
                if ((int)$org['manager_id'] === $this->adminId) {
                    $roots[] = (int)$orgId;
                }
            }
            return $this->uniquePositiveIds($roots);
        }

        if (in_array('sales', $this->roles, true)) {
            $memberRows = Db::name('cpq_sales_org_member')
                ->where('admin_id', $this->adminId)
                ->where('role', 'sales')
                ->where('status', 'normal')
                ->field('org_id,effective_date,expiry_date')
                ->select();
            foreach ($memberRows as $row) {
                $orgId = (int)$row['org_id'];
                if (isset($this->activeSalesOrgs[$orgId]) && $this->isEffective($row)) {
                    $roots[] = $orgId;
                }
            }
        }
        return $this->uniquePositiveIds($roots);
    }

    /**
     * @param array $rootIds
     * @return array
     */
    private function expandSalesOrgTree(array $rootIds)
    {
        if ($rootIds === []) {
            return [];
        }
        $allowed = [];
        foreach ($this->activeSalesOrgs as $orgId => $org) {
            $path = (string)$org['path'];
            foreach ($rootIds as $rootId) {
                if ((int)$orgId === (int)$rootId
                    || strpos($path, '/' . (int)$rootId . '/') !== false) {
                    $allowed[] = (int)$orgId;
                    break;
                }
            }
        }
        return $this->uniquePositiveIds($allowed);
    }

    /**
     * 区域授权只能由负责组织或组织内有效客户推导，不存在独立授权表时不放开任意区域。
     *
     * @param array $orgIds
     * @return array
     */
    private function loadAllowedRegionIds(array $orgIds)
    {
        if ($orgIds === []) {
            return [];
        }
        $regionRows = Db::name('cpq_region')
            ->where('status', 'normal')
            ->field('id,sales_org_id')
            ->select();
        $activeRegionIds = [];
        $allowed = [];
        foreach ($regionRows as $row) {
            $regionId = (int)$row['id'];
            $activeRegionIds[$regionId] = true;
            if (in_array((int)$row['sales_org_id'], $orgIds, true)) {
                $allowed[] = $regionId;
            }
        }

        $customerRegions = Db::name('cpq_customer')
            ->where('status', 'normal')
            ->where('sales_org_id', 'in', $orgIds)
            ->column('region_id');
        foreach ($customerRegions as $regionId) {
            $regionId = (int)$regionId;
            if ($regionId > 0 && isset($activeRegionIds[$regionId])) {
                $allowed[] = $regionId;
            }
        }
        return $this->uniquePositiveIds($allowed);
    }

    /** @return array */
    private function loadAllowedOwnerIds()
    {
        if ($this->ownerOnly) {
            return $this->adminId > 0 ? [$this->adminId] : [];
        }
        if ($this->allowedSalesOrgIds === [] || $this->allowedRegionIds === []) {
            return [];
        }

        $owners = [$this->adminId];
        $memberRows = Db::name('cpq_sales_org_member')
            ->where('status', 'normal')
            ->where('org_id', 'in', $this->allowedSalesOrgIds)
            ->field('admin_id,effective_date,expiry_date')
            ->select();
        foreach ($memberRows as $row) {
            if ($this->isEffective($row)) {
                $owners[] = (int)$row['admin_id'];
            }
        }
        foreach ($this->allowedSalesOrgIds as $orgId) {
            $owners[] = (int)$this->activeSalesOrgs[$orgId]['manager_id'];
        }

        $quoteOwners = Db::name('cpq_quote')->alias('q')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT')
            ->where('q.sales_org_id', 'in', $this->allowedSalesOrgIds)
            ->where('c.region_id', 'in', $this->allowedRegionIds);
        if ($this->allowedProductLines !== null && $this->allowedProductLines !== []) {
            $quoteOwners->where('q.product_line', 'in', $this->allowedProductLines);
        }
        $owners = array_merge($owners, $quoteOwners->column('q.owner_id'));
        return $this->uniquePositiveIds($owners);
    }

    /**
     * @param array $row
     * @return bool
     */
    private function isEffective(array $row)
    {
        $today = date('Y-m-d');
        $start = trim((string)($row['effective_date'] ?? ''));
        $end = trim((string)($row['expiry_date'] ?? ''));
        return ($start === '' || $start <= $today)
            && ($end === '' || $end >= $today);
    }

    /**
     * 单条报价的越权访问兜底。
     *
     * 组织/区域/负责人维度在列表查询层由 applyToQuoteQuery 收窄，
     * 详情/编辑/提交/PDF 等单条入口由本方法补齐同一口径；产品线维度
     * 仍由 ProductLineScopeService::assertLineAllowed 校验。
     *
     * @param array $quote fa_cpq_quote 行（至少含 owner_id/sales_org_id/customer_id）
     * @return void
     */
    public function assertQuoteAccess(array $quote)
    {
        if ($this->unrestricted || $this->productLineOnly) {
            return;
        }
        if (!$this->hasUsableScope()) {
            throw new InvalidArgumentException('无该报价的数据权限');
        }
        if (!in_array((int)($quote['sales_org_id'] ?? 0), $this->allowedSalesOrgIds, true)) {
            throw new InvalidArgumentException('无该报价的数据权限');
        }
        $regionId = (int)Db::name('cpq_customer')
            ->where('id', (int)($quote['customer_id'] ?? 0))
            ->value('region_id');
        if (!in_array($regionId, $this->allowedRegionIds, true)) {
            throw new InvalidArgumentException('无该报价的数据权限');
        }
        if ($this->ownerOnly && (int)($quote['owner_id'] ?? 0) !== $this->adminId) {
            throw new InvalidArgumentException('无该报价的数据权限');
        }
    }

    /**
     * @param array $ids
     * @return array
     */
    private function uniquePositiveIds(array $ids)
    {
        $normalized = array_map('intval', $ids);
        $normalized = array_values(array_unique(array_filter($normalized, function ($id) {
            return $id > 0;
        })));
        sort($normalized, SORT_NUMERIC);
        return $normalized;
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
