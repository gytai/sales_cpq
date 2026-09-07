<?php

namespace app\admin\model\cpq;

use think\Model;

class QuoteAttachment extends Model
{
    protected $name = 'cpq_quote_attachment';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function quote()
    {
        return $this->belongsTo(Quote::class, 'quote_id', 'id');
    }
}
