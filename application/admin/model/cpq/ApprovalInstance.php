<?php

namespace app\admin\model\cpq;

use think\Model;

/**
 * CPQ 审批实例（M3，GYTAI-72）。
 *
 * 每次报价提交（冻结版本）创建一条实例；quote_id + revision_no 唯一。
 * 状态机见 docs/cpq/state-machines.md §2 与 ApprovalService。
 */
class ApprovalInstance extends Model
{
    protected $name = 'cpq_approval_instance';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'active' => '进行中',
            'completed' => '已完成',
            'rejected' => '已驳回',
            'returned' => '已退回',
            'withdrawn' => '已撤回',
            'cancelled' => '已取消',
        ];
    }

    public function getNodeList()
    {
        return [
            'sales_confirm' => '销售确认',
            'line_approval' => '产线价格审批',
            'company_approval' => '公司价格审批',
        ];
    }

    public function getLevelList()
    {
        return ['none' => '无需审批', 'line' => '产线审批', 'company' => '公司审批'];
    }

    public function tasks()
    {
        return $this->hasMany(ApprovalTask::class, 'instance_id', 'id');
    }
}
