<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\SensitiveFieldService;
use think\Db;

/**
 * CPQ 审计日志（P106，GYTAI-78）
 *
 * cpq_audit_log 只读查询：仅 auditor / system_admin 可访问。
 * 本控制器不提供任何写接口（审计表只增不删，业务用户无删除路径）。
 * 全部筛选条件走参数化查询。
 *
 * @icon fa fa-history
 */
class AuditLog extends Backend
{
    /** 可访问角色 */
    const ALLOWED_ROLES = ['auditor', 'system_admin'];

    /**
     * 审计日志分页列表。
     */
    public function index()
    {
        $this->assertAllowed();
        if (!$this->request->isAjax()) {
            return $this->view->fetch();
        }

        $page = max(1, (int)$this->request->request('page', 1));
        $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
        $action = trim((string)$this->request->request('action', ''));
        $objectType = trim((string)$this->request->request('object_type', ''));
        $objectId = (int)$this->request->request('object_id', 0);
        $userId = (int)$this->request->request('user_id', 0);
        $createdFrom = $this->parseDate((string)$this->request->request('created_from', ''));
        $createdTo = $this->parseDate((string)$this->request->request('created_to', ''));

        $buildQuery = function () use ($action, $objectType, $objectId, $userId, $createdFrom, $createdTo) {
            $query = Db::name('cpq_audit_log');
            if ($action !== '') {
                $query->where('action', $action);
            }
            if ($objectType !== '') {
                $query->where('object_type', $objectType);
            }
            if ($objectId > 0) {
                $query->where('object_id', $objectId);
            }
            if ($userId > 0) {
                $query->where('user_id', $userId);
            }
            if ($createdFrom !== null) {
                $query->where('createtime', '>=', $createdFrom);
            }
            if ($createdTo !== null) {
                $query->where('createtime', '<', $createdTo + 86400);
            }
            return $query;
        };
        $total = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->order('id', 'desc')
            ->limit(($page - 1) * $limit, $limit)
            ->select();

        return json(['total' => $total, 'rows' => $rows ?: []]);
    }

    /**
     * 单条审计日志详情（只读）。
     */
    public function detail()
    {
        $this->assertAllowed();
        $id = (int)$this->request->request('ids', 0);
        if ($id <= 0) {
            $id = (int)$this->request->request('id', 0);
        }
        $row = Db::name('cpq_audit_log')->where('id', $id)->find();
        if (!$row) {
            $this->error('审计日志不存在');
        }
        $this->success('', null, $row);
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    private function assertAllowed()
    {
        $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
        if (!array_intersect(self::ALLOWED_ROLES, $roles)) {
            $this->error('无权访问');
        }
    }

    /**
     * 解析 YYYY-MM-DD 为时间戳；非法返回 null。
     *
     * @param string $value
     * @return int|null
     */
    private function parseDate($value)
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $timestamp = strtotime($value . ' 00:00:00');
        return $timestamp === false ? null : $timestamp;
    }
}
