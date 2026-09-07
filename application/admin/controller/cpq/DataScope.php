<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\QuoteDataScopeService;
use app\common\service\cpq\SensitiveFieldService;
use think\Db;

/**
 * CPQ 数据范围授权管理（P100，GYTAI-78）
 *
 * 管理「管理员 ↔ 产品线 / 销售组织」数据范围授权，并预览由
 * QuoteDataScopeService 计算出的有效数据范围。
 * 写动作仅 system_admin / master_data_admin；读动作另允许 auditor；
 * 其他角色一律拒绝（服务端校验，不依赖前端按钮隐藏）。全部写操作写审计。
 *
 * @icon fa fa-shield
 */
class DataScope extends Backend
{
    /** 可写角色（auth_group.name 精确匹配） */
    const WRITE_ROLES = ['system_admin', 'master_data_admin'];

    /** 可读角色 = 可写角色 + auditor */
    const READ_ROLES = ['system_admin', 'master_data_admin', 'auditor'];

    /** @var AuditLogService */
    private $auditService;

    public function _initialize()
    {
        parent::_initialize();
        $this->auditService = new AuditLogService();
    }

    /**
     * 管理员授权列表：存在 CPQ 角色或存在产品线/组织授权记录的管理员。
     */
    public function index()
    {
        $this->assertRead();
        if (!$this->request->isAjax()) {
            $this->assignconfig('canWrite', $this->canWrite());
            return $this->view->fetch();
        }
        $page = max(1, (int)$this->request->request('page', 1));
        $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
        $keyword = trim((string)$this->request->request('keyword', ''));

        $adminIds = $this->cpqRelatedAdminIds();
        $buildQuery = function () use ($adminIds, $keyword) {
            $query = Db::name('admin')->where('id', 'in', $adminIds ?: [0]);
            if ($keyword !== '') {
                $query->where('username|nickname', 'like', '%' . $keyword . '%');
            }
            return $query;
        };
        $total = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->field('id,username,nickname,status')
            ->order('id', 'asc')
            ->limit(($page - 1) * $limit, $limit)
            ->select();
        return json(['total' => $total, 'rows' => $this->decorateAdminRows($rows ?: [])]);
    }

    /**
     * 单个管理员的授权明细 + QuoteDataScopeService 有效范围预览。
     */
    public function detail()
    {
        $this->assertRead();
        $adminId = (int)$this->request->request('admin_id');
        $admin = Db::name('admin')->where('id', $adminId)->field('id,username,nickname')->find();
        if (!$admin) {
            $this->error('管理员不存在');
        }
        $lines = Db::name('cpq_admin_product_line')
            ->where('admin_id', $adminId)
            ->order('id', 'asc')
            ->select();
        $orgs = Db::name('cpq_sales_org_member')->alias('m')
            ->join('__CPQ_SALES_ORG__ o', 'o.id = m.org_id', 'LEFT')
            ->where('m.admin_id', $adminId)
            ->field('m.id,m.org_id,m.role,m.status,m.effective_date,m.expiry_date,o.name AS org_name')
            ->order('m.id', 'asc')
            ->select();
        $scope = QuoteDataScopeService::forAdmin($adminId);
        $this->success('', null, [
            'admin' => $admin,
            'lines' => $lines ?: [],
            'orgs' => $orgs ?: [],
            'effective' => [
                'product_lines' => $scope->getAllowedProductLines(),
                'sales_org_ids' => $scope->getAllowedSalesOrgIds(),
                'region_ids' => $scope->getAllowedRegionIds(),
                'owner_only' => $scope->isOwnerOnly(),
                'unrestricted' => $scope->isUnrestricted(),
            ],
        ]);
    }

    /**
     * 可授权的正常销售组织列表（授权弹层下拉用）。
     */
    public function orgs()
    {
        $this->assertRead();
        $rows = Db::name('cpq_sales_org')
            ->where('status', 'normal')
            ->field('id,name,parent_id,path,level')
            ->order('path', 'asc')
            ->select();
        $this->success('', null, ['rows' => $rows ?: []]);
    }

