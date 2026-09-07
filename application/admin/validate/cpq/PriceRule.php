<?php

namespace app\admin\validate\cpq;

use think\Validate;

class PriceRule extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_price_rule',
        'name' => 'require|max:120',
        'adjustment_type' => 'require|in:fixed,amount,discount,factor',
        'adjustment_target' => 'in:base,option,service,freight,subtotal',
        'adjustment_value' => 'require',
        'minimum_amount' => 'checkAmount',
        'maximum_amount' => 'checkAmount',
        'priority' => 'integer',
        'version' => 'require|integer|gt:0',
        'effective_date' => 'require|date',
        'expiry_date' => 'date|checkDateRange',
    ];

    protected $message = [
        'code.regex' => '规则编码仅允许字母、数字、点、横线和下划线',
        'adjustment_value.require' => '调整值不能为空',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'adjustment_type', 'adjustment_target', 'adjustment_value', 'minimum_amount', 'maximum_amount', 'priority', 'version', 'effective_date', 'expiry_date'],
        'edit' => ['code', 'name', 'adjustment_type', 'adjustment_target', 'adjustment_value', 'minimum_amount', 'maximum_amount', 'priority', 'version', 'effective_date', 'expiry_date'],
    ];

    protected function checkAmount($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        return preg_match('/^\d+(\.\d{1,8})?$/', (string)$value) ? true : '金额必须是非负数字';
    }

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
