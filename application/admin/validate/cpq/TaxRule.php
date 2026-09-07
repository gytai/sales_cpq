<?php

namespace app\admin\validate\cpq;

use think\Validate;

class TaxRule extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_tax_rule',
        'rate' => 'require|checkRate',
        'effective_date' => 'require|date',
        'expiry_date' => 'date|checkDateRange',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'code.regex' => '税率编码仅允许字母、数字、点、横线和下划线',
        'rate.require' => '税率不能为空',
    ];

    protected $scene = [
        'add' => ['code', 'rate', 'effective_date', 'expiry_date', 'status'],
        'edit' => ['code', 'rate', 'effective_date', 'expiry_date', 'status'],
    ];

    protected function checkRate($value)
    {
        if (!preg_match('/^\d+(\.\d{1,8})?$/', (string)$value)) {
            return '税率必须是非负数字';
        }
        $value = (string)$value;
        if (bccomp($value, '0', 8) < 0 || bccomp($value, '1', 8) > 0) {
            return '税率必须在 [0,1] 之间';
        }
        return true;
    }

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
