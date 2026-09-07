<?php

namespace app\admin\model\cpq;

use app\common\service\cpq\PricePolicyService;
use think\Model;

class PricePolicy extends Model
{
    protected $name = 'cpq_price_policy';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'target_type_text', 'market_scope_text'];

    protected static function init()
    {
        self::beforeWrite(function ($row) {
            // dimension_key 是派生值，禁止依赖页面或导入方自行维护。
            $row['dimension_key'] = PricePolicyService::dimensionKey($row->getData());
        });
    }

    public function getStatusList()
    {
        return [
            'draft' => '草稿',
            'pending' => '待审批',
            'published' => '已发布',
            'expired' => '已失效',
        ];
    }

    public function getTargetTypeList()
    {
        return [
            'model' => '产品型号',
            'option' => '配置选项',
            'accessory_service' => '配件服务',
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

    public function getTargetTypeTextAttr($value, $data)
    {
        $type = $data['target_type'] ?? '';
        return $this->getTargetTypeList()[$type] ?? $type;
    }

    public function getMarketScopeTextAttr($value, $data)
    {
        $scope = $data['market_scope'] ?? '';
        return $this->getMarketScopeList()[$scope] ?? $scope;
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
