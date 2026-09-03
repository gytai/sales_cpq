<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ProductModel as ProductModelEntity;
use app\common\controller\Backend;
use think\Db;

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
        $this->assignconfig('statusList', $this->model->getStatusList());
    }

    protected function validateBeforePublish($row)
    {
        $mappings = Db::name('cpq_model_option_group')
            ->alias('mapping')
            ->join('__CPQ_OPTION_GROUP__ option_group', 'option_group.id = mapping.group_id')
            ->where('mapping.model_id', $row['id'])
            ->where('mapping.is_visible', 1)
            ->field('mapping.group_id,option_group.name,option_group.input_type')
            ->select();
        if (!$mappings) {
            $this->error('产品型号至少需要一个可见配置组才能发布');
        }
        foreach ($mappings as $mapping) {
            if (!in_array($mapping['input_type'], ['single', 'multiple'], true)) {
                continue;
            }
            $optionCount = Db::name('cpq_option_value')
                ->where('group_id', $mapping['group_id'])
                ->where('status', 'normal')
                ->count();
            if ($optionCount < 1) {
                $this->error($mapping['name'] . '没有可用选项，不能发布型号');
            }
        }
    }
}
