<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\ApprovalService;
use app\common\service\cpq\QuoteRevisionService;
use think\Db;

/**
 * 审批中心：我发起的（P72，GYTAI-75）
 *
 *  - index  我发起的审批：当前节点、处理人、停留时间、催办、允许条件下撤回
 *  - flow   完整流程视图（节点/任务/动作时间线）
 *
 * @icon fa fa-paper-plane-o
 */
class ApprovalInstance extends Backend
{
    /** @var ApprovalService */
    private $approvalService;

    public function _initialize()
    {
        parent::_initialize();
        $this->approvalService = new ApprovalService();
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $result = $this->approvalService->initiatedList((int)$this->auth->id, [
                'status' => (string)$this->request->request('instance_status', ''),
                'keyword' => (string)$this->request->request('keyword', ''),
            ], ($page - 1) * $limit, $limit);
            return json(['total' => $result['total'], 'rows' => $result['rows']]);
        }
        $this->view->assign('statusList', (new \app\admin\model\cpq\ApprovalInstance())->getStatusList());
        $this->assignconfig('statusList', (new \app\admin\model\cpq\ApprovalInstance())->getStatusList());
        $productLineList = \app\common\service\cpq\ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $this->view->assign('productLineList', $productLineList);
        $this->assignconfig('productLineList', $productLineList);
        return $this->view->fetch();
    }

    /**
     * 完整流程视图：实例 + 节点任务 + 动作时间线（发起人/超管可见）。
     */
    public function flow($ids = null)
    {
        $instanceId = (int)$ids;
        try {
            $instance = Db::name('cpq_approval_instance')->where('id', $instanceId)->find();
            if (!$instance) {
                throw new \InvalidArgumentException('审批实例不存在');
            }
            $isInitiator = (int)$instance['initiator_id'] === (int)$this->auth->id;
            $scope = \app\common\service\cpq\ProductLineScopeService::forAdmin((int)$this->auth->id);
            if (!$isInitiator && !$scope->isUnrestricted()) {
                throw new \RuntimeException('仅发起人可查看完整流程');
            }
            $quote = Db::name('cpq_quote')->where('id', (int)$instance['quote_id'])->find();
            $nodes = [];
            foreach (ApprovalService::FIXED_PATHS[(string)$instance['approval_level']] ?? [] as $node) {
                $tasks = Db::name('cpq_approval_task')
                    ->alias('t')
                    ->join('__ADMIN__ a', 'a.id = t.assignee_id', 'LEFT')
                    ->where('t.instance_id', $instanceId)
                    ->where('t.node', $node)
                    ->field('t.*, a.nickname AS assignee_name')
                    ->order('t.id asc')
                    ->select();
                foreach ($tasks as &$task) {
                    $task['status_text'] = (new \app\admin\model\cpq\ApprovalTask())->getStatusList()[$task['status']] ?? $task['status'];
                    $task['node_text'] = $this->approvalService->nodeText($node);
                }
                unset($task);
                $nodes[] = ['node' => $node, 'node_text' => $this->approvalService->nodeText($node), 'tasks' => $tasks];
            }
            $actions = Db::name('cpq_approval_action')->where('instance_id', $instanceId)->order('id asc')->select();
            foreach ($actions as &$action) {
                $action['action_text'] = $this->approvalService->actionText($action['action']);
                $action['node_text'] = $action['node'] ? $this->approvalService->nodeText($action['node']) : '';
            }
            unset($action);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_APPROVAL_INVALID']);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_APPROVAL_FORBIDDEN']);
        }

        $flow = [
            'instance' => $instance,
            'instance_status_text' => (new \app\admin\model\cpq\ApprovalInstance())->getStatusList()[$instance['status']] ?? $instance['status'],
            'quote' => [
                'id' => (int)$quote['id'],
                'code' => (string)$quote['code'],
                'name' => (string)$quote['name'],
                'status' => (string)$quote['status'],
            ],
            'nodes' => $nodes,
            'actions' => $actions,
        ];
        $flowJson = json_encode($flow, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->view->assign('flowJson', $flowJson);
        return $this->view->fetch();
    }

    /**
     * 发起人撤回（代理 QuoteRevisionService::withdraw，含审批任务取消）。
     */
    public function withdraw()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $quoteId = (int)$this->request->post('quote_id');
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = (new QuoteRevisionService())->withdraw($quoteId, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_QUOTE_INVALID', 'trace_id' => $traceId]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_QUOTE_FORBIDDEN', 'trace_id' => $traceId]);
        } catch (\Throwable $exception) {
            $this->error('撤回失败', null, ['business_code' => 'CPQ_INTERNAL_ERROR', 'trace_id' => $traceId]);
        }
        $this->success('已撤回', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }
}
