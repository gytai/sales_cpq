<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\ApprovalService;

/**
 * 审批中心：审批记录（P73，GYTAI-75）
 *
 * 全量审批动作流水：业务类型、单号、节点、动作、处理人、代理人、意见、
 * 原因分类、开始/完成时间；按人员、单号、产品线、时间检索。
 *
 * @icon fa fa-history
 */
class ApprovalRecord extends Backend
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
            $createTime = $this->request->request('createtime', '');
            $filters = [
                'actor_id' => (int)$this->request->request('actor_id', 0),
                'action' => (string)$this->request->request('action', ''),
                'product_line' => (string)$this->request->request('product_line', ''),
                'keyword' => (string)$this->request->request('keyword', ''),
                'createtime' => [],
            ];
            if (is_string($createTime) && strpos($createTime, ' - ') !== false) {
                list($from, $to) = explode(' - ', $createTime);
                $filters['createtime'] = [strtotime($from), strtotime($to) + 86399];
            } elseif (is_array($createTime) && count($createTime) === 2) {
                $filters['createtime'] = [(int)$createTime[0], (int)$createTime[1]];
            }
            $result = $this->approvalService->actionRecords($filters, ($page - 1) * $limit, $limit);
            return json(['total' => $result['total'], 'rows' => $result['rows']]);
        }

        $this->view->assign('actionList', [
            'confirm' => '销售确认', 'approve' => '批准', 'reject' => '驳回', 'return' => '退回修改',
            'transfer' => '转交', 'add_sign' => '加签', 'withdraw' => '撤回', 'urge' => '催办', 'cancel' => '取消',
        ]);
        $this->assignconfig('actionList', [
            'confirm' => '销售确认', 'approve' => '批准', 'reject' => '驳回', 'return' => '退回修改',
            'transfer' => '转交', 'add_sign' => '加签', 'withdraw' => '撤回', 'urge' => '催办', 'cancel' => '取消',
        ]);
        $productLineList = \app\common\service\cpq\ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $this->view->assign('productLineList', $productLineList);
        $this->assignconfig('productLineList', $productLineList);
        return $this->view->fetch();
    }
}
