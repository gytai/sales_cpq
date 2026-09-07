<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ConfigTemplate as ConfigTemplateModel;
use app\common\controller\Backend;

/**
 * 推荐配置模板
 *
 * @icon fa fa-clone
 */
class ConfigTemplate extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $cpqScopeType = 'model';
    protected $cpqRelations = ['productModel'];
    protected $searchFields = 'code,name,market_scope,customer_level';
    protected $multiFields = 'status';
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'model_id' => '', 'market_scope' => '', 'customer_level' => '',
        'config_json' => '{}', 'description' => '', 'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ConfigTemplateModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
