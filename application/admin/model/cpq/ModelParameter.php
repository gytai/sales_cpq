<?php

namespace app\admin\model\cpq;

use think\Model;

class ModelParameter extends Model
{
    protected $name = 'cpq_model_parameter';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function parameterDefinition()
    {
        return $this->belongsTo(ParameterDefinition::class, 'parameter_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
