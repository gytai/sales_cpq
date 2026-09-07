<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\CustomerLevel as CustomerLevelModel;
use app\common\controller\Backend;

/**
 * 客户等级
 *
 * @icon fa fa-star
 */
class CustomerLevel extends Backend
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
        'market_scope' => 'all', 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new CustomerLevelModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('marketScopeList', $this->model->getMarketScopeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('marketScopeList', $this->model->getMarketScopeList());
    }
}
