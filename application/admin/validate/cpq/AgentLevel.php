<?php

namespace app\admin\validate\cpq;

use think\Validate;

class AgentLevel extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_agent_level',
        'name' => 'require|max:120',
        'sort' => 'integer|egt:0',
        'default_discount' => 'checkDiscount',
        'status' => 'in:normal,hidden',
    ];

    protected $message = [
        'code.regex' => '等级编码仅允许字母、数字、点、横线和下划线',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'sort', 'default_discount', 'status'],
        'edit' => ['code', 'name', 'sort', 'default_discount', 'status'],
    ];

    protected function checkDiscount($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        $value = (string)$value;
        if (!preg_match('/^\d+(\.\d{1,8})?$/', $value)) {
            return '默认折扣率必须是非负数字';
        }
        if (bccomp($value, '0', 8) <= 0 || bccomp($value, '1', 8) > 0) {
            return '默认折扣率必须在 (0,1] 之间';
        }
        return true;
    }
}
