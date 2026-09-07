<?php

namespace app\admin\model\cpq;

use think\Model;

class PriceEntry extends Model
{
    protected $name = 'cpq_price_entry';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['target_type_text'];

    public function getTargetTypeList()
    {
        return [
            'model' => '产品型号',
            'option' => '配置选项',
            'accessory_service' => '配件服务',
        ];
    }

    public function getTargetTypeTextAttr($value, $data)
    {
        $type = $data['target_type'] ?? '';
        return $this->getTargetTypeList()[$type] ?? $type;
    }
}
