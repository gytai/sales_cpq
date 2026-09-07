<?php

namespace app\admin\model\cpq;

use think\Model;

class OptionValue extends Model
{
    protected $name = 'cpq_option_value';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return ['normal' => '正常', 'hidden' => '隐藏'];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function setMaxQtyAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function optionGroup()
    {
        return $this->belongsTo(OptionGroup::class, 'group_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
