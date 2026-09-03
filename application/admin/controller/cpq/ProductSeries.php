<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ProductSeries as ProductSeriesModel;
use app\common\controller\Backend;
use think\Db;

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

    protected function validateBeforePublish($row)
    {
        $modelCount = Db::name('cpq_product_model')
            ->where('series_id', $row['id'])
            ->where('status', '<>', 'expired')
            ->count();
        if ($modelCount < 1) {
            $this->error('产品系列至少需要一个有效型号才能发布');
        }
    }
}
