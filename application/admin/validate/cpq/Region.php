<?php

namespace app\admin\validate\cpq;

use think\Validate;

class Region extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_region',
        'name' => 'require|max:120',
        'parent_id' => 'integer|egt:0',
        'default_currency' => 'regex:^[A-Z]{3}$',
        'sales_org_id' => 'integer|gt:0',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'code.regex' => '区域编码仅允许字母、数字、点、横线和下划线',
        'default_currency.regex' => '币种必须是三位大写编码',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'parent_id', 'default_currency', 'sales_org_id', 'status'],
        'edit' => ['code', 'name', 'parent_id', 'default_currency', 'sales_org_id', 'status'],
    ];
}
