<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

/**
 * CPQ 审批委托与代理服务（M3，P75）。
 *
 * 规则（方案 §4.6/P75）：
 *  - 委托须审批通过后生效（pending → active），可撤销；
 *  - 代理关系不能突破代理人原有数据权限（处理动作时重复校验产品线范围）；
 *  - 报价创建人与审批人分离规则仍然有效（ApprovalService::act 内强校验）；
 *  - 时间窗与产品线范围决定某条任务是否可由代理人处理。
 */
class ApprovalDelegationService
{
    const BUSINESS_APPROVAL = 'approval';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';

    /** 可审批委托的角色（审批管理权限） */
    const APPROVE_ROLES = ['system_admin', 'master_data_admin', 'company_pricer'];

    /**
     * 发起委托申请。
     */
    public function create(array $data, $delegatorId)
    {
        $delegateId = (int)($data['delegate_id'] ?? 0);
        $productLine = trim((string)($data['product_line'] ?? ''));
        $startsAt = (int)($data['starts_at'] ?? 0);
        $endsAt = (int)($data['ends_at'] ?? 0);
        $reason = mb_substr(trim((string)($data['reason'] ?? '')), 0, 500);
        $businessType = self::BUSINESS_APPROVAL;

        if ($delegateId <= 0) {
            throw new InvalidArgumentException('请选择代理人');
        }
        if ($delegateId === (int)$delegatorId) {
            throw new InvalidArgumentException('代理人不能是委托人本人');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('请填写委托原因');
        }
        if ($startsAt <= 0 || $endsAt <= 0 || $endsAt <= $startsAt) {
            throw new InvalidArgumentException('委托时间区间无效（结束时间须晚于开始时间）');
        }
        $delegate = Db::name('admin')->where('id', $delegateId)->where('status', 'normal')->find();
        if (!$delegate) {
            throw new InvalidArgumentException('代理人不存在或已禁用');
        }
        // 代理不突破数据权限：代理人必须具备该产品线（或全部产品线）的数据范围
        $scope = ProductLineScopeService::forAdmin($delegateId);
        if ($productLine !== '') {
            $scope->assertLineAllowed($productLine, '代理人无该产品线的数据权限，委托不能超越其原有权限');
        }

        // 同委托人存在时间窗重叠的生效中/待审批委托 → 拒绝
        $overlap = Db::name('cpq_approval_delegation')
            ->where('delegator_id', (int)$delegatorId)
            ->where('delegate_id', $delegateId)
            ->where('business_type', $businessType)
            ->where('status', 'in', [self::STATUS_PENDING, self::STATUS_ACTIVE])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->count();
        if ($overlap > 0) {
            throw new InvalidArgumentException('已存在同代理人在该时间段的委托');
        }

        $now = time();
        $id = Db::name('cpq_approval_delegation')->insertGetId([
            'delegator_id' => (int)$delegatorId,
            'delegate_id' => $delegateId,
            'business_type' => $businessType,
            'product_line' => $productLine,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $reason,
            'status' => self::STATUS_PENDING,
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        return ['id' => (int)$id];
    }

    /**
     * 审批委托（生效/拒绝），仅具备审批管理权限的角色可操作。
     */
    public function approve($delegationId, $adminId, $approve = true)
    {
        $row = $this->load((int)$delegationId);
        if ($row['status'] !== self::STATUS_PENDING) {
            throw new RuntimeException('仅待审批的委托可以审批');
        }
        $roles = SensitiveFieldService::rolesOfAdmin((int)$adminId);
        if (!array_intersect($roles, self::APPROVE_ROLES)) {
            throw new RuntimeException('无委托审批权限');
        }
        Db::name('cpq_approval_delegation')->where('id', (int)$row['id'])->update([
            'status' => $approve ? self::STATUS_ACTIVE : self::STATUS_REJECTED,
            'updatetime' => time(),
        ]);
        (new AuditLogService())->record('update', 'cpq_approval_delegation', (int)$row['id'], [
            'delegation_status' => $approve ? self::STATUS_ACTIVE : self::STATUS_REJECTED,
            'delegator_id' => (int)$row['delegator_id'],
            'delegate_id' => (int)$row['delegate_id'],
        ]);
        return ['id' => (int)$row['id'], 'status' => $approve ? self::STATUS_ACTIVE : self::STATUS_REJECTED];
    }

    /**
     * 撤销委托：委托人本人（或具备审批管理权限的角色）可撤销生效中/待审批的委托。
     */
    public function cancel($delegationId, $adminId)
    {
        $row = $this->load((int)$delegationId);
        if (!in_array($row['status'], [self::STATUS_PENDING, self::STATUS_ACTIVE], true)) {
            throw new RuntimeException('该委托已结束，不能撤销');
        }
        $roles = SensitiveFieldService::rolesOfAdmin((int)$adminId);
        $isOwner = (int)$adminId === (int)$row['delegator_id'];
        if (!$isOwner && !array_intersect($roles, self::APPROVE_ROLES)) {
            throw new RuntimeException('仅委托人或具备委托审批权限的角色可撤销');
        }
        Db::name('cpq_approval_delegation')->where('id', (int)$row['id'])->update([
            'status' => self::STATUS_CANCELLED,
            'updatetime' => time(),
        ]);
        (new AuditLogService())->record('update', 'cpq_approval_delegation', (int)$row['id'], [
            'delegation_status' => self::STATUS_CANCELLED,
        ]);
        return ['id' => (int)$row['id'], 'status' => self::STATUS_CANCELLED];
    }

    /**
     * 解析有效代理：actor 是否 delegator 的当前有效代理人，返回委托人ID（无代理返回 0）。
     *
     * @param int    $delegatorId 任务审批人（委托人）
     * @param int    $actorId     实际处理人（代理人）
     * @param string $businessType
     * @param string $productLine 报价产品线（代理范围校验）
     * @return int
     */
    public function resolveDelegator($delegatorId, $actorId, $businessType, $productLine)
    {
        $now = time();
        $row = Db::name('cpq_approval_delegation')
            ->where('delegator_id', (int)$delegatorId)
            ->where('delegate_id', (int)$actorId)
            ->where('business_type', (string)$businessType)
            ->where('status', self::STATUS_ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->order('id desc')
            ->find();
        if (!$row) {
            return 0;
        }
        $line = trim((string)$row['product_line']);
        if ($line !== '' && $line !== trim((string)$productLine)) {
            return 0;
        }
        return (int)$row['delegator_id'];
    }

    /**
     * 我作为代理人的全部有效委托人（待办列表展示委托任务用）。
     * 返回 [delegator_id => [product_line scope]]
     */
    public function activeDelegationsOf($delegateId, $businessType = self::BUSINESS_APPROVAL)
    {
        $now = time();
        $rows = Db::name('cpq_approval_delegation')
            ->where('delegate_id', (int)$delegateId)
            ->where('business_type', (string)$businessType)
            ->where('status', self::STATUS_ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->select();
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['delegator_id']] = trim((string)$row['product_line']);
        }
        return $result;
    }

    /**
     * 待办列表用：我可作为代理处理的委托人 ID 列表（不限产品线，逐行再校验范围）。
     */
    public function activeDelegatorsOf($delegateId, $businessType = self::BUSINESS_APPROVAL)
    {
        return array_keys($this->activeDelegationsOf($delegateId, $businessType));
    }

    /**
     * 委托人×代理人是否覆盖指定产品线。
     */
    public function delegationCoversLine($delegatorId, $delegateId, $productLine, $businessType = self::BUSINESS_APPROVAL)
    {
        $line = trim((string)$productLine);
        foreach ($this->activeDelegationsOf($delegateId, $businessType) as $dId => $scope) {
            if ((int)$dId === (int)$delegatorId && ($scope === '' || $scope === $line)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 代理操作记录（P75：查看代理动作）。
     */
    public function delegatedActions($delegateId, $offset = 0, $limit = 20)
    {
        $base = Db::name('cpq_approval_action')
            ->where('actor_id', (int)$delegateId)
            ->where('delegate_from_id', '>', 0);
        // 本环境 PDO 下 clone+count 丢绑定参数（TP5 已知问题），计数与列表各建一次查询
        $total = Db::name('cpq_approval_action')
            ->where('actor_id', (int)$delegateId)
            ->where('delegate_from_id', '>', 0)
            ->count();
        $rows = $base->order('id desc')->limit((int)$offset, (int)$limit)->select();
        $delegatorIds = array_values(array_unique(array_map(function ($row) {
            return (int)$row['delegate_from_id'];
        }, $rows)));
        $names = $delegatorIds ? Db::name('admin')->where('id', 'in', $delegatorIds)->column('nickname', 'id') : [];
        foreach ($rows as &$row) {
            $row['delegator_name'] = isset($names[(int)$row['delegate_from_id']]) ? $names[(int)$row['delegate_from_id']] : '';
        }
        unset($row);
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * 委托列表（P75）：委托人=我 或 代理人=我（其他人只看被授权范围）。
     */
    public function myList($adminId, array $filters = [], $offset = 0, $limit = 20)
    {
        $roles = SensitiveFieldService::rolesOfAdmin((int)$adminId);
        $canSeeAll = (bool)array_intersect($roles, self::APPROVE_ROLES);
        $where = [];
        if (!$canSeeAll) {
            $where['d.delegator_id|d.delegate_id'] = ['=', (int)$adminId];
        }
        $base = Db::name('cpq_approval_delegation')->alias('d')->where($where);
        if (!empty($filters['status'])) {
            $base->where('d.status', (string)$filters['status']);
        }
        // 计数单独建查询（本环境 clone+count 丢绑定参数）
        $totalQuery = Db::name('cpq_approval_delegation')->alias('d')->where($where);
        if (!empty($filters['status'])) {
            $totalQuery->where('d.status', (string)$filters['status']);
        }
        $total = $totalQuery->count();
        $now = time();
        $rows = $base
            ->field('d.*')
            ->order('d.id desc')
            ->limit((int)$offset, (int)$limit)
            ->select();
        $adminIds = [];
        foreach ($rows as $row) {
            $adminIds[(int)$row['delegator_id']] = true;
            $adminIds[(int)$row['delegate_id']] = true;
        }
        $names = $adminIds ? Db::name('admin')->where('id', 'in', array_keys($adminIds))->column('nickname', 'id') : [];
        foreach ($rows as &$row) {
            $row['delegator_name'] = isset($names[(int)$row['delegator_id']]) ? $names[(int)$row['delegator_id']] : '';
            $row['delegate_name'] = isset($names[(int)$row['delegate_id']]) ? $names[(int)$row['delegate_id']] : '';
            // 展示态：生效中但时间窗已过 → 已过期
            $row['effective_status'] = $row['status'];
            if ($row['status'] === self::STATUS_ACTIVE && (int)$row['ends_at'] < $now) {
                $row['effective_status'] = self::STATUS_EXPIRED;
            }
        }
        unset($row);
        return ['total' => $total, 'rows' => $rows];
    }

    private function load($id)
    {
        $row = Db::name('cpq_approval_delegation')->where('id', (int)$id)->find();
        if (!$row) {
            throw new InvalidArgumentException('委托记录不存在');
        }
        return $row;
    }
}
