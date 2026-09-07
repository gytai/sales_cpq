<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\AgentLevel as AgentLevelModel;
use app\common\controller\Backend;

/**
 * 代理等级
 *
 * @icon fa fa-star-half-o
 */
class AgentLevel extends Backend
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
        'code' => '', 'name' => '', 'sort' => 0, 'default_discount' => '',
        'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new AgentLevelModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
