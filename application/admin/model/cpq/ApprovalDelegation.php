<?php

namespace app\admin\model\cpq;

use think\Model;

/**
 * CPQ 审批委托与代理（M3，P75）。
 * 代理关系不突破代理人原有数据权限；报价创建人与审批人分离规则仍然有效。
 */
class ApprovalDelegation extends Model
{
    protected $name = 'cpq_approval_delegation';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return [
            'pending' => '待审批',
            'active' => '生效中',
            'cancelled' => '已撤销',
            'rejected' => '已拒绝',
            'expired' => '已过期',
        ];
    }

    public function getBusinessList()
    {
        return ['approval' => '报价审批'];
    }
}
