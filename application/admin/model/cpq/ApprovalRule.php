<?php

namespace app\admin\model\cpq;

use think\Model;

/**
 * CPQ 审批规则（M3，P74）。固定路径的节点候选人/角色/产品线/SLA 配置。
 * 正常价格路径的销售确认节点固定由报价负责人处理，不配置规则。
 */
class ApprovalRule extends Model
{
    protected $name = 'cpq_approval_rule';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    public function getStatusList()
    {
        return ['enabled' => '启用', 'disabled' => '停用'];
    }

    public function getNodeList()
    {
        return [
            'line_approval' => '产线价格审批',
            'company_approval' => '公司价格审批',
        ];
    }

    public function getRoleList()
    {
        return [
            'line_pricer' => '产线价格管理员',
            'company_pricer' => '公司价格管理员',
            'sales_manager' => '销售经理',
            'finance_reviewer' => '财务审核',
        ];
    }
}
