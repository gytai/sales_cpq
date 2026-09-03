<?php

namespace app\admin\model\cpq;

use think\Model;

class ConfigRule extends Model
{
    protected $name = 'cpq_config_rule';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text', 'severity_text'];

    public function getStatusList()
    {
        return [
            'draft' => '草稿',
            'pending' => '待审批',
            'published' => '已发布',
            'expired' => '已失效',
        ];
    }

    public function getSeverityList()
    {
        return ['blocking' => '阻断', 'warning' => '警告'];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function getSeverityTextAttr($value, $data)
    {
        $severity = $data['severity'] ?? '';
        return $this->getSeverityList()[$severity] ?? $severity;
    }

    public function setModelIdAttr($value)
    {
        return $value === '' || $value === null ? null : (int)$value;
    }

    public function setEffectiveDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function setExpiryDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
