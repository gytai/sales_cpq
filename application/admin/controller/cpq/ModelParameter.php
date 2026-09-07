<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\ModelParameter as ModelParameterModel;
use app\common\controller\Backend;

/**
 * 型号技术参数
 *
 * @icon fa fa-link
 */
class ModelParameter extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $cpqScopeType = 'model';
    protected $cpqRelations = ['productModel', 'parameterDefinition'];
    protected $cpqFormDefaults = [
        'model_id' => '', 'parameter_id' => '', 'value' => '', 'is_configurable' => 0,
        'is_required' => 0, 'sort' => 0,
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ModelParameterModel();
    }
}
