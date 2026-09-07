<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\NumberRuleService;
use app\common\service\cpq\SensitiveFieldService;
use InvalidArgumentException;
use think\Db;
use think\exception\PDOException;

/**
 * CPQ 编号规则管理（P102，GYTAI-78）
 *
 * 维护 cpq_number_rule 并提供编号格式试算与计数器查看。
 * 读取：任何登录用户；写动作：master_data_admin / system_admin。
 * 已产生编号计数器的规则修改 pattern 需要 force 二次确认。
 *
 * @icon fa fa-sort-numeric-asc
 */
class NumberRule extends Backend
{
    /** 可写角色（auth_group.name 精确匹配） */
    const WRITE_ROLES = ['master_data_admin', 'system_admin'];

    /** @var NumberRuleService */
    private $numberRuleService;

    /** @var AuditLogService */
    private $auditService;

    public function _initialize()
    {
        parent::_initialize();
        $this->numberRuleService = new NumberRuleService();
        $this->auditService = new AuditLogService();
    }

    /**
     * 规则列表 + 每条规则的计数器摘要（行数 / 最大 current_value）。
     */
    public function index()
    {
        if (!$this->request->isAjax()) {
            $this->assignconfig('canWrite', $this->canWrite());
            return $this->view->fetch();
        }
        $page = max(1, (int)$this->request->request('page', 1));
        $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
        $keyword = trim((string)$this->request->request('keyword', ''));
        $status = trim((string)$this->request->request('status', ''));

        $buildQuery = function () use ($keyword, $status) {
            $query = Db::name('cpq_number_rule');
            if ($keyword !== '') {
                $query->where('code|name', 'like', '%' . $keyword . '%');
            }
            if (in_array($status, ['enabled', 'disabled'], true)) {
                $query->where('status', $status);
            }
            return $query;
        };
        $total = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->order('id', 'asc')
            ->limit(($page - 1) * $limit, $limit)
            ->select();
        $rows = $rows ?: [];

        $ruleIds = array_map('intval', array_column($rows, 'id'));
        $summaryMap = [];
        if ($ruleIds) {
            $summaryRows = Db::name('cpq_number_counter')
                ->where('rule_id', 'in', $ruleIds)
                ->field('rule_id,COUNT(*) AS counters_count,MAX(current_value) AS max_current_value')
                ->group('rule_id')
                ->select();
            foreach ($summaryRows ?: [] as $summaryRow) {
                $summaryMap[(int)$summaryRow['rule_id']] = [
                    'counters_count' => (int)$summaryRow['counters_count'],
                    'max_current_value' => (int)$summaryRow['max_current_value'],
                ];
            }
        }
        foreach ($rows as &$row) {
            $summary = isset($summaryMap[(int)$row['id']]) ? $summaryMap[(int)$row['id']] : ['counters_count' => 0, 'max_current_value' => 0];
            $row['counters_count'] = $summary['counters_count'];
            $row['max_current_value'] = $summary['max_current_value'];
        }
        unset($row);

        return json(['total' => $total, 'rows' => $rows]);
    }

