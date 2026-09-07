<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ProductModel as ProductModelEntity;
use app\common\controller\Backend;
use app\common\library\cpq\ProductCategory;

/**
 * 产品型号
 *
 * @icon fa fa-cube
 */
class ProductModel extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $searchFields = 'code,name,name_en,category_code,base_item_code';
    protected $multiFields = 'status';
    protected $cpqScopeType = 'series';
    protected $cpqRelations = ['series'];
    protected $cpqFormDefaults = [
        'series_id' => '', 'code' => '', 'name' => '', 'name_en' => '', 'category_code' => '',
        'base_item_code' => '', 'unit' => 'set', 'allow_custom' => 1, 'allow_overseas' => 1,
        'effective_date' => '', 'expiry_date' => '', 'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ProductModelEntity();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('categoryCodeList', ['' => '未分类'] + ProductCategory::list());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
