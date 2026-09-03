<?php

namespace app\admin\validate\cpq;

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
        return (int)$value > 0 || trim((string)($data['product_line'] ?? '')) !== ''
            ? true
            : '适用型号和适用产品线至少填写一项';
    }

    protected function validJsonObject($value)
    {
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? true
            : '条件必须是合法 JSON 对象';
    }

    protected function validActions($value)
    {
        $actions = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($actions)) {
            return '动作必须是合法 JSON 数组';
        }
        if (isset($actions['action'])) {
            $actions = [$actions];
        }
        $allowedActions = ['require', 'exclude', 'one_of', 'min_max', 'hide', 'show', 'default', 'set', 'formula', 'warning'];
        foreach ($actions as $action) {
            if (!is_array($action) || !in_array(strtolower((string)($action['action'] ?? '')), $allowedActions, true)) {
                return '动作包含不受支持的 action';
            }
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
