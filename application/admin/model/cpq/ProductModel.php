<?php

namespace app\admin\model\cpq;

use think\Model;

class ProductModel extends Model
{
    protected $name = 'cpq_product_model';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return [
            'draft' => '草稿',
            'pending' => '待审批',
            'published' => '已发布',
            'expired' => '已失效',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function setEffectiveDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function setExpiryDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function series()
    {
        return $this->belongsTo(ProductSeries::class, 'series_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
