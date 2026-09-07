<?php

namespace app\admin\model\cpq;

use think\Model;

class ReleaseVersion extends Model
{
    protected $name = 'cpq_release_version';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return [
            'pending' => '未生效',
            'published' => '已生效',
            'withdrawn' => '已撤回',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }
}
