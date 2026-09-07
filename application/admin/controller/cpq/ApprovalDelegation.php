<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\ApprovalDelegationService;
use app\common\service\cpq\ProductLineScopeService;
use think\Db;

/**
 * 审批中心：委托与代理（P75，GYTAI-75）
 *
 *  - index    委托列表（委托人/代理人视角 + 具备审批管理权限的角色全量可见）
 *  - create   新增委托申请（待审批）
 *  - approve  审批委托（生效/拒绝，具备委托审批权限的角色）
 *  - cancel   撤销委托（委托人本人或委托审批权限角色）
 *  - actions  代理操作记录（我作为代理人处理过的动作）
 *
 * 代理关系不突破代理人原有数据权限；创建人与审批人分离规则仍然有效。
 *
 * @icon fa fa-user-plus
 */
class ApprovalDelegation extends Backend
{
    /** @var ApprovalDelegationService */
    private $service;

    public function _initialize()
    {
        parent::_initialize();
        $this->service = new ApprovalDelegationService();
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $result = $this->service->myList((int)$this->auth->id, [
                'status' => (string)$this->request->request('status', ''),
            ], ($page - 1) * $limit, $limit);
            return json(['total' => $result['total'], 'rows' => $result['rows']]);
        }
        $statusList = (new \app\admin\model\cpq\ApprovalDelegation())->getStatusList();
        $this->view->assign('statusList', $statusList);
        $this->assignconfig('statusList', $statusList);
        $productLineList = ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $this->view->assign('productLineList', $productLineList);
        $this->assignconfig('productLineList', $productLineList);
        // 委托审批权限（页面按钮显隐；服务端重复校验）
        $roles = \app\common\service\cpq\SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
        $this->view->assign('canApproveDelegation', (bool)array_intersect($roles, ApprovalDelegationService::APPROVE_ROLES));
        return $this->view->fetch();
    }

    /**
     * 代理人候选：正常状态的管理员（排除自己），供委托创建下拉选择。
     * 仅暴露 id/昵称/账号；委托创建时的数据权限与时间窗由服务端 create() 强校验。
     */
    public function candidates()
    {
        $keyword = trim((string)$this->request->request('searchWord', ''));
        if ($keyword === '') {
            // selectpage 插件以 q_word[] 数组形式提交关键字
            $qWord = $this->request->request('q_word/a', []);
            $keyword = trim((string)(is_array($qWord) && $qWord ? reset($qWord) : ''));
        }
        $query = Db::name('admin')
            ->where('status', 'normal')
            ->where('id', '<>', (int)$this->auth->id);
        if ($keyword !== '') {
            $query->where('nickname|username', 'like', '%' . $keyword . '%');
        }
        $rows = $query->field('id,nickname,username')->order('id asc')->limit(50)->select();
        return json(['total' => count($rows), 'rows' => $rows]);
    }

    /**
     * 新增委托申请。
     */
    public function create()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $startsAt = $this->request->post('starts_at');
        $endsAt = $this->request->post('ends_at');
        $data = [
            'delegate_id' => (int)$this->request->post('delegate_id'),
            'product_line' => trim((string)$this->request->post('product_line')),
            'reason' => (string)$this->request->post('reason'),
            'starts_at' => is_string($startsAt) && $startsAt !== '' ? strtotime($startsAt) : (int)$startsAt,
            'ends_at' => is_string($endsAt) && $endsAt !== '' ? strtotime($endsAt) : (int)$endsAt,
        ];
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = $this->service->create($data, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DELEGATION_INVALID', 'trace_id' => $traceId]);
        } catch (\Throwable $exception) {
            $this->error('委托创建异常', null, ['business_code' => 'CPQ_INTERNAL_ERROR', 'trace_id' => $traceId]);
        }
        $this->success('委托申请已提交，待审批后生效', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }

    /**
     * 审批委托：approve=1 生效 / 0 拒绝。
     */
    public function approve()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('ids');
        $approve = (int)$this->request->post('approve', 1) === 1;
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = $this->service->approve($id, (int)$this->auth->id, $approve);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DELEGATION_INVALID', 'trace_id' => $traceId]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DELEGATION_FORBIDDEN', 'trace_id' => $traceId]);
        }
        $this->success($approve ? '委托已生效' : '委托已拒绝', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }

    /**
     * 撤销委托。
     */
    public function cancel()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('ids');
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = $this->service->cancel($id, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DELEGATION_INVALID', 'trace_id' => $traceId]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DELEGATION_FORBIDDEN', 'trace_id' => $traceId]);
        }
        $this->success('委托已撤销', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }

    /**
     * 代理操作记录：我作为代理人处理过的审批动作。
     */
    public function actions()
    {
        if ($this->request->isAjax()) {
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $result = $this->service->delegatedActions((int)$this->auth->id, ($page - 1) * $limit, $limit);
            $service = new \app\common\service\cpq\ApprovalService();
            foreach ($result['rows'] as &$row) {
                $row['action_text'] = $service->actionText($row['action']);
                $row['node_text'] = $row['node'] ? $service->nodeText($row['node']) : '';
            }
            unset($row);
            return json(['total' => $result['total'], 'rows' => $result['rows']]);
        }
        return $this->view->fetch();
    }
}
