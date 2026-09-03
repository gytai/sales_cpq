<?php

namespace app\admin\model\cpq;

use think\Model;

class BomMapping extends Model
{
    protected $name = 'cpq_bom_mapping';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return ['draft' => '草稿', 'published' => '已发布', 'expired' => '已失效'];
    }

    public function getStatusTextAttr($value, $data)
    {
        return $this->getStatusList()[$data['status'] ?? ''] ?? ($data['status'] ?? '');
    }

    public function setOptionValueIdAttr($value)
    {
        return $value === '' || $value === null ? null : (int)$value;
    }

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function optionValue()
    {
        return $this->belongsTo(OptionValue::class, 'option_value_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
