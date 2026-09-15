<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\DictionaryService;
use app\common\service\cpq\SensitiveFieldService;
use InvalidArgumentException;
use think\Db;
use think\exception\PDOException;

/**
 * CPQ 字典与参数维护（P101，GYTAI-78）
 *
 * 维护 cpq_dictionary_value；系统参数以 dictionary_code=system_param 维护。
 * 读取：任何登录用户；写动作（add/edit/del/toggle）：master_data_admin /
 * system_admin。已被业务引用的字典值由 DictionaryService 强制只能停用。
 *
 * @icon fa fa-book
 */
class Dictionary extends Backend
{
    /** 可写角色（auth_group.name 精确匹配） */
    const WRITE_ROLES = ['master_data_admin', 'system_admin'];

    /** 启停状态字典（视图下拉与列表徽标共用同一来源） */
    const STATUS_LIST = ['enabled' => '启用', 'disabled' => '停用'];

    /** @var DictionaryService */
    private $dictionaryService;

    /** @var AuditLogService */
    private $auditService;

    public function _initialize()
    {
        parent::_initialize();
        $this->dictionaryService = new DictionaryService();
        $this->auditService = new AuditLogService();
        // 状态字典单一来源下发（表单下拉与列表徽标共用，前端不硬编码文案）
        $this->view->assign('statusList', self::STATUS_LIST);
        $this->assignconfig('statusList', self::STATUS_LIST);
    }

    /**
     * 字典值分页列表：筛选 dictionary_code（精确）与 keyword（label/value_code 模糊）。
     */
    public function index()
    {
        if (!$this->request->isAjax()) {
            $dictCodes = Db::name('cpq_dictionary_value')
                ->group('dictionary_code')
                ->order('dictionary_code', 'asc')
                ->column('dictionary_code');
            $dictCodes = array_values(array_filter(array_map('strval', $dictCodes ?: []), function ($code) {
                return $code !== '';
            }));
            $this->view->assign('dictCodes', $dictCodes);
            $this->assignconfig('canWrite', $this->canWrite());
            return $this->view->fetch();
        }

        $page = max(1, (int)$this->request->request('page', 1));
        $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
        $dictCode = trim((string)$this->request->request('dictionary_code', ''));
        $keyword = trim((string)$this->request->request('keyword', ''));

        $buildQuery = function () use ($dictCode, $keyword) {
            $query = Db::name('cpq_dictionary_value');
            if ($dictCode !== '') {
                $query->where('dictionary_code', $dictCode);
            }
            if ($keyword !== '') {
                $query->where('label|value_code', 'like', '%' . $keyword . '%');
            }
            return $query;
        };
        $total = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->order('dictionary_code asc,sort asc,id asc')
            ->limit(($page - 1) * $limit, $limit)
            ->select();
        $rows = $rows ?: [];

        // 引用计数（批量，避免 N+1）：被引用的值只能停用
        $ids = array_map('intval', array_column($rows, 'id'));
        $refMap = [];
        if ($ids) {
            $refRows = Db::name('cpq_dictionary_reference')
                ->where('dictionary_value_id', 'in', $ids)
                ->field('dictionary_value_id,COUNT(*) AS ref_count')
                ->group('dictionary_value_id')
                ->select();
            foreach ($refRows ?: [] as $refRow) {
                $refMap[(int)$refRow['dictionary_value_id']] = (int)$refRow['ref_count'];
            }
        }
        foreach ($rows as &$row) {
            $row['ref_count'] = isset($refMap[(int)$row['id']]) ? $refMap[(int)$row['id']] : 0;
        }
        unset($row);

        return json(['total' => $total, 'rows' => $rows]);
    }

