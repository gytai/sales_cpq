<?php

namespace app\admin\library\traits;

use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\MasterDataLifecycleService;
use app\common\service\cpq\ProductLineScopeService;
use think\Db;
use think\Exception;
use think\exception\ValidateException;
use think\exception\PDOException;

/**
 * CPQ 主数据 CRUD 基座（所有 cpq 控制器使用）。
 *
 * 在 FastAdmin CRUD 之上叠加：
 *  - 产品线数据范围：列表过滤 + 写操作服务端校验（不依赖前端按钮隐藏）；
 *  - 版本不可变：已发布/已失效版本不可编辑（配合 CpqVersioned 使用）；
 *  - 引用保护：删除前由生命周期服务做跨表引用检查；
 *  - 审计：新增/编辑/删除在同一事务内写入审计日志。
 *
 * 控制器通过 $cpqScopeType 声明范围类型（由各控制器自行声明属性，
 * trait 不重复声明，避免与控制器属性定义冲突）：
 *  - null    全局字典，不受产品线范围约束；
 *  - 'line'  自身带 product_line 字段（系列、配件服务、规则）；
 *  - 'series' 通过 series_id 关联系列（型号）；
 *  - 'model' 通过 model_id → 系列关联（型号参数、配置结构、模板、BOM 映射）；
 *  - 'rule'  规则专用：product_line 或经型号→系列解析。
 */
trait CpqRelationIndex
{
    public function add()
    {
        if (!$this->request->isPost()) {
            $this->view->assign('row', $this->cpqFormDefaults ?? []);
            return $this->view->fetch();
        }
        $params = $this->request->post('row/a');
        if (empty($params)) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);

        $this->cpqAssertWriteScope($params);

