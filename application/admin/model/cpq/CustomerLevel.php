<?php

namespace app\admin\model\cpq;

use think\Model;

class CustomerLevel extends Model
{
    protected $name = 'cpq_customer_level';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'market_scope_text'];

    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'hidden' => '停用',
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
}
