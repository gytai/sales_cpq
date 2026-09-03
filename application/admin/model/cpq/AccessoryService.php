<?php

namespace app\admin\model\cpq;

use think\Model;

class AccessoryService extends Model
{
    protected $name = 'cpq_accessory_service';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return ['normal' => '正常', 'hidden' => '隐藏'];
    }

    public function getStatusTextAttr($value, $data)
    {
        return $this->getStatusList()[$data['status'] ?? ''] ?? ($data['status'] ?? '');
    }
}
