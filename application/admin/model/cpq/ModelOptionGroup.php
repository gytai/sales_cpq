<?php

namespace app\admin\model\cpq;

use think\Model;

class ModelOptionGroup extends Model
{
    protected $name = 'cpq_model_option_group';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function optionGroup()
    {
        return $this->belongsTo(OptionGroup::class, 'group_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
