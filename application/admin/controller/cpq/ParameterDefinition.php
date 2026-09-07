<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\ParameterDefinition as ParameterDefinitionModel;
use app\common\controller\Backend;

/**
 * 技术参数定义
 *
 * @icon fa fa-list-alt
 */
class ParameterDefinition extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name,unit';
    protected $multiFields = 'status';
    /** 参数定义为全局字典，不受产品线范围约束 */
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'value_type' => 'text', 'unit' => '', 'option_values' => '',
        'validation_rule' => '', 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ParameterDefinitionModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('valueTypeList', $this->model->getValueTypeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('valueTypeList', $this->model->getValueTypeList());
    }
}
