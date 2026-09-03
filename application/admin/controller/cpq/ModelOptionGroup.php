<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\ModelOptionGroup as ModelOptionGroupModel;
use app\common\controller\Backend;

/**
 * 型号配置结构
 *
 * @icon fa fa-sitemap
 */
class ModelOptionGroup extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $cpqRelations = ['productModel', 'optionGroup'];
    protected $cpqFormDefaults = [
        'model_id' => '', 'group_id' => '', 'sort' => 0, 'is_visible' => 1,
        'is_required' => 0, 'default_value' => '',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ModelOptionGroupModel();
    }
}
