<?php

namespace app\admin\validate\cpq;

use think\Validate;

class OptionGroup extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_option_group',
        'name' => 'require|max:120',
        'input_type' => 'require|in:single,multiple,number,text,readonly',
        'is_required' => 'in:0,1',
        'min_select' => 'integer|egt:0',
        'max_select' => 'integer|egt:0|checkSelectionRange',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'code.regex' => '配置组编码仅允许字母、数字、点、横线和下划线',
    ];

    protected function checkSelectionRange($value, $rule, $data)
    {
        if (($data['input_type'] ?? '') === 'multiple' && (int)$value > 0 && (int)$value < (int)($data['min_select'] ?? 0)) {
            return '最多选择数不能小于最少选择数';
        }
        if (($data['input_type'] ?? '') === 'single' && (int)$value > 1) {
            return '单选配置组最多只能选择一项';
        }
        return true;
    }
}
