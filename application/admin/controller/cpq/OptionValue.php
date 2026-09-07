<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\OptionValue as OptionValueModel;
use app\common\controller\Backend;

/**
 * 配置选项
 *
 * @icon fa fa-check-square-o
 */
class OptionValue extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $searchFields = 'code,name,name_en,material_code';
    protected $multiFields = 'status';
    /** 配置选项为全局字典，不受产品线范围约束 */
    protected $cpqScopeType = null;
    protected $cpqRelations = ['optionGroup'];
    protected $cpqFormDefaults = [
        'group_id' => '', 'code' => '', 'name' => '', 'name_en' => '', 'material_code' => '',
        'default_qty' => '1', 'min_qty' => '0', 'max_qty' => '', 'step' => '1', 'price_key' => '',
        'cost_key' => '', 'image' => '', 'parameter_json' => '', 'no_material' => 0, 'weigh' => 0, 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new OptionValueModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