        if ($this->dataLimit && $this->dataLimitFieldAutoFill) {
            $params[$this->dataLimitField] = $this->auth->id;
        }
        $result = false;
        Db::startTrans();
        try {
            // 是否采用模型验证
            if ($this->modelValidate) {
                $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.add' : $name) : $this->modelValidate;
                $this->model->validateFailException()->validate($validate);
            }
            $result = $this->model->allowField(true)->save($params);
            $this->cpqAudit(AuditLogService::ACTION_CREATE, $this->model->toArray(), $params);
            Db::commit();
        } catch (ValidateException|PDOException|Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        if ($result === false) {
            $this->error(__('No rows were inserted'));
        }
        $this->success();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        try {
            (new MasterDataLifecycleService())->assertEditable($this->cpqTable(), $row->toArray());
            $this->cpqAssertRowScope($row->toArray());
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        if (!$this->request->isPost()) {
            $this->view->assign('row', $row);
            return $this->view->fetch();
        }
        $params = $this->request->post('row/a');
        if (empty($params)) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);

        try {
            $this->cpqAssertWriteScope($params);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }

        $result = false;
        Db::startTrans();
        try {
            // 是否采用模型验证
            if ($this->modelValidate) {
                $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                $validate = is_bool($this->modelValidate) ? ($this->modelSceneValidate ? $name . '.edit' : $name) : $this->modelValidate;
                $row->validateFailException()->validate($validate);
            }
            $original = $row->getData();
            $result = $row->allowField(true)->save($params);
            $this->cpqAudit(AuditLogService::ACTION_UPDATE, $row->toArray(), $this->cpqDiff($original, $row->getData()));
            Db::commit();
        } catch (ValidateException|PDOException|Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        if ($result === false) {
            $this->error(__('No rows were updated'));
        }
        $this->success();
    }

    /**
     * 只读详情：复用表单视图渲染，前端以只读模式展示（不做任何写操作）。
     * 已发布/已失效版本同样可查看，编辑守卫只在 edit 入口生效。
     *
     * @param int|string|null $ids
     */
    public function detail($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        try {
            $this->cpqAssertRowScope($row->toArray());
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->view->assign('row', $row);
        return $this->view->fetch('detail');
    }

    public function del($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $rows = $this->model->where($this->model->getPk(), 'in', $ids)->select();
        $rowsArray = [];
        foreach ($rows as $row) {
            $rowsArray[] = $row->toArray();
        }
        $service = new MasterDataLifecycleService();
        try {
            foreach ($rowsArray as $rowArray) {
                $service->assertDraftDeletable($this->cpqTable(), $rowArray);
                $this->cpqAssertRowScope($rowArray);
            }
            $service->assertDeletable($this->cpqTable(), $rowsArray);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }

        $count = 0;
        Db::startTrans();
        try {
            foreach ($rows as $row) {
                $rowArray = $row->toArray();
                $count += $row->delete();
                $this->cpqAudit(AuditLogService::ACTION_DELETE, $rowArray, $rowArray);
            }
            Db::commit();
        } catch (PDOException|Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        if ($count) {
            $this->success();
        }
        $this->error(__('No rows were deleted'));
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if (!$this->request->isAjax()) {
            return $this->view->fetch();
        }
        if ($this->request->request('keyField')) {
            return $this->selectpage();
        }

        list($where, $sort, $order, $offset, $limit) = $this->buildparams();
        $query = $this->model;
        if (!empty($this->cpqRelations)) {
            $this->relationSearch = true;
            $query = $query->with($this->cpqRelations);
        }
        $query = $this->cpqApplyScopeFilter($query);
        $list = $query->where($where)->order($sort, $order)->paginate($limit);

        return json([
            'total' => $list->total(),
            'rows' => $list->items(),
        ]);
    }

    // ------------------------------------------------------------------
    // 产品线数据范围
    // ------------------------------------------------------------------

    /**
     * 当前管理员的数据范围服务。
     *
     * @return ProductLineScopeService
     */
    protected function cpqScope()
    {
        return ProductLineScopeService::forAdmin(
            $this->auth->id,
            $this->auth->isSuperAdmin()
        );
    }

    /**
     * 列表按产品线范围过滤（子查询/预解析 ID，避免与关联查询别名冲突）。
     *
     * @param \think\db\Query $query
     * @return \think\db\Query
     */
    protected function cpqApplyScopeFilter($query)
    {
        $scopeType = $this->cpqScopeType ?? null;
        if ($scopeType === null) {
            return $query;
        }
        $scope = $this->cpqScope();
        if ($scope->isUnrestricted()) {
            return $query;
        }
        $lines = $scope->getAllowedLines() ?: ['__CPQ_NONE__'];

        switch ($scopeType) {
            case 'line':
                return $query->where('product_line', 'in', $lines);
            case 'series':
                $seriesIds = Db::name('cpq_product_series')->where('product_line', 'in', $lines)->column('id');
                return $query->where('series_id', 'in', $seriesIds ?: [0]);
            case 'model':
                return $query->where('model_id', 'in', $this->cpqScopedModelIds($lines));
            case 'rule':
                $modelIds = $this->cpqScopedModelIds($lines);
                return $query->where(function ($subQuery) use ($lines, $modelIds) {
                    $subQuery->where('product_line', 'in', $lines)
                        ->whereOr('model_id', 'in', $modelIds ?: [0]);
                });
        }
        return $query;
    }

    /**
     * 范围内产品线对应的型号 ID 列表。
     *
     * @param array $lines
     * @return array
     */
    private function cpqScopedModelIds(array $lines)
    {
        $seriesIds = Db::name('cpq_product_series')->where('product_line', 'in', $lines)->column('id');
        if (!$seriesIds) {
            return [];
        }
        return Db::name('cpq_product_model')->where('series_id', 'in', $seriesIds)->column('id');
    }

    /**
     * 写操作（新增/编辑提交的数据）范围校验 + 型号子表父型号可编辑校验。
     *
     * @param array $params
     * @throws \InvalidArgumentException
     */
    protected function cpqAssertWriteScope(array $params)
    {
        $service = new MasterDataLifecycleService();
        $service->assertWritableParent($this->cpqTable(), $params);
        $service->assertLineScope($this->cpqTable(), $params, $this->cpqScope());
    }

    /**
     * 已存在记录的范围校验。
     *
     * @param array $row
     * @throws \InvalidArgumentException
     */
    protected function cpqAssertRowScope(array $row)
    {
        (new MasterDataLifecycleService())->assertLineScope($this->cpqTable(), $row, $this->cpqScope());
    }

    // ------------------------------------------------------------------
    // 审计与工具
    // ------------------------------------------------------------------

    /**
     * 在当前事务内记录审计日志。
     *
     * @param string $action
     * @param array  $row
     * @param array  $detail
     */
    protected function cpqAudit($action, array $row, array $detail)
    {
        (new AuditLogService())->record(
            $action,
            $this->cpqTable(),
            (int)($row['id'] ?? 0),
            $detail,
            (string)($row['code'] ?? $row['material_code'] ?? '')
        );
    }

    /**
     * 计算编辑差异（仅保留被修改的字段）。
     *
     * @param array $original
     * @param array $current
     * @return array
     */
    protected function cpqDiff(array $original, array $current)
    {
        $diff = [];
        foreach ($current as $field => $value) {
            if (!array_key_exists($field, $original)) {
                continue;
            }
            if ((string)$original[$field] !== (string)$value) {
                $diff[$field] = ['from' => $original[$field], 'to' => $value];
            }
        }
        return $diff;
    }

    /**
     * 当前控制器对应逻辑表名（不含前缀）。
     *
     * @return string
     */
    protected function cpqTable()
    {
        $table = $this->model->getTable();
        $prefix = (string)\think\Config::get('database.prefix');
        return $prefix !== '' && strpos($table, $prefix) === 0 ? substr($table, strlen($prefix)) : $table;
    }
}
