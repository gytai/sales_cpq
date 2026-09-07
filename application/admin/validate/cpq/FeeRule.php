<?php

namespace app\admin\validate\cpq;

use think\Validate;

class FeeRule extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_fee_rule',
        'name' => 'require|max:120',
        'fee_type' => 'require|max:32',
        'calculation_type' => 'require|in:fixed,per_quantity,percentage',
        'value' => 'require|checkAmount',
        'currency' => 'regex:^[A-Z]{3}$',
        'priority' => 'integer',
        'effective_date' => 'require|date',
        'expiry_date' => 'date|checkDateRange',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'code.regex' => '费用规则编码仅允许字母、数字、点、横线和下划线',
        'currency.regex' => '币种必须是三位大写编码',
        'value.require' => '计算值不能为空',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'fee_type', 'calculation_type', 'value', 'currency', 'priority', 'effective_date', 'expiry_date', 'status'],
        'edit' => ['code', 'name', 'fee_type', 'calculation_type', 'value', 'currency', 'priority', 'effective_date', 'expiry_date', 'status'],
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
