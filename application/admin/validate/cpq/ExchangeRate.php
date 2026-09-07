<?php

namespace app\admin\validate\cpq;

use think\Validate;

class ExchangeRate extends Validate
{
    protected $rule = [
        'source_currency' => 'require|regex:^[A-Z]{3}$',
        'target_currency' => 'require|regex:^[A-Z]{3}$',
        'rate' => 'require|checkRate',
        'effective_date' => 'require|date',
        'expiry_date' => 'date|checkDateRange',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'source_currency.regex' => '币种必须是三位大写编码',
        'target_currency.regex' => '币种必须是三位大写编码',
        'rate.require' => '汇率不能为空',
    ];

    protected $scene = [
        'add' => ['source_currency', 'target_currency', 'rate', 'effective_date', 'expiry_date', 'status'],
        'edit' => ['source_currency', 'target_currency', 'rate', 'effective_date', 'expiry_date', 'status'],
    ];

    protected function checkRate($value)
    {
        if (!preg_match('/^\d+(\.\d{1,8})?$/', (string)$value)) {
            return '汇率必须是非负数字';
        }
        return bccomp((string)$value, '0', 8) > 0 ? true : '汇率必须大于0';
    }

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
