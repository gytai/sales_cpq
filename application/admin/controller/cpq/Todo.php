<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\ApprovalService;
use think\Db;

/**
 * 工作台：我的待办（P02，GYTAI-75）
 *
 * 分类：待处理 / 已处理 / 我发起的 / 抄送我的（被转交/加签给我的任务）。
 * 列表展示业务类型、单号、客户、提交人、到达时间、剩余 SLA（超时红色提示）；
 * 支持产品线/审批类型筛选、批量转交与催办；批量批准默认禁用。
 *
 * @icon fa fa-list-ul
 */
class Todo extends Backend
{
    /** @var ApprovalService */
    private $approvalService;

    public function _initialize()
    {
        parent::_initialize();
        $this->approvalService = new ApprovalService();
    }

    /**
     * 我的待办：category = pending/processed/initiated/cc。
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $category = (string)$this->request->request('category', 'pending');
            if (!in_array($category, ['pending', 'processed', 'initiated', 'cc'], true)) {
                $category = 'pending';
            }
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $offset = ($page - 1) * $limit;

            if ($category === 'initiated') {
                $result = $this->approvalService->initiatedList((int)$this->auth->id, [
                    'keyword' => (string)$this->request->request('keyword', ''),
                ], $offset, $limit);
            } else {
                $result = $this->approvalService->myTaskList((int)$this->auth->id, $category, [
                    'node' => (string)$this->request->request('node', ''),
                    'product_line' => (string)$this->request->request('product_line', ''),
                    'keyword' => (string)$this->request->request('keyword', ''),
                ], $offset, $limit);
            }
            return json(['total' => $result['total'], 'rows' => $result['rows']]);
        }

        $nodeList = [
            'sales_confirm' => '销售确认',
            'line_approval' => '产线价格审批',
            'company_approval' => '公司价格审批',
        ];
        $productLineList = \app\common\service\cpq\ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $this->view->assign('nodeList', $nodeList);
        $this->view->assign('productLineList', $productLineList);
        $this->assignconfig('nodeList', $nodeList);
        $this->assignconfig('productLineList', $productLineList);
        return $this->view->fetch();
    }

    /**
     * 待办批量转交（逐条复用审批动作，服务端逐项重复校验）。
     */
    public function batchtransfer()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $taskIds = (array)($this->request->post('task_ids/a', []));
        $nextAssigneeId = (int)$this->request->post('next_assignee_id');
        $comment = trim((string)$this->request->post('comment'));
        if (!$taskIds) {
            $this->error('请选择要转交的任务');
        }
        $results = [];
        $failed = [];
        foreach ($taskIds as $taskId) {
            try {
                $results[] = $this->approvalService->act((int)$taskId, (int)$this->auth->id, 'transfer', [
                    'comment' => $comment,
                    'next_assignee_id' => $nextAssigneeId,
                ]);
            } catch (\Throwable $e) {
                $failed[] = '#任务' . (int)$taskId . '：' . $e->getMessage();
            }
        }
        $this->success('转交完成 ' . count($results) . ' 条' . ($failed ? '，失败 ' . count($failed) . ' 条' : ''), null, [
            'business_code' => 'OK',
            'payload' => ['succeeded' => $results, 'failed' => $failed],
        ]);
    }

    /**
     * 待办催办（P70/P72 共用）。
     */
    public function urge()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $taskId = (int)$this->request->post('task_id');
        try {
            $this->approvalService->urge($taskId, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_APPROVAL_INVALID']);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_APPROVAL_FORBIDDEN']);
        }
        // success 必须在 try 外：TP5 success 抛出的 HttpResponseException
        // 是 RuntimeException 子类，放在 try 内会被上方 catch 吞掉
        $this->success('已发送催办提醒', null, ['business_code' => 'OK']);
    }
}
