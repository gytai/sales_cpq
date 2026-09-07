<?php

namespace app\admin\validate\cpq;

use think\Validate;

class Agent extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_agent',
        'customer_id' => 'require|integer|gt:0',
        'agent_level_id' => 'integer|gt:0',
        'credit_limit' => 'checkAmount',
        'auth_start_date' => 'date',
        'auth_end_date' => 'date|checkAuthDateRange',
        'status' => 'in:normal,disabled',
    ];

    protected $message = [
        'code.regex' => '代理商编码仅允许字母、数字、点、横线和下划线',
        'customer_id.require' => '关联客户不能为空',
    ];

    protected $scene = [
        'add' => ['code', 'customer_id', 'agent_level_id', 'credit_limit', 'auth_start_date', 'auth_end_date', 'status'],
        'edit' => ['code', 'customer_id', 'agent_level_id', 'credit_limit', 'auth_start_date', 'auth_end_date', 'status'],
    ];

    protected function checkAmount($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        return preg_match('/^\d+(\.\d{1,8})?$/', (string)$value) ? true : '金额必须是非负数字';
    }

    protected function checkAuthDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['auth_start_date']) || $value >= $data['auth_start_date']
            ? true
            : '授权失效日期不能早于授权生效日期';
    }
}