    /**
     * 新增编号规则。
     */
    public function add()
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->view->assign('row', [
                'code' => '', 'name' => '', 'pattern' => '{YYYY}{SEQ6}',
                'period_type' => 'year', 'initial_value' => 1, 'status' => 'enabled',
            ]);
            return $this->view->fetch();
        }
        $row = (array)$this->request->post('row/a', []);
        try {
            $data = $this->validateRuleInput($row);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $now = time();
        $data['createtime'] = $now;
        $data['updatetime'] = $now;
        try {
            $id = (int)Db::name('cpq_number_rule')->insertGetId($data);
        } catch (PDOException $exception) {
            $this->error('规则编码已存在', null, ['business_code' => 'CPQ_DUPLICATE']);
        }
        $this->auditService->record('create', 'cpq_number_rule', $id, $data, $data['code']);
        $this->success('保存成功', null, ['id' => $id]);
    }

    /**
     * 编辑编号规则；已有计数器的规则修改 pattern 需 force=1 二次确认。
     */
    public function edit($ids = null)
    {
        $this->assertWrite();
        $row = Db::name('cpq_number_rule')->where('id', (int)$ids)->find();
        if (!$row) {
            $this->error('编号规则不存在');
        }
        if (!$this->request->isPost()) {
            $counterCount = (int)Db::name('cpq_number_counter')->where('rule_id', (int)$row['id'])->count();
            $this->view->assign('row', $row);
            $this->view->assign('counterCount', $counterCount);
            return $this->view->fetch();
        }
        $input = (array)$this->request->post('row/a', []);
        $force = (int)($input['force'] ?? 0) === 1;
        unset($input['force']);
        try {
            $data = $this->validateRuleInput($input);
            $counterCount = (int)Db::name('cpq_number_counter')->where('rule_id', (int)$row['id'])->count();
            if (!$force && $counterCount > 0 && (string)$data['pattern'] !== (string)$row['pattern']) {
                $this->error('该规则已产生 ' . $counterCount . ' 条编号计数器，修改编号格式需二次确认', null, [
                    'business_code' => 'CPQ_PATTERN_CONFIRM',
                    'need_confirm' => true,
                ]);
            }
            $data['updatetime'] = time();
            Db::name('cpq_number_rule')->where('id', (int)$row['id'])->update($data);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        } catch (PDOException $exception) {
            $this->error('规则编码已存在', null, ['business_code' => 'CPQ_DUPLICATE']);
        }
        $this->auditService->record('update', 'cpq_number_rule', (int)$row['id'], $data, $data['code']);
        $this->success('保存成功');
    }

    /**
     * 编号格式试算：以序号 123 渲染，不产生序号、不写计数器。
     */
    public function preview()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $pattern = trim((string)$this->request->post('pattern'));
        $scope = trim((string)$this->request->post('scope', ''));
        try {
            $this->assertPattern($pattern);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $rendered = $this->numberRuleService->render($pattern, 123, $scope);
        $this->success('', null, ['preview' => $rendered]);
    }

    /**
     * 规则的计数器列表。
     */
    public function counters()
    {
        $ruleId = (int)$this->request->request('rule_id');
        $rule = Db::name('cpq_number_rule')->where('id', $ruleId)->find();
        if (!$rule) {
            $this->error('编号规则不存在');
        }
        $rows = Db::name('cpq_number_counter')
            ->where('rule_id', $ruleId)
            ->field('id,scope_key,period_key,current_value,updatetime')
            ->order('id', 'asc')
            ->select();
        $this->success('', null, ['rule' => $rule, 'rows' => $rows ?: []]);
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * 校验并规范化规则输入。
     *
     * @param array $row
     * @return array
     */
    private function validateRuleInput(array $row)
    {
        $code = trim((string)($row['code'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $code)) {
            throw new InvalidArgumentException('规则编码格式无效（1-64 位字母/数字/._-）');
        }
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('规则名称必填且不超过 120 字');
        }
        $pattern = trim((string)($row['pattern'] ?? ''));
        if ($pattern === '' || mb_strlen($pattern) > 120) {
            throw new InvalidArgumentException('编号格式必填且不超过 120 字');
        }
        $this->assertPattern($pattern);
        $periodType = (string)($row['period_type'] ?? '');
        if (!in_array($periodType, ['none', 'year', 'month', 'day'], true)) {
            throw new InvalidArgumentException('编号周期无效');
        }
        $status = (string)($row['status'] ?? 'enabled');
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new InvalidArgumentException('状态无效');
        }
        return [
            'code' => $code,
            'name' => $name,
            'pattern' => $pattern,
            'period_type' => $periodType,
            'initial_value' => max(1, (int)($row['initial_value'] ?? 1)),
            'status' => $status,
        ];
    }

    /**
     * pattern 必须包含 {SEQn} 占位符（n 为 1-10）。
     *
     * @param string $pattern
     */
    private function assertPattern($pattern)
    {
        if (!preg_match('/\{SEQ(\d{1,2})\}/', (string)$pattern, $matches)
            || (int)$matches[1] < 1
            || (int)$matches[1] > 10) {
            throw new InvalidArgumentException('编号格式必须包含 {SEQn} 占位符（n 为 1-10 的序号宽度）');
        }
    }

    /**
     * @return bool
     */
    private function canWrite()
    {
        return (bool)array_intersect(self::WRITE_ROLES, SensitiveFieldService::rolesOfAdmin((int)$this->auth->id));
    }

    private function assertWrite()
    {
        if (!$this->canWrite()) {
            $this->error('无权访问');
        }
    }
}
