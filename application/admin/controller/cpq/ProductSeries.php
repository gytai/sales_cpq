<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ProductSeries as ProductSeriesModel;
use app\common\controller\Backend;

/**
 * 产品系列
 *
 * @icon fa fa-cubes
 */
class ProductSeries extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name,name_en,product_line';
    protected $multiFields = 'status';
    protected $cpqScopeType = 'line';
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'name_en' => '', 'business_unit' => '', 'product_line' => '',
        'brand' => '', 'default_unit' => 'set', 'default_currency' => 'CNY', 'description' => '',
        'image' => '', 'effective_date' => '', 'expiry_date' => '', 'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ProductSeriesModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
