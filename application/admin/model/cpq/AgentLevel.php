<?php

namespace app\admin\model\cpq;

use think\Model;

class AgentLevel extends Model
{
    protected $name = 'cpq_agent_level';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'hidden' => '停用',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }
}
