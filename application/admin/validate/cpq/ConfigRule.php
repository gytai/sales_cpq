<?php

namespace app\admin\validate\cpq;

use app\common\library\cpq\RuleDsl;
use think\Validate;

class ConfigRule extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$',
        'name' => 'require|max:120',
        'type' => 'require|in:REQUIRES,EXCLUDES,ONE_OF,MIN_MAX,VISIBILITY,DEFAULT,FORMULA,WARNING',
        'model_id' => 'integer|egt:0|checkScope',
        'condition_json' => 'require|validJsonObject',
        'action_json' => 'require|validActions',
        'priority' => 'integer',
        'severity' => 'in:blocking,warning',
        'version' => 'require|integer|gt:0',
        'effective_date' => 'date',
        'expiry_date' => 'date|checkDateRange',
    ];

    protected $message = [
        'code.regex' => '规则编码仅允许字母、数字、点、横线和下划线',
    ];

    protected function checkScope($value, $rule, $data)
    {
        if ((int)$value > 0 || trim((string)($data['product_line'] ?? '')) !== '') {
            return true;
        }
        try {
            RuleDsl::assertScope($value, $data['product_line'] ?? '');
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
        return true;
    }

    protected function validJsonObject($value)
    {
        try {
            RuleDsl::assertConditionJson($value);
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
        }
        return true;
    }

    protected function validActions($value)
    {
        try {
            RuleDsl::assertActionJson($value);
        } catch (\InvalidArgumentException $exception) {
            return $exception->getMessage();
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
