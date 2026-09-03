<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\AccessoryService as AccessoryServiceModel;
use app\common\controller\Backend;

/**
 * 配件与服务
 *
 * @icon fa fa-wrench
 */
class AccessoryService extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name,name_en,product_line';
    protected $multiFields = 'status';
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'type' => 'accessory', 'name' => '', 'name_en' => '', 'unit' => 'item',
        'product_line' => '', 'tax_category' => '', 'is_inventory_item' => 0,
        'description' => '', 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new AccessoryServiceModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }
}
