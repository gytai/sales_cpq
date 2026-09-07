<?php

namespace app\admin\model\cpq;

use think\Model;

class Customer extends Model
{
    protected $name = 'cpq_customer';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'type_text'];

    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'disabled' => '停用',
        ];
    }

    public function getTypeList()
    {
        return [
            'direct' => '直销',
            'terminal' => '代理终端',
            'other' => '其他',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function getTypeTextAttr($value, $data)
    {
        $type = $data['type'] ?? '';
        return $this->getTypeList()[$type] ?? $type;
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function customerLevel()
    {
        return $this->belongsTo(CustomerLevel::class, 'customer_level_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function salesOrg()
    {
        return $this->belongsTo(SalesOrg::class, 'sales_org_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
