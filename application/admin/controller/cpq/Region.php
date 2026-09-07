<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\Region as RegionModel;
use app\common\controller\Backend;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\ChannelTreeService;
use app\common\service\cpq\MasterDataLifecycleService;
use think\Db;
use think\Exception;
use think\exception\ValidateException;
use think\exception\PDOException;
use think\Loader;

/**
 * 销售区域（树形）
 *
 * @icon fa fa-map-marker
 */
class Region extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name';
    protected $multiFields = 'status';
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'parent_id' => 0, 'default_currency' => 'CNY',
        'sales_org_id' => '', 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new RegionModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }

    public function add()
    {
        if (!$this->request->isPost()) {
            $this->view->assign('row', $this->cpqFormDefaults);
            return $this->view->fetch();
        }
        $params = $this->request->post('row/a');
        if (empty($params)) {
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $params = $this->preExcludeFields($params);

        Db::startTrans();
        try {
            $this->cpqValidateTreeNode($params, 'add');
            $id = (new ChannelTreeService())->saveNode($this->cpqTable(), $params);
            $row = $this->model->get($id);
            $this->cpqAudit(AuditLogService::ACTION_CREATE, $row ? $row->toArray() : ['id' => (int)$id], $params);
            Db::commit();
        } catch (ValidateException|PDOException|Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
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

        Db::startTrans();
        try {
            $this->cpqValidateTreeNode($params, 'edit');
            $original = $row->getData();
            (new ChannelTreeService())->saveNode($this->cpqTable(), $params, (int)$ids);
            $fresh = $this->model->get($ids);
            $freshData = $fresh ? $fresh->toArray() : ['id' => (int)$ids];
            $this->cpqAudit(AuditLogService::ACTION_UPDATE, $freshData, $this->cpqDiff($original, $fresh ? $fresh->getData() : $original));
            Db::commit();
        } catch (ValidateException|PDOException|Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
    }

    /**
     * 移动节点（批量）。
     */
    public function move($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        $idList = array_filter(array_map('intval', explode(',', (string)$ids)));
        if (empty($idList)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }
        $parentId = (int)$this->request->post('parent_id', 0);

        $service = new ChannelTreeService();
        Db::startTrans();
        try {
            foreach ($idList as $id) {
                $service->moveNode($this->cpqTable(), $id, $parentId);
                $row = $this->model->get($id);
                if ($row) {
                    $this->cpqAudit(AuditLogService::ACTION_UPDATE, $row->toArray(), ['parent_id' => $parentId]);
                }
            }
            Db::commit();
        } catch (\InvalidArgumentException|Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
        $this->success();
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
        try {
            // 树结构断言：有子节点或被树内引用时拒绝删除
            (new ChannelTreeService())->assertNodeDeletable($this->cpqTable(), array_map(function ($rowArray) {
                return (int)$rowArray['id'];
            }, $rowsArray));
            // 跨表引用检查
            (new MasterDataLifecycleService())->assertDeletable($this->cpqTable(), $rowsArray);
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

    /**
     * 树节点写操作前对提交数据跑模型验证（写入走 ChannelTreeService，不触发模型 save 事件）。
     *
     * @param array  $params
     * @param string $scene add|edit
     * @throws ValidateException
     */
    protected function cpqValidateTreeNode(array $params, $scene)
    {
        if (!$this->modelValidate) {
            return;
        }
        $name = str_replace("\\model\\", "\\validate\\", get_class($this->model));
        $validate = Loader::validate($name);
        if ($this->modelSceneValidate) {
            $validate->scene($scene);
        }
        if (!$validate->check($params)) {
            throw new ValidateException($validate->getError());
        }
    }
}
