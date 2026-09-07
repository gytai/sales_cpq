<?php

namespace app\admin\model\cpq;

use think\Model;

class PriceRule extends Model
{
    protected $name = 'cpq_price_rule';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'adjustment_type_text', 'adjustment_target_text'];

    public function getStatusList()
    {
        return [
            'draft' => '草稿',
            'pending' => '待审批',
            'published' => '已发布',
            'expired' => '已失效',
        ];
    }

    public function getAdjustmentTypeList()
    {
        return [
            'fixed' => '固定价',
            'amount' => '加减金额',
            'discount' => '折扣率',
            'factor' => '系数',
        ];
    }

    public function getAdjustmentTargetList()
    {
        return [
            'base' => '基础价',
            'option' => '选项价',
            'service' => '服务价',
            'freight' => '运费',
            'subtotal' => '小计',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function getAdjustmentTypeTextAttr($value, $data)
    {
        $type = $data['adjustment_type'] ?? '';
        return $this->getAdjustmentTypeList()[$type] ?? $type;
    }

    public function getAdjustmentTargetTextAttr($value, $data)
    {
        $target = $data['adjustment_target'] ?? '';
        return $this->getAdjustmentTargetList()[$target] ?? $target;
    }

    public function setEffectiveDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function setExpiryDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }
}
