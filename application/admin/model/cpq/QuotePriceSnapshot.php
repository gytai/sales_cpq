<?php

namespace app\admin\model\cpq;

use think\Model;

class QuotePriceSnapshot extends Model
{
    protected $name = 'cpq_quote_price_snapshot';
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
     * 解析 converted_amounts_json。
     */
    public function getConvertedAmountsAttribute()
    {
        $raw = $this->getData('converted_amounts_json');
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * 解析 price_trace_json。
     */
    public function getPriceTraceAttribute()
    {
        $raw = $this->getData('price_trace_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 price_book_snapshot_json。
     */
    public function getPriceBookSnapshotAttribute()
    {
        $raw = $this->getData('price_book_snapshot_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 policy_snapshot_json。
     */
    public function getPolicySnapshotAttribute()
    {
        $raw = $this->getData('policy_snapshot_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 tax_rule_snapshot_json。
     */
    public function getTaxRuleSnapshotAttribute()
    {
        $raw = $this->getData('tax_rule_snapshot_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 exchange_rate_snapshot_json。
     */
    public function getExchangeRateSnapshotAttribute()
    {
        $raw = $this->getData('exchange_rate_snapshot_json');
        return $raw ? json_decode($raw, true) : null;
    }
}
