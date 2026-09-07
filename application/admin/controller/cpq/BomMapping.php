<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\BomMapping as BomMappingModel;
use app\common\controller\Backend;

/**
 * 配置 BOM 映射
 *
 * @icon fa fa-cubes
 */
class BomMapping extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $cpqRequiresApproval = false;
    protected $cpqScopeType = 'model';
    protected $cpqRelations = ['productModel', 'optionValue'];
    protected $searchFields = 'material_code,substitute_material_code';
    protected $multiFields = 'status';
    protected $cpqFormDefaults = [
        'model_id' => '', 'option_value_id' => '', 'material_code' => '', 'qty_formula' => '1',
        'unit' => 'item', 'substitute_material_code' => '', 'loss_rate' => '0',
        'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new BomMappingModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
