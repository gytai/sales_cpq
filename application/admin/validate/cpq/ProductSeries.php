<?php

namespace app\admin\validate\cpq;

use think\Validate;

class ProductSeries extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_product_series',
        'name' => 'require|max:120',
        'default_currency' => 'require|regex:^[A-Z]{3}$',
        'version' => 'require|integer|gt:0',
        'effective_date' => 'date',
        'expiry_date' => 'date|checkDateRange',
    ];

    protected $message = [
        'code.regex' => '系列编码仅允许字母、数字、点、横线和下划线',
        'default_currency.regex' => '币种必须是三位大写编码',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'default_currency', 'version', 'effective_date', 'expiry_date'],
        'edit' => ['code', 'name', 'default_currency', 'version', 'effective_date', 'expiry_date'],
    ];

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
