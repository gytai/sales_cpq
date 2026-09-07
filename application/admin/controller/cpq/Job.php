<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\AsyncJobService;
use app\common\service\cpq\SensitiveFieldService;
use InvalidArgumentException;
use think\Db;

/**
 * CPQ 异步任务监控（P105，GYTAI-78）
 *
 * 统一展示 PDF/Excel/同步/邮件等异步任务状态。普通用户仅能看到
 * 自己发起（requested_by=自己）的任务；system_admin / auditor 可见全部。
 * 失败任务支持受控重试（属主或特权角色，服务端由 AsyncJobService 强校验）。
 * 每行数据经 AsyncJobService::publicJob 净化，不泄露 payload/下载令牌。
 *
 * @icon fa fa-tasks
 */
class Job extends Backend
{
    /** 可见全部任务的角色 */
    const PRIVILEGED_ROLES = ['system_admin', 'auditor'];

    /** @var AsyncJobService */
    private $jobService;

    public function _initialize()
    {
        parent::_initialize();
        $this->jobService = new AsyncJobService();
    }

    /**
     * 任务分页列表（按角色过滤属主）。
     */
    public function index()
    {
        $privileged = $this->isPrivileged();
        if (!$this->request->isAjax()) {
            $this->assignconfig('canSeeAll', $privileged);
            $this->assignconfig('adminId', (int)$this->auth->id);
            $this->view->assign('typeList', AsyncJobService::TYPES);
            $this->view->assign('statusList', ['pending', 'processing', 'succeeded', 'failed', 'cancelled']);
            return $this->view->fetch();
        }

        $page = max(1, (int)$this->request->request('page', 1));
        $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
        $type = trim((string)$this->request->request('type', ''));
        $status = trim((string)$this->request->request('status', ''));
        $createdFrom = $this->parseDate((string)$this->request->request('created_from', ''));
        $createdTo = $this->parseDate((string)$this->request->request('created_to', ''));

        $buildQuery = function () use ($privileged, $type, $status, $createdFrom, $createdTo) {
            $query = Db::name('cpq_job');
            if (!$privileged) {
                $query->where('requested_by', (int)$this->auth->id);
            }
            if (in_array($type, AsyncJobService::TYPES, true)) {
                $query->where('type', $type);
            }
            if (in_array($status, ['pending', 'processing', 'succeeded', 'failed', 'cancelled'], true)) {
                $query->where('status', $status);
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

        $jobs = [];
        foreach ($rows ?: [] as $row) {
            $jobs[] = $this->jobService->publicJob($row);
        }
        return json(['total' => $total, 'rows' => $jobs]);
    }

    /**
     * 任务详情（属主/特权校验由 AsyncJobService::status 内部完成）。
     */
    public function detail()
    {
        $jobKey = trim((string)$this->request->request('job_key'));
        try {
            $job = $this->jobService->status($jobKey, (int)$this->auth->id);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $this->success('', null, ['job' => $job]);
    }

    /**
     * 失败任务受控重试。
     */
    public function retry()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $jobKey = trim((string)$this->request->post('job_key'));
        try {
            $job = $this->jobService->retry($jobKey, (int)$this->auth->id);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $this->success('已重新排队', null, ['job' => $job]);
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * @return bool
     */
    private function isPrivileged()
    {
        return (bool)array_intersect(self::PRIVILEGED_ROLES, SensitiveFieldService::rolesOfAdmin((int)$this->auth->id));
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
