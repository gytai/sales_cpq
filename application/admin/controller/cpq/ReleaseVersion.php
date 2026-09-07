<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\ReleaseVersion as ReleaseVersionModel;
use app\common\controller\Backend;
use app\common\service\cpq\PriceReleaseService;

/**
 * 发布版本（已生效不可变，仅查看/撤回/回滚）
 *
 * @icon fa fa-tags
 */
class ReleaseVersion extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = false;
    protected $modelSceneValidate = false;
    protected $searchFields = 'object_type,object_id,change_summary';
    protected $cpqScopeType = null;
    protected $cpqRelations = [];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ReleaseVersionModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }

    public function add()
    {
        $this->error('发布版本不可修改或删除');
    }

    public function edit($ids = null)
    {
        $this->error('发布版本不可修改或删除');
    }

    public function del($ids = null)
    {
        $this->error('发布版本不可修改或删除');
    }

    /**
     * 撤回发布版本。
     */
    public function withdraw($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (!$ids || strpos((string)$ids, ',') !== false) {
            $this->error('请选择一条记录');
        }
        try {
            (new PriceReleaseService())->withdraw((int)$ids);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('已撤回');
    }

    /**
     * 基于历史版本创建回滚发布。
     */
    public function rollback($ids = null)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (!$ids || strpos((string)$ids, ',') !== false) {
            $this->error('请选择一条记录');
        }
        try {
            (new PriceReleaseService())->createRollback((int)$ids, (string)$this->request->post('change_summary', ''));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('已创建回滚发布');
    }
}
