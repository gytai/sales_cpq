<?php

namespace app\admin\model\cpq;

use think\Model;

class ConfigTemplate extends Model
{
    protected $name = 'cpq_config_template';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return ['draft' => '草稿', 'pending' => '待审批', 'published' => '已发布', 'expired' => '已失效'];
    }

    public function getStatusTextAttr($value, $data)
    {
        return $this->getStatusList()[$data['status'] ?? ''] ?? ($data['status'] ?? '');
    }

    public function productModel()
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