    /**
     * 授予产品线数据范围（幂等：重复授权直接成功）。
     */
    public function grantline()
    {
        $this->assertWritePost();
        $adminId = (int)$this->request->post('admin_id');
        $productLine = trim((string)$this->request->post('product_line'));
        if (!$this->adminExists($adminId)) {
            $this->error('管理员不存在');
        }
        if (!preg_match('/^(\*|[A-Za-z0-9_.\-]{1,64})$/', $productLine)) {
            $this->error('产品线编码格式无效（1-64 位字母/数字/._-，或 * 表示全部）', null, ['business_code' => 'CPQ_INVALID']);
        }
        $existing = Db::name('cpq_admin_product_line')
            ->where('admin_id', $adminId)
            ->where('product_line', $productLine)
            ->find();
        if ($existing) {
            $this->success('该管理员已拥有此产品线授权', null, ['id' => (int)$existing['id'], 'duplicated' => true]);
        }
        $now = time();
        $id = (int)Db::name('cpq_admin_product_line')->insertGetId([
            'admin_id' => $adminId,
            'product_line' => $productLine,
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        $this->auditService->record('grant_product_line', 'cpq_admin_product_line', $id, [
            'admin_id' => $adminId,
            'product_line' => $productLine,
        ]);
        $this->success('已授权产品线', null, ['id' => $id]);
    }

    /**
     * 撤销产品线授权。
     */
    public function revokeline()
    {
        $this->assertWritePost();
        $id = (int)$this->request->post('id');
        $row = Db::name('cpq_admin_product_line')->where('id', $id)->find();
        if (!$row) {
            $this->error('授权记录不存在');
        }
        Db::name('cpq_admin_product_line')->where('id', $id)->delete();
        $this->auditService->record('revoke_product_line', 'cpq_admin_product_line', $id, [
            'admin_id' => (int)$row['admin_id'],
            'product_line' => (string)$row['product_line'],
        ]);
        $this->success('已撤销产品线授权');
    }

    /**
     * 授予销售组织成员资格（同一组织+管理员+角色幂等；角色变化视为改授）。
     */
    public function grantorg()
    {
        $this->assertWritePost();
        $adminId = (int)$this->request->post('admin_id');
        $orgId = (int)$this->request->post('org_id');
        $role = trim((string)$this->request->post('role'));
        $effectiveDate = trim((string)$this->request->post('effective_date', ''));
        $expiryDate = trim((string)$this->request->post('expiry_date', ''));

        if (!$this->adminExists($adminId)) {
            $this->error('管理员不存在');
        }
        $org = Db::name('cpq_sales_org')->where('id', $orgId)->find();
        if (!$org || (string)$org['status'] !== 'normal') {
            $this->error('销售组织不存在或已停用');
        }
        if (!in_array($role, ['sales', 'sales_manager'], true)) {
            $this->error('组织角色无效（仅支持 sales / sales_manager）', null, ['business_code' => 'CPQ_INVALID']);
        }
        foreach (['effective_date' => $effectiveDate, 'expiry_date' => $expiryDate] as $field => $value) {
            if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $this->error('日期格式无效（YYYY-MM-DD）', null, ['business_code' => 'CPQ_INVALID']);
            }
        }
        if ($effectiveDate !== '' && $expiryDate !== '' && $effectiveDate > $expiryDate) {
            $this->error('生效日期不能晚于失效日期', null, ['business_code' => 'CPQ_INVALID']);
        }

        $now = time();
        $data = [
            'role' => $role,
            'status' => 'normal',
            'effective_date' => $effectiveDate !== '' ? $effectiveDate : null,
            'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
            'updatetime' => $now,
        ];
        $existing = Db::name('cpq_sales_org_member')
            ->where('org_id', $orgId)
            ->where('admin_id', $adminId)
            ->find();
        if ($existing
            && (string)$existing['status'] === 'normal'
            && (string)$existing['role'] === $role) {
            $this->success('该管理员已是此组织成员', null, ['id' => (int)$existing['id'], 'duplicated' => true]);
        }
        if ($existing) {
            // 唯一键为 (org_id, admin_id)：角色变化或已停用记录走更新复活。
            Db::name('cpq_sales_org_member')->where('id', (int)$existing['id'])->update($data);
            $id = (int)$existing['id'];
        } else {
            $data['org_id'] = $orgId;
            $data['admin_id'] = $adminId;
            $data['createtime'] = $now;
            $id = (int)Db::name('cpq_sales_org_member')->insertGetId($data);
        }
        $this->auditService->record('grant_org', 'cpq_sales_org_member', $id, [
            'admin_id' => $adminId,
            'org_id' => $orgId,
            'org_name' => (string)$org['name'],
            'role' => $role,
        ]);
        $this->success('已授予组织成员资格', null, ['id' => $id]);
    }

    /**
     * 撤销组织成员资格（置为 hidden，保留历史）。
     */
    public function revokeorg()
    {
        $this->assertWritePost();
        $id = (int)$this->request->post('id');
        $row = Db::name('cpq_sales_org_member')->where('id', $id)->find();
        if (!$row) {
            $this->error('成员记录不存在');
        }
        if ((string)$row['status'] !== 'hidden') {
            Db::name('cpq_sales_org_member')->where('id', $id)->update([
                'status' => 'hidden',
                'updatetime' => time(),
            ]);
        }
        $this->auditService->record('revoke_org', 'cpq_sales_org_member', $id, [
            'admin_id' => (int)$row['admin_id'],
            'org_id' => (int)$row['org_id'],
            'role' => (string)$row['role'],
        ]);
        $this->success('已撤销组织成员资格');
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * 存在 CPQ 角色或任一授权记录的管理员 ID 集合。
     *
     * @return array
     */
    private function cpqRelatedAdminIds()
    {
        $roleIds = Db::name('auth_group_access')->alias('access')
            ->join('__AUTH_GROUP__ auth_group', 'auth_group.id = access.group_id')
            ->where('auth_group.name', 'in', SensitiveFieldService::CPQ_ROLES)
            ->column('access.uid');
        $lineIds = Db::name('cpq_admin_product_line')->column('admin_id');
        $orgIds = Db::name('cpq_sales_org_member')->column('admin_id');
        $ids = array_merge($roleIds ?: [], $lineIds ?: [], $orgIds ?: []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        return $ids;
    }

    /**
     * 为管理员行补充角色与授权摘要（批量查询，避免 N+1）。
     *
     * @param array $rows
     * @return array
     */
    private function decorateAdminRows(array $rows)
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(function ($row) {
            return (int)$row['id'];
        }, $rows);

        $roleRows = Db::name('auth_group_access')->alias('access')
            ->join('__AUTH_GROUP__ auth_group', 'auth_group.id = access.group_id')
            ->where('access.uid', 'in', $ids)
            ->where('auth_group.status', 'normal')
            ->field('access.uid,auth_group.name,auth_group.rules')
            ->select();
        $rolesMap = [];
        foreach ($roleRows ?: [] as $roleRow) {
            $uid = (int)$roleRow['uid'];
            if ((string)($roleRow['rules'] ?? '') === '*') {
                $rolesMap[$uid]['system_admin'] = true;
                continue;
            }
            $name = trim((string)$roleRow['name']);
            if (in_array($name, SensitiveFieldService::CPQ_ROLES, true)) {
                $rolesMap[$uid][$name] = true;
            }
        }

        $lineRows = Db::name('cpq_admin_product_line')
            ->where('admin_id', 'in', $ids)
            ->field('admin_id,product_line')
            ->order('id', 'asc')
            ->select();
        $linesMap = [];
        foreach ($lineRows ?: [] as $lineRow) {
            $linesMap[(int)$lineRow['admin_id']][] = (string)$lineRow['product_line'];
        }

        $orgRows = Db::name('cpq_sales_org_member')->alias('m')
            ->join('__CPQ_SALES_ORG__ o', 'o.id = m.org_id', 'LEFT')
            ->where('m.admin_id', 'in', $ids)
            ->where('m.status', 'normal')
            ->field('m.admin_id,m.org_id,m.role,o.name AS org_name')
            ->order('m.id', 'asc')
            ->select();
        $orgsMap = [];
        foreach ($orgRows ?: [] as $orgRow) {
            $label = (string)($orgRow['org_name'] ?? '');
            if ($label === '') {
                $label = '#' . (int)$orgRow['org_id'];
            }
            $orgsMap[(int)$orgRow['admin_id']][] = $label . '(' . (string)$orgRow['role'] . ')';
        }

        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            $roles = isset($rolesMap[$id]) ? array_keys($rolesMap[$id]) : [];
            sort($roles);
            $row['roles'] = implode(',', $roles);
            $lines = isset($linesMap[$id]) ? $linesMap[$id] : [];
            $row['product_line_summary'] = $lines ? implode('、', $lines) : '';
            $row['org_summary'] = isset($orgsMap[$id]) ? implode('、', $orgsMap[$id]) : '';
        }
        unset($row);
        return $rows;
    }

    /**
     * @param int $adminId
     * @return bool
     */
    private function adminExists($adminId)
    {
        return $adminId > 0 && Db::name('admin')->where('id', $adminId)->count() > 0;
    }

    /**
     * @return array
     */
    private function cpqRoles()
    {
        return SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
    }

    /**
     * @return bool
     */
    private function canWrite()
    {
        return (bool)array_intersect(self::WRITE_ROLES, $this->cpqRoles());
    }

    private function assertRead()
    {
        if (!array_intersect(self::READ_ROLES, $this->cpqRoles())) {
            $this->error('无权访问');
        }
    }

    private function assertWritePost()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        if (!$this->canWrite()) {
            $this->error('无权访问');
        }
    }
}
