<?php

namespace app\admin\model\cpq;

use think\Model;

class Agent extends Model
{
    protected $name = 'cpq_agent';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'disabled' => '停用',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function setAuthStartDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function setAuthEndDateAttr($value)
    {
        return $value === '' || $value === null ? null : $value;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function agentLevel()
    {
        return $this->belongsTo(AgentLevel::class, 'agent_level_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
