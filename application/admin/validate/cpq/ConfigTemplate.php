<?php

namespace app\admin\validate\cpq;

use think\Validate;

class ConfigTemplate extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$',
        'name' => 'require|max:120',
        'model_id' => 'require|integer|gt:0',
        'market_scope' => 'max:64',
        'customer_level' => 'max:64',
        'config_json' => 'require|validJsonObject',
        'version' => 'require|integer|gt:0',
        'status' => 'in:draft,pending,published,expired',
    ];

    protected function validJsonObject($value)
    {
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? true : '产品配置必须是合法 JSON 对象';
    }
}
