<?php

namespace app\admin\validate\cpq;

use think\Validate;

class SalesOrgMember extends Validate
{
    protected $rule = [
        'org_id' => 'require|integer|gt:0',
        'admin_id' => 'require|integer|gt:0',
        'role' => 'max:32',
        'effective_date' => 'date',
        'expiry_date' => 'date|checkDateRange',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'org_id.require' => '销售组织不能为空',
        'admin_id.require' => '成员管理员不能为空',
    ];

    protected $scene = [
        'add' => ['org_id', 'admin_id', 'role', 'effective_date', 'expiry_date', 'status'],
        'edit' => ['org_id', 'admin_id', 'role', 'effective_date', 'expiry_date', 'status'],
    ];

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
