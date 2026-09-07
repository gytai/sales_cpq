<?php

namespace app\admin\model\cpq;

use think\Model;

class QuoteRevision extends Model
{
    protected $name = 'cpq_quote_revision';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return [
            'draft' => '草稿',
            'frozen' => '已冻结',
            'submitted' => '已提交',
            'approved' => '已批准',
            'archived' => '已归档',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class, 'quote_id', 'id');
    }

    public function configSnapshots()
    {
        return $this->hasMany(QuoteConfigSnapshot::class, 'revision_id', 'id');
    }

    public function priceSnapshots()
    {
        return $this->hasMany(QuotePriceSnapshot::class, 'revision_id', 'id');
    }

    /**
     * 解析 pricing_request_json。
     */
    public function getPricingRequestAttribute()
    {
        $raw = $this->getData('pricing_request_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 pricing_result_json。
     */
    public function getPricingResultAttribute()
    {
        $raw = $this->getData('pricing_result_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 config_snapshot_json。
     */
    public function getConfigSnapshotAttribute()
    {
        $raw = $this->getData('config_snapshot_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 price_snapshot_json。
     */
    public function getPriceSnapshotAttribute()
    {
        $raw = $this->getData('price_snapshot_json');
        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * 解析 block_reasons_json。
     */
    public function getBlockReasonsAttribute()
    {
        $raw = $this->getData('block_reasons_json');
        return $raw ? json_decode($raw, true) : [];
    }

    /**
     * 解析 diff_from_previous_json。
     */
    public function getDiffAttribute()
    {
        $raw = $this->getData('diff_from_previous_json');
        return $raw ? json_decode($raw, true) : null;
    }
}
