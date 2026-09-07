<?php

namespace app\admin\model\cpq;

use think\Model;

class PriceBook extends Model
{
    protected $name = 'cpq_price_book';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'market_scope_text', 'tax_mode_text'];

    public function getStatusList()
    {
        return [
            'draft' => '草稿',
            'pending' => '待审批',
            'published' => '已发布',
            'expired' => '已失效',
        ];
    }

    public function getMarketScopeList()
    {
        return [
            'domestic' => '国内',
            'international' => '国际',
            'all' => '全部',
        ];
    }

    public function getTaxModeList()
    {
        return [
            'tax_exclusive' => '不含税',
            'tax_inclusive' => '含税',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function getMarketScopeTextAttr($value, $data)
    {
        $scope = $data['market_scope'] ?? '';
        return $this->getMarketScopeList()[$scope] ?? $scope;
    }

    public function getTaxModeTextAttr($value, $data)
    {
        $mode = $data['tax_mode'] ?? '';
        return $this->getTaxModeList()[$mode] ?? $mode;
    }

    public function setEffectiveDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function setExpiryDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }
}
