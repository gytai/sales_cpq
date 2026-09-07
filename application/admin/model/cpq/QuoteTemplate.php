<?php

namespace app\admin\model\cpq;

use think\Model;

/**
 * CPQ 报价模板（M3，P59）。中英文结构模板，变量白名单渲染，市场默认模板。
 */
class QuoteTemplate extends Model
{
    protected $name = 'cpq_quote_template';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return ['draft' => '草稿', 'published' => '已发布', 'disabled' => '已停用'];
    }

    public function getLanguageList()
    {
        return ['zh' => '中文', 'en' => '英文'];
    }

    public function getMarketList()
    {
        return ['all' => '全部市场', 'domestic' => '国内', 'international' => '国际'];
    }
}