    /**
     * 新增字典值。
     */
    public function add()
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->view->assign('row', [
                'dictionary_code' => '', 'value_code' => '', 'label' => '',
                'sort' => 0, 'status' => 'enabled',
            ]);
            return $this->view->fetch();
        }
        $row = (array)$this->request->post('row/a', []);
        try {
            $data = $this->validateValueInput($row, true);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $now = time();
        $data['createtime'] = $now;
        $data['updatetime'] = $now;
        try {
            $id = (int)Db::name('cpq_dictionary_value')->insertGetId($data);
        } catch (PDOException $exception) {
            $this->error('同一字典分类下值编码已存在', null, ['business_code' => 'CPQ_DUPLICATE']);
        }
        $this->auditService->record('create', 'cpq_dictionary_value', $id, $data, $data['dictionary_code'] . ':' . $data['value_code']);
        $this->success('保存成功', null, ['id' => $id]);
    }

    /**
     * 编辑字典值（引用保护由 DictionaryService::updateValue 保证）。
     */
    public function edit($ids = null)
    {
        $this->assertWrite();
        $row = Db::name('cpq_dictionary_value')->where('id', (int)$ids)->find();
        if (!$row) {
            $this->error('字典值不存在');
        }
        if (!$this->request->isPost()) {
            $refCount = (int)Db::name('cpq_dictionary_reference')->where('dictionary_value_id', (int)$row['id'])->count();
            $this->view->assign('row', $row);
            $this->view->assign('refCount', $refCount);
            return $this->view->fetch();
        }
        $input = (array)$this->request->post('row/a', []);
        $changes = array_intersect_key($input, array_flip(['dictionary_code', 'value_code', 'label', 'sort', 'status']));
        try {
            $changes = $this->validateValueInput($changes, false);
            $updated = $this->dictionaryService->updateValue((int)$row['id'], $changes);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        } catch (PDOException $exception) {
            $this->error('同一字典分类下值编码已存在', null, ['business_code' => 'CPQ_DUPLICATE']);
        }
        $this->auditService->record('update', 'cpq_dictionary_value', (int)$row['id'], $changes, $updated['dictionary_code'] . ':' . $updated['value_code']);
        $this->success('保存成功');
    }

    /**
     * 删除字典值：被引用删除失败时汇总提示，成功的照常删除并写审计。
     */
    public function del($ids = null)
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        $idList = array_values(array_filter(array_map('intval', explode(',', (string)$ids)), function ($id) {
            return $id > 0;
        }));
        if (!$idList) {
            $this->error('请选择要删除的字典值');
        }
        $deleted = 0;
        $failures = [];
        foreach ($idList as $id) {
            $row = Db::name('cpq_dictionary_value')->where('id', $id)->find();
            if (!$row) {
                continue;
            }
            try {
                $this->dictionaryService->deleteValue($id);
                $deleted++;
                $this->auditService->record('delete', 'cpq_dictionary_value', $id, [
                    'dictionary_code' => (string)$row['dictionary_code'],
                    'value_code' => (string)$row['value_code'],
                    'label' => (string)$row['label'],
                ], (string)$row['dictionary_code'] . ':' . (string)$row['value_code']);
            } catch (InvalidArgumentException $exception) {
                $failures[] = $row['dictionary_code'] . ':' . $row['value_code'] . '（' . $exception->getMessage() . '）';
            }
        }
        if ($failures) {
            $message = ($deleted ? '已删除 ' . $deleted . ' 条；' : '') . '以下字典值删除失败：' . implode('；', $failures);
            $this->error($message, null, ['business_code' => 'CPQ_REFERENCED']);
        }
        $this->success('已删除 ' . $deleted . ' 条');
    }

    /**
     * 启用/停用切换（被引用的值仅允许停用，由服务层强制）。
     */
    public function toggle()
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('id');
        $status = trim((string)$this->request->post('status'));
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            $this->error('状态无效', null, ['business_code' => 'CPQ_INVALID']);
        }
        try {
            $updated = $this->dictionaryService->updateValue($id, ['status' => $status]);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $this->auditService->record('toggle_status', 'cpq_dictionary_value', $id, ['status' => $status], $updated['dictionary_code'] . ':' . $updated['value_code']);
        $this->success($status === 'enabled' ? '已启用' : '已停用');
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * 校验并规范化字典值输入。
     *
     * @param array $row
     * @param bool  $requireAll 新增时要求必填字段齐全
     * @return array
     */
    private function validateValueInput(array $row, $requireAll)
    {
        $data = [];
        foreach (['dictionary_code', 'value_code'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if (array_key_exists($field, $row) || $requireAll) {
                if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $value)) {
                    throw new InvalidArgumentException(($field === 'dictionary_code' ? '字典分类编码' : '字典值编码') . '格式无效（1-64 位字母/数字/._-）');
                }
                $data[$field] = $value;
            }
        }
        if (array_key_exists('label', $row) || $requireAll) {
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 120) {
                throw new InvalidArgumentException('显示名必填且不超过 120 字');
            }
            $data['label'] = $label;
        }
        if (array_key_exists('status', $row)) {
            if (!in_array($row['status'], ['enabled', 'disabled'], true)) {
                throw new InvalidArgumentException('状态无效');
            }
            $data['status'] = (string)$row['status'];
        } elseif ($requireAll) {
            $data['status'] = 'enabled';
        }
        if (array_key_exists('sort', $row) || $requireAll) {
            $data['sort'] = max(0, (int)($row['sort'] ?? 0));
        }
        return $data;
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
