<?php

namespace app\admin\model\cpq;

use think\Model;

class ParameterDefinition extends Model
{
    protected $name = 'cpq_parameter_definition';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'value_type_text'];

    public function getStatusList()
    {
        return ['normal' => '正常', 'hidden' => '隐藏'];
    }

    public function getValueTypeList()
    {
        return ['text' => '文本', 'number' => '数值', 'boolean' => '布尔', 'select' => '选项'];
    }

    public function getStatusTextAttr($value, $data)
    {
        return $this->getStatusList()[$data['status'] ?? ''] ?? ($data['status'] ?? '');
    }

    public function getValueTypeTextAttr($value, $data)
    {
        return $this->getValueTypeList()[$data['value_type'] ?? ''] ?? ($data['value_type'] ?? '');
    }
}
