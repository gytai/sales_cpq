<?php

namespace app\admin\validate\cpq;

use think\Validate;

class Customer extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_customer',
        'name' => 'require|max:120',
        'type' => 'require|in:direct,terminal,other',
        'region_id' => 'integer|gt:0',
        'customer_level_id' => 'integer|gt:0',
        'agent_id' => 'integer|gt:0',
        'sales_org_id' => 'integer|gt:0',
        'default_currency' => 'regex:^[A-Z]{3}$',
        'status' => 'in:normal,disabled',
    ];

    protected $message = [
        'code.regex' => '客户编码仅允许字母、数字、点、横线和下划线',
        'default_currency.regex' => '币种必须是三位大写编码',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'type', 'region_id', 'customer_level_id', 'agent_id', 'sales_org_id', 'default_currency', 'status'],
        'edit' => ['code', 'name', 'type', 'region_id', 'customer_level_id', 'agent_id', 'sales_org_id', 'default_currency', 'status'],
    ];
}
