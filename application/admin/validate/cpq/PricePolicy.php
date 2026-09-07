<?php

namespace app\admin\validate\cpq;

use think\Validate;

class PricePolicy extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|checkUnique',
        'name' => 'require|max:120',
        'target_type' => 'require|in:model,option,accessory_service',
        'target_id' => 'require|integer|gt:0',
        'customer_id' => 'integer|gt:0',
        'agent_id' => 'integer|gt:0',
        'currency' => 'regex:^[A-Z]{3}$',
        'market_scope' => 'in:domestic,international,all',
        'guide_price' => 'require|checkAmount',
        'line_floor' => 'require|checkAmount',
        'company_floor' => 'require|checkAmount',
        'cost' => 'checkAmount',
        'priority' => 'integer',
        'version' => 'require|integer|gt:0',
        'effective_date' => 'require|date',
        'expiry_date' => 'date|checkDateRange',
    ];

    protected $message = [
        'code.regex' => '策略编码仅允许字母、数字、点、横线和下划线',
        'currency.regex' => '币种必须是三位大写编码',
    ];

    protected $scene = [
        'add' => ['code', 'name', 'target_type', 'target_id', 'customer_id', 'agent_id', 'currency', 'market_scope', 'guide_price', 'line_floor', 'company_floor', 'cost', 'priority', 'version', 'effective_date', 'expiry_date'],
        'edit' => ['code', 'name', 'target_type', 'target_id', 'customer_id', 'agent_id', 'currency', 'market_scope', 'guide_price', 'line_floor', 'company_floor', 'cost', 'priority', 'version', 'effective_date', 'expiry_date'],
    ];

    /**
     * 编码唯一性校验：版本化实体同一 code 允许多个 version（数据库唯一键
     * uk_cpq_price_policy_code_version），因此按 code+version 判重，编辑场景再
     * 排除当前行 id，否则 FastAdmin 一行 form 回填 code 会与自身或其他版本冲突。
     *
     * @param string $value
     * @param string $rule
     * @param array  $data 调用方传入的待校验数据（包含 id / version）
     * @return bool|string
     */
    protected function checkUnique($value, $rule, $data)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        $pk = isset($data['id']) ? (int)$data['id'] : 0;
        $query = \think\Db::name('cpq_price_policy')->where('code', $value);
        if (isset($data['version'])) {
            $query->where('version', (int)$data['version']);
        }
        if ($pk > 0) {
            $query->where('id', '<>', $pk);
        }
        return $query->count() > 0 ? 'code已存在' : true;
    }

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
