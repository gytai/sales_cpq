<?php

namespace app\admin\model\cpq;

use think\Model;

/**
 * CPQ 审批动作记录（M3，GYTAI-72）。只增不删，业务用户无删除路径。
 */
class ApprovalAction extends Model
{
    protected $name = 'cpq_approval_action';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = false;

    public function getActionList()
    {
        return [
            'confirm' => '销售确认',
            'approve' => '批准',
            'reject' => '驳回',
            'return' => '退回修改',
            'transfer' => '转交',
            'add_sign' => '加签',
            'withdraw' => '撤回',
            'urge' => '催办',
            'cancel' => '取消',
        ];
    }
}
