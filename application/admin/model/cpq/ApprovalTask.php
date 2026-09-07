<?php

namespace app\admin\model\cpq;

use think\Model;

/**
 * CPQ 审批任务（M3，GYTAI-72）。
 *
 * 候选人任务（is_required=0）为或签：任一候选人处理即推进节点；
 * 加签任务（is_required=1）为会签：必须处理完毕节点才推进。
 */
class ApprovalTask extends Model
{
    protected $name = 'cpq_approval_task';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'pending' => '待处理',
            'completed' => '已处理',
            'rejected' => '已驳回',
            'returned' => '已退回',
            'transferred' => '已转交',
            'superseded' => '已被处理',
            'cancelled' => '已取消',
        ];
    }

    public function getActionList()
    {
        return [
            'confirm' => '销售确认',
            'approve' => '批准',
            'reject' => '驳回',
            'return' => '退回修改',
            'transfer' => '转交',
            'add_sign' => '加签',
        ];
    }

    public function instance()
    {
        return $this->belongsTo(ApprovalInstance::class, 'instance_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
