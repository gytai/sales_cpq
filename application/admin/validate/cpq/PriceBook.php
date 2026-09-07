<?php

namespace app\admin\validate\cpq;

use think\Validate;

class PriceBook extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_price_book',
        'name' => 'require|max:120',
        'currency' => 'require|regex:^[A-Z]{3}$',
        'market_scope' => 'in:domestic,international,all',
        'tax_mode' => 'in:tax_exclusive,tax_inclusive',
        'priority' => 'integer',
        'version' => 'require|integer|gt:0',
        'effective_date' => 'require|date',
        'expiry_date' => 'date|checkDateRange',
    ];

    protected $message = [
        'code.regex' => '价格表编码仅允许字母、数字、点、横线和下划线',
        'currency.regex' => '币种必须是三位大写编码',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'currency', 'market_scope', 'tax_mode', 'priority', 'version', 'effective_date', 'expiry_date'],
        'edit' => ['code', 'name', 'currency', 'market_scope', 'tax_mode', 'priority', 'version', 'effective_date', 'expiry_date'],
    ];

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
