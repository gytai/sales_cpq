<?php

namespace app\admin\validate\cpq;

use think\Validate;

class ModelOptionGroup extends Validate
{
    protected $rule = [
        'model_id' => 'require|integer|gt:0',
        'group_id' => 'require|integer|gt:0',
        'sort' => 'integer',
        'is_visible' => 'in:0,1',
        'is_required' => 'in:0,1',
        'default_value' => 'validJsonValue',
    ];

    protected function validJsonValue($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? true : '默认值必须是合法 JSON';
    }
}
