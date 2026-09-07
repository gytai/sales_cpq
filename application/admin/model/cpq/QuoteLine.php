<?php

namespace app\admin\model\cpq;

use think\Model;

class QuoteLine extends Model
{
    protected $name = 'cpq_quote_line';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function quote()
    {
        return $this->belongsTo(Quote::class, 'quote_id', 'id');
    }

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    /**
     * 解析 configuration_json。
     */
    public function getConfigurationAttribute()
    {
        $raw = $this->getData('configuration_json');
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * 解析 bom_json。
     */
    public function getBomAttribute()
    {
        $raw = $this->getData('bom_json');
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * 解析 accessories_json。
     */
    public function getAccessoriesAttribute()
    {
        $raw = $this->getData('accessories_json');
        return $raw ? json_decode($raw, true) : [];
    }
}
