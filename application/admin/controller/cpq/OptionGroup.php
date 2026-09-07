<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\OptionGroup as OptionGroupModel;
use app\common\controller\Backend;

/**
 * 配置组
 *
 * @icon fa fa-list-ul
 */
class OptionGroup extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name,name_en';
    protected $multiFields = 'status';
    /** 配置组为全局字典，不受产品线范围约束 */
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'name_en' => '', 'input_type' => 'single', 'is_required' => 0,
        'min_select' => 0, 'max_select' => 1, 'affects_price' => 0, 'affects_bom' => 0,
        'affects_lead_time' => 0, 'affects_weight' => 0, 'help_text' => '', 'sort' => 0, 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new OptionGroupModel();
        $this->view->assign('inputTypeList', $this->model->getInputTypeList());
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('inputTypeList', $this->model->getInputTypeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
