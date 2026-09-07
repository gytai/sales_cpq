<?php

namespace app\admin\model\cpq;

use think\Model;

/**
 * CPQ 报价打印记录（M3，P60）。异步 PDF 任务；成功文件不可覆盖。
 */
class QuoteDocument extends Model
{
    protected $name = 'cpq_quote_document';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'pending' => '排队中',
            'processing' => '生成中',
            'succeeded' => '已生成',
            'failed' => '失败',
        ];
    }
}
