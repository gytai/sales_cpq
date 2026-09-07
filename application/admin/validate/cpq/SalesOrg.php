<?php

namespace app\admin\validate\cpq;

use think\Validate;

class SalesOrg extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_sales_org',
        'name' => 'require|max:120',
        'parent_id' => 'integer|egt:0',
        'manager_id' => 'integer|egt:0',
        'effective_date' => 'date',
        'expiry_date' => 'date|checkDateRange',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'code.regex' => '组织编码仅允许字母、数字、点、横线和下划线',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'parent_id', 'manager_id', 'effective_date', 'expiry_date', 'status'],
        'edit' => ['code', 'name', 'parent_id', 'manager_id', 'effective_date', 'expiry_date', 'status'],
    ];

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
