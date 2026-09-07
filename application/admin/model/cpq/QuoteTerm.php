<?php

namespace app\admin\model\cpq;

use think\Model;

class QuoteTerm extends Model
{
    protected $name = 'cpq_quote_term';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function quote()
    {
        return $this->belongsTo(Quote::class, 'quote_id', 'id');
    }

    public function revision()
    {
        return $this->belongsTo(QuoteRevision::class, 'revision_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function getTypeList()
    {
        return [
            'payment' => '付款条款',
            'trade' => '贸易条款',
            'warranty' => '质保条款',
            'delivery' => '交货条款',
            'other' => '其他条款',
        ];
    }
}
