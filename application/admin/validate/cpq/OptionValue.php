<?php

namespace app\admin\validate\cpq;

use think\Validate;

class OptionValue extends Validate
{
    protected $rule = [
        'group_id' => 'require|integer|gt:0',
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$',
        'name' => 'require|max:120',
        'default_qty' => 'require|number|egt:0',
        'min_qty' => 'require|number|egt:0',
        'max_qty' => 'number|checkQuantityRange',
        'step' => 'require|number|gt:0',
        'parameter_json' => 'validJson',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'code.regex' => '选项编码仅允许字母、数字、点、横线和下划线',
    ];

    protected function checkQuantityRange($value, $rule, $data)
    {
        return $value === '' || $value === null || bccomp((string)$value, (string)($data['min_qty'] ?? '0'), 4) >= 0
            ? true
            : '最大数量不能小于最小数量';
    }

    protected function validJson($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? true : '技术参数必须是合法 JSON';
    }
}
