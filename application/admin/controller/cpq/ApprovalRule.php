<?php

namespace app\admin\controller\cpq;

use app\admin\model\cpq\ApprovalRule as ApprovalRuleModel;
use app\common\controller\Backend;
use app\common\service\cpq\ApprovalService;
use app\common\service\cpq\AuditLogService;
use think\Db;
use think\exception\PDOException;

/**
 * 审批中心：审批规则（P74，GYTAI-75）
 *
 * 维护固定路径所需审批角色、候选人、适用产品线与 SLA；支持规则模拟
 * （给定审批等级+产品线解析路径与候选人）；启停状态与版本递增。
 * 不提供任意节点编排或可视化流程设计器（固定三条路径，方案 §4.6）。
 *
 * @icon fa fa-random
 */
class ApprovalRule extends Backend
{
    protected $model = null;
    protected $modelValidate = true;
    protected $searchFields = 'code,name';
    protected $multiFields = 'status';

    /** @var ApprovalService */
    private $approvalService;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ApprovalRuleModel();
        $this->approvalService = new ApprovalService();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('nodeList', $this->model->getNodeList());
        $this->view->assign('roleList', $this->model->getRoleList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('nodeList', $this->model->getNodeList());
        $this->assignconfig('productLineList', \app\common\service\cpq\ProductLineScopeService::productLineOptionsFor((int)$this->auth->id));
    }

    /**
     * 规则模拟：给定审批等级与产品线，解析固定路径各节点候选人与 SLA。
     */
    public function simulate()
    {
        $level = (string)$this->request->request('level', 'line');
        $productLine = (string)$this->request->request('product_line', '');
        $traceId = bin2hex(random_bytes(12));
        try {
            $paths = ApprovalService::FIXED_PATHS;
            if (!isset($paths[$level])) {
                throw new \InvalidArgumentException('未知审批等级：' . $level);
            }
            $quote = ['id' => 0, 'product_line' => $productLine, 'owner_id' => 0];
            $nodes = [];
            foreach ($paths[$level] as $node) {
                $rule = $this->approvalService->matchRule($node, $productLine);
                $entry = [
                    'node' => $node,
                    'node_text' => $this->approvalService->nodeText($node),
                    'rule_code' => $rule ? (string)$rule['code'] : '',
                    'sla_hours' => $rule ? (int)$rule['sla_hours'] : ApprovalService::SALES_CONFIRM_SLA_HOURS,
                ];
                if ($node === ApprovalService::NODE_SALES_CONFIRM) {
                    $entry['candidates'] = ['报价负责人（提交人确认）'];
                } elseif ($rule) {
                    try {
                        $candidates = $this->approvalService->resolveCandidates($node, $quote, 0);
                        $entry['candidates'] = array_values($candidates);
                    } catch (\InvalidArgumentException $e) {
                        $entry['candidates'] = [];
                        $entry['error'] = $e->getMessage();
                    }
                } else {
                    $entry['candidates'] = [];
                    $entry['error'] = '未配置该节点审批规则';
                }
                $nodes[] = $entry;
            }
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_APPROVAL_INVALID', 'trace_id' => $traceId]);
        } catch (\Throwable $exception) {
            $this->error('规则模拟异常', null, ['business_code' => 'CPQ_INTERNAL_ERROR', 'trace_id' => $traceId]);
        }
        $this->success('OK', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => ['level' => $level, 'path' => $paths[$level], 'nodes' => $nodes],
        ]);
    }

    /**
     * 保存后写审计并递增版本（停用状态才可编辑，启停经 multi/自定义动作）。
     */
    public function edit($ids = null)
    {
        $row = $this->model->get((int)$ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                if ($row['status'] === 'enabled') {
                    $this->error('规则启用中不可编辑，请先停用后再修改');
                }
                $params = $this->normalize($params);
                $params['version'] = (int)$row['version'] + 1;
                try {
                    $result = $this->model->allowField(true)->save($params, ['id' => (int)$ids]);
                    if ($result !== false) {
                        (new AuditLogService())->record('update', 'cpq_approval_rule', (int)$ids, [
                            'code' => $params['code'] ?? $row['code'],
                            'version' => $params['version'],
                        ]);
                        $this->success();
                    } else {
                        $this->error($this->model->getError());
                    }
                } catch (PDOException $e) {
                    $this->error($e->getMessage());
                }
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                $params = $this->normalize($params);
                try {
                    $result = $this->model->allowField(true)->save($params);
                    if ($result !== false) {
                        (new AuditLogService())->record('create', 'cpq_approval_rule', (int)$this->model->id, [
                            'code' => $params['code'] ?? '',
                        ]);
                        $this->success();
                    } else {
                        $this->error($this->model->getError());
                    }
                } catch (PDOException $e) {
                    $this->error($e->getMessage());
                }
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        return $this->view->fetch();
    }

    /**
     * 启停切换（写审计）。
     */
    public function toggle()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('ids');
        $row = Db::name('cpq_approval_rule')->where('id', $id)->find();
        if (!$row) {
            $this->error('规则不存在');
        }
        $next = $row['status'] === 'enabled' ? 'disabled' : 'enabled';
        // 启用时校验候选人可解析（避免无人可批）
        if ($next === 'enabled') {
            $probe = ['id' => 0, 'product_line' => (string)$row['product_line'], 'owner_id' => 0];
            try {
                $this->approvalService->resolveCandidates($row['node'], $probe, 0);
            } catch (\InvalidArgumentException $e) {
                $this->error('无法启用：' . $e->getMessage());
            }
        }
        Db::name('cpq_approval_rule')->where('id', $id)->update(['status' => $next, 'updatetime' => time()]);
        (new AuditLogService())->record('update', 'cpq_approval_rule', $id, ['status' => $next]);
        $this->success($next === 'enabled' ? '已启用' : '已停用');
    }

    /**
     * 仅停用状态的规则可删除（启用中的规则删除会直接阻断审批路径）。
     */
    public function del($ids = '')
    {
        $ids = $ids ?: $this->request->request('ids');
        $rows = Db::name('cpq_approval_rule')->where('id', 'in', (array)$ids)->select();
        foreach ($rows as $row) {
            if ($row['status'] === 'enabled') {
                $this->error('启用中的规则不可删除，请先停用');
            }
        }
        parent::del($ids);
    }

    private function normalize(array $params)
    {
        $params['code'] = trim((string)($params['code'] ?? ''));
        $params['name'] = trim((string)($params['name'] ?? ''));
        $params['node'] = (string)($params['node'] ?? 'line_approval');
        if (!in_array($params['node'], ['line_approval', 'company_approval'], true)) {
            throw new \InvalidArgumentException('审批规则节点仅支持产线/公司价格审批');
        }
        $candidates = trim((string)($params['candidate_admin_ids'] ?? ''));
        if ($candidates !== '') {
            $decoded = json_decode($candidates, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('显式候选人必须是 JSON 数组');
            }
            $params['candidate_admin_ids'] = json_encode(array_values(array_map('intval', $decoded)));
        }
        $params['sla_hours'] = max(0, (int)($params['sla_hours'] ?? 24));
        $params['status'] = in_array(($params['status'] ?? ''), ['enabled', 'disabled'], true) ? $params['status'] : 'enabled';
        return $params;
    }
}
