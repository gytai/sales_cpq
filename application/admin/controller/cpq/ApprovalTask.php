<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\ApprovalService;
use app\common\service\cpq\ProductLineScopeService;
use app\common\service\cpq\QuoteDocumentService;
use think\Db;

/**
 * 审批中心：我的待审批（P70）与报价审批详情（P71，GYTAI-75）
 *
 *  - index   我的待审批列表（SLA/超时红色提示/代理标记）
 *  - detail  审批详情：配置/价格/条款/风险/版本差异/轨迹，操作仅
 *            批准/驳回/退回/加签/转交（审批人无权修改报价，服务端逐项重复校验）
 *  - action  审批动作（幂等键 + 版本校验 + 权限校验）
 *  - urge    催办
 *  - candidates 转交/加签目标候选人（同节点可用审批人）
 *
 * @icon fa fa-check-square-o
 */
class ApprovalTask extends Backend
{
    /** @var ApprovalService */
    private $service;

    public function _initialize()
    {
        parent::_initialize();
        $this->service = new ApprovalService();
    }

    /**
     * P70 我的待审批：列表数据 + 页面。
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $category = (string)$this->request->request('category', 'pending');
            if (!in_array($category, ['pending', 'processed', 'cc'], true)) {
                $category = 'pending';
            }
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $filters = [
                'node' => (string)$this->request->request('node', ''),
                'product_line' => (string)$this->request->request('product_line', ''),
                'keyword' => (string)$this->request->request('keyword', ''),
            ];
            $result = $this->service->myTaskList((int)$this->auth->id, $category, $filters, ($page - 1) * $limit, $limit);
            return json(['total' => $result['total'], 'rows' => $result['rows']]);
        }
        $nodeList = [
            'sales_confirm' => '销售确认',
            'line_approval' => '产线价格审批',
            'company_approval' => '公司价格审批',
        ];
        $productLineList = ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $this->view->assign('nodeList', $nodeList);
        $this->view->assign('productLineList', $productLineList);
        $this->assignconfig('nodeList', $nodeList);
        $this->assignconfig('productLineList', $productLineList);
        return $this->view->fetch();
    }

    /**
     * P71 报价审批详情（只读 + 受限审批操作）：视图内嵌脱敏后的详情 JSON。
     */
    public function detail($ids = null)
    {
        $taskId = (int)$ids;
        try {
            $detail = $this->service->taskDetail($taskId, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_APPROVAL_INVALID']);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_APPROVAL_FORBIDDEN']);
        } catch (\Throwable $exception) {
            $this->error('审批服务异常', null, ['business_code' => 'CPQ_INTERNAL_ERROR']);
        }
        $detailJson = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $this->view->assign('taskId', $taskId);
        $this->view->assign('detailJson', $detailJson);
        $this->assignconfig('taskId', $taskId);
        return $this->view->fetch();
    }

    /**
     * 审批动作：approve/reject/return/transfer/add_sign。
     */
    public function action()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $payload = $this->requestPayload();
        $taskId = (int)($payload['task_id'] ?? 0);
        $action = (string)($payload['action'] ?? '');
        $this->respond(function () use ($taskId, $action, $payload) {
            if (!in_array($action, ['approve', 'reject', 'return', 'transfer', 'add_sign', 'confirm'], true)) {
                throw new \InvalidArgumentException('不支持的审批动作：' . $action);
            }
            $options = [
                'comment' => (string)($payload['comment'] ?? ''),
                'reason_category' => (string)($payload['reason_category'] ?? ''),
                'idempotency_key' => (string)($payload['idempotency_key'] ?? ''),
                'next_assignee_id' => (int)($payload['next_assignee_id'] ?? 0),
                'ip' => $this->request->ip(),
            ];
            $result = $this->service->act($taskId, (int)$this->auth->id, $action, $options);
            return $result;
        });
    }

    /**
     * 催办。
     */
    public function urge()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $taskId = (int)$this->request->post('task_id');
        $this->respond(function () use ($taskId) {
            return $this->service->urge($taskId, (int)$this->auth->id);
        });
    }

    /**
     * 转交/加签目标候选人：同产品线可用审批人（排除报价负责人与提交人）。
     */
    public function candidates()
    {
        $taskId = (int)$this->request->request('task_id');
        $this->respond(function () use ($taskId) {
            $task = Db::name('cpq_approval_task')->where('id', $taskId)->find();
            if (!$task) {
                throw new \InvalidArgumentException('审批任务不存在');
            }
            $quote = Db::name('cpq_quote')->where('id', (int)$task['quote_id'])->find();
            if (!$quote) {
                throw new \InvalidArgumentException('报价不存在');
            }
            ProductLineScopeService::forAdmin((int)$this->auth->id)->assertLineAllowed((string)$quote['product_line'], '无该产品线的数据权限');
            $instance = Db::name('cpq_approval_instance')->where('id', (int)$task['instance_id'])->find();
            $exclude = [(int)$quote['owner_id'], (int)($instance ? $instance['initiator_id'] : 0)];
            $admins = Db::name('admin')->where('status', 'normal')->field('id,nickname,username')->select();
            $candidates = [];
            foreach ($admins as $admin) {
                if (in_array((int)$admin['id'], $exclude, true)) {
                    continue; // 职责分离：负责人/提交人不可作为目标
                }
                $scope = ProductLineScopeService::forAdmin((int)$admin['id']);
                if (!$scope->isLineAllowed((string)$quote['product_line'])) {
                    continue;
                }
                $candidates[] = ['id' => (int)$admin['id'], 'nickname' => (string)$admin['nickname'], 'username' => (string)$admin['username']];
            }
            return ['candidates' => $candidates];
        });
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    private function requestPayload()
    {
        $content = file_get_contents('php://input');
        if ($content !== false && trim($content) !== '') {
            $payload = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
                return $payload;
            }
        }
        return $this->request->post();
    }

    private function respond(callable $callback)
    {
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = $callback();
        } catch (\app\common\library\cpq\PricingException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => $exception->getBusinessCode(),
                'trace_id' => $traceId,
                'payload' => $exception->getDetails(),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => 'CPQ_APPROVAL_INVALID',
                'trace_id' => $traceId,
            ]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => 'CPQ_APPROVAL_FORBIDDEN',
                'trace_id' => $traceId,
            ]);
        } catch (\Throwable $exception) {
            $this->error('审批服务异常：' . $exception->getMessage(), null, [
                'business_code' => 'CPQ_INTERNAL_ERROR',
                'trace_id' => $traceId,
            ]);
        }
        $this->success('OK', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }
}
