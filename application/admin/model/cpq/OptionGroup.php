<?php

namespace app\admin\model\cpq;

use think\Model;

class OptionGroup extends Model
{
    protected $name = 'cpq_option_group';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['input_type_text', 'status_text'];

    public function getInputTypeList()
    {
        return [
            'single' => '单选',
            'multiple' => '多选',
            'number' => '数值',
            'text' => '文本',
            'readonly' => '只读计算值',
        ];
    }

    public function getStatusList()
    {
        return ['normal' => '正常', 'hidden' => '隐藏'];
    }

    public function getInputTypeTextAttr($value, $data)
    {
        $type = $data['input_type'] ?? '';
        return $this->getInputTypeList()[$type] ?? $type;
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }
}
