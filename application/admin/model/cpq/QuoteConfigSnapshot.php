<?php

namespace app\admin\model\cpq;

use think\Model;

class QuoteConfigSnapshot extends Model
{
    protected $name = 'cpq_quote_config_snapshot';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function revision()
    {
        return $this->belongsTo(QuoteRevision::class, 'revision_id', 'id');
    }

    public function quoteLine()
    {
        return $this->belongsTo(QuoteLine::class, 'quote_line_id', 'id');
    }

    /**
     * 解析 configuration。
     */
    public function getConfigurationAttribute()
    {
        $raw = $this->getData('configuration');
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * 解析 applied_rules_json。
     */
    public function getAppliedRulesAttribute()
    {
        $raw = $this->getData('applied_rules_json');
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * 解析 validation_errors_json。
     */
    public function getValidationErrorsAttribute()
    {
        $raw = $this->getData('validation_errors_json');
        return $raw ? json_decode($raw, true) : [];
    }
}
