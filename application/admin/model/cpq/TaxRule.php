<?php

namespace app\admin\model\cpq;

use think\Model;

class TaxRule extends Model
{
    protected $name = 'cpq_tax_rule';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'hidden' => '停用',
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
}
