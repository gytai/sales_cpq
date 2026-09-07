<?php

namespace app\admin\model\cpq;

use think\Model;

class FeeRule extends Model
{
    protected $name = 'cpq_fee_rule';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'calculation_type_text'];

    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'hidden' => '停用',
        ];
    }

    public function getCalculationTypeList()
    {
        return [
            'fixed' => '固定金额',
            'per_quantity' => '按数量',
            'percentage' => '按比例',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function getCalculationTypeTextAttr($value, $data)
    {
        $type = $data['calculation_type'] ?? '';
        return $this->getCalculationTypeList()[$type] ?? $type;
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
