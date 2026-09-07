<?php

namespace app\admin\validate\cpq;

use think\Validate;

class ParameterDefinition extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$',
        'name' => 'require|max:120',
        'value_type' => 'require|in:text,number,boolean,select',
        'unit' => 'max:32',
        'option_values' => 'validJsonArray',
        'validation_rule' => 'validJsonObject',
        'status' => 'in:normal,hidden',
    ];

    protected function validJsonArray($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? true : '可选值必须是合法 JSON 数组';
    }

    protected function validJsonObject($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? true : '校验规则必须是合法 JSON 对象';
    }
}
