<?php

namespace app\common\service\cpq;

use app\admin\model\cpq\Quote as QuoteModel;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use think\Request;

/**
 * CPQ 审批服务（M3，GYTAI-72，方案 §4.4/§5.2、P70-P74）。
 *
 * 固定三条审批路径（由定价引擎的整单最严格审批等级决定，不可编排）：
 *  - none    → 销售确认（报价负责人确认）→ 已批准；
 *  - line    → 产线价格审批 → 已批准；
 *  - company → 产线价格审批 → 公司价格审批 → 已批准。
 * 低于公司控制价在提交端即被阻断（PricingService::assertSubmittable）。
 *
 * 关键规则（服务端强校验，状态迁移与动作记录同一事务）：
 *  1. 审批人不能修改待审批报价，只能批准/驳回/退回/加签/转交；
 *  2. 报价创建人与最终特批人不得为同一人（候选解析时排除负责人与提交人）；
 *  3. 动作携带版本号校验与幂等键：旧版本任务自动失效，重复动作幂等返回；
 *  4. 候选人任务（is_required=0）或签，加签任务（is_required=1）会签；
 *  5. 委托代理不突破代理人原有产品线数据权限，代理处理记录委托来源。
 */
class ApprovalService
{
    const NODE_SALES_CONFIRM = 'sales_confirm';
    const NODE_LINE_APPROVAL = 'line_approval';
    const NODE_COMPANY_APPROVAL = 'company_approval';

    const INSTANCE_ACTIVE = 'active';
    const INSTANCE_COMPLETED = 'completed';
    const INSTANCE_REJECTED = 'rejected';
    const INSTANCE_RETURNED = 'returned';
    const INSTANCE_WITHDRAWN = 'withdrawn';
    const INSTANCE_CANCELLED = 'cancelled';

    const TASK_PENDING = 'pending';
    const TASK_COMPLETED = 'completed';
    const TASK_REJECTED = 'rejected';
    const TASK_RETURNED = 'returned';
    const TASK_TRANSFERRED = 'transferred';
    const TASK_SUPERSEDED = 'superseded';
    const TASK_CANCELLED = 'cancelled';

    const ACTION_CONFIRM = 'confirm';
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';
    const ACTION_RETURN = 'return';
    const ACTION_ADD_SIGN = 'add_sign';
    const ACTION_TRANSFER = 'transfer';
    const ACTION_WITHDRAW = 'withdraw';
    const ACTION_URGE = 'urge';
    const ACTION_CANCEL = 'cancel';

    /** 销售确认节点 SLA（小时）；该节点固定由报价负责人处理，不配置规则 */
    const SALES_CONFIRM_SLA_HOURS = 24;

    /** 三条固定路径：审批等级 → 顺序节点 */
    const FIXED_PATHS = [
        'none' => [self::NODE_SALES_CONFIRM],
        'line' => [self::NODE_LINE_APPROVAL],
        'company' => [self::NODE_LINE_APPROVAL, self::NODE_COMPANY_APPROVAL],
    ];

    /** @var ApprovalDelegationService */
    private $delegationService;

    public function __construct(ApprovalDelegationService $delegationService = null)
    {
        $this->delegationService = $delegationService ?: new ApprovalDelegationService();
    }

    // ------------------------------------------------------------------
    // 提交联动（由 QuoteRevisionService::submit 在同一事务内调用）
    // ------------------------------------------------------------------

    /**
     * 为已冻结的报价版本创建审批实例与首个节点任务。
     *
     * @param array $quote       报价行（含 final_approval_level/product_line/owner_id）
     * @param int   $revisionNo  冻结版本号
     * @param int   $initiatorId 提交人管理员ID
     * @return array 实例与任务摘要
     * @throws InvalidArgumentException 审批路径缺少可用审批人（fail-closed，提交回滚）
     */
    public function createForSubmittedQuote(array $quote, $revisionNo, $initiatorId)
    {
        $level = (string)$quote['final_approval_level'];
        if (!isset(self::FIXED_PATHS[$level])) {
            throw new InvalidArgumentException('未知审批等级：' . $level);
        }
        $path = self::FIXED_PATHS[$level];
        $now = time();

        $existing = Db::name('cpq_approval_instance')
            ->where('quote_id', (int)$quote['id'])
            ->where('revision_no', (int)$revisionNo)
            ->find();
        if ($existing) {
            return $this->instanceSummary($existing['id']);
        }

        $instanceId = Db::name('cpq_approval_instance')->insertGetId([
            'quote_id' => (int)$quote['id'],
            'revision_no' => (int)$revisionNo,
            'approval_level' => $level,
            'status' => self::INSTANCE_ACTIVE,
            'current_node' => $path[0],
            'initiator_id' => (int)$initiatorId,
            'sla_deadline' => null,
            'submitted_at' => $now,
            'createtime' => $now,
            'updatetime' => $now,
        ]);

        $this->createNodeTasks($instanceId, $quote, $path[0], $revisionNo, $initiatorId, $now);

        Db::name('cpq_audit_log')->insertGetId([
            'trace_id' => bin2hex(random_bytes(12)),
            'user_id' => (int)$initiatorId,
            'action' => 'submit',
            'object_type' => 'cpq_approval_instance',
            'object_id' => $instanceId,
            'object_code' => (string)$quote['code'],
            'detail_json' => json_encode([
                'revision_no' => (int)$revisionNo,
                'approval_level' => $level,
                'path' => $path,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => '',
            'createtime' => $now,
        ]);

        return $this->instanceSummary($instanceId);
    }

    /**
     * 撤回联动：实例 withdrawn，待办任务取消（QuoteRevisionService::withdraw 同事务调用）。
     *
     * @param int $quoteId
     * @param int $adminId
     */
    public function cancelOnWithdraw($quoteId, $adminId)
    {
        $instance = $this->activeInstance($quoteId);
        if (!$instance) {
            return;
        }
        // 撤回仅发起人可操作（报价负责人视为发起人）
        if ((int)$adminId !== (int)$instance['initiator_id']) {
            $quote = Db::name('cpq_quote')->where('id', (int)$quoteId)->find();
            if (!$quote || (int)$adminId !== (int)$quote['owner_id']) {
                throw new RuntimeException('撤回仅报价发起人可操作');
            }
        }
        $this->terminateInstance($instance, self::INSTANCE_WITHDRAWN, self::ACTION_WITHDRAW, $adminId, '发起人撤回');
    }

    /**
     * 修订联动：提交新版本前取消旧版本的审批任务（旧版本任务自动失效）。
     *
     * @param int $quoteId
     * @param int $adminId
     */
    public function cancelOnRevision($quoteId, $adminId)
    {
        $instance = $this->activeInstance($quoteId);
        if (!$instance) {
            return;
        }
        $this->terminateInstance($instance, self::INSTANCE_CANCELLED, self::ACTION_CANCEL, $adminId, '报价修订，旧版本任务失效');
    }

    // ------------------------------------------------------------------
    // 审批动作（幂等 + 版本校验 + 权限校验 + 同事务动作记录）
    // ------------------------------------------------------------------

    /**
     * 执行审批动作。
     *
     * @param int    $taskId  审批任务ID
     * @param int    $actorId 实际处理人（本人或有效代理人）
     * @param string $action  confirm/approve/reject/return/transfer/add_sign
     * @param array  $options comment/reason_category/idempotency_key/next_assignee_id/ip
     * @return array 动作结果（幂等命中时返回既有结果）
     */
    public function act($taskId, $actorId, $action, array $options = [])
    {
        $taskId = (int)$taskId;
        $actorId = (int)$actorId;
        $comment = mb_substr(trim((string)($options['comment'] ?? '')), 0, 1000);
        $reasonCategory = mb_substr(trim((string)($options['reason_category'] ?? '')), 0, 64);
        $idempotencyKey = trim((string)($options['idempotency_key'] ?? ''));
        $nextAssigneeId = (int)($options['next_assignee_id'] ?? 0);
        $ip = (string)($options['ip'] ?? '');

        $task = Db::name('cpq_approval_task')->where('id', $taskId)->find();
        if (!$task) {
            throw new InvalidArgumentException('审批任务不存在');
        }
        $quote = Db::name('cpq_quote')->where('id', (int)$task['quote_id'])->find();

        // 幂等：同幂等键动作已存在 → 返回既有结果（重复操作不产生二次效果）
        if ($idempotencyKey !== '') {
            $existing = Db::name('cpq_approval_action')
                ->where('idempotency_key', $idempotencyKey)
                ->find();
            if ($existing) {
                return [
                    'idempotent' => true,
                    'action' => $existing['action'],
                    'instance_status' => $this->instanceStatusOf($existing['instance_id']),
                    'task_status' => $task['status'],
                    'action_id' => (int)$existing['id'],
                ];
            }
        }

        // 权限：本人 或 有效代理人（代理关系覆盖业务类型/产品线/时间窗）
        $delegateFromId = 0;
        if ((int)$task['assignee_id'] !== $actorId) {
            $delegateFromId = $this->delegationService->resolveDelegator(
                (int)$task['assignee_id'], $actorId, 'approval', (string)($quote ? $quote['product_line'] : ''));
            if ($delegateFromId === 0) {
                throw new RuntimeException('无权处理该审批任务（仅任务审批人或其有效代理人可操作）');
            }
        }

        // 状态前置：任务与实例均须待处理
        if ($task['status'] !== self::TASK_PENDING) {
            throw new RuntimeException('该任务已被处理或已失效，请刷新列表');
        }
        $instance = Db::name('cpq_approval_instance')->where('id', (int)$task['instance_id'])->find();
        if (!$instance || $instance['status'] !== self::INSTANCE_ACTIVE) {
            throw new RuntimeException('审批流程已结束，任务失效');
        }

        // 版本校验：任务版本必须等于报价当前版本且报价仍处于已提交态
        if (!$quote
            || (int)$quote['current_revision_no'] !== (int)$task['revision_no']
            || $quote['status'] !== QuoteModel::STATUS_SUBMITTED) {
            // 版本已变化：旧任务自动失效（CANCELLED_BY_REVISION 语义）
            Db::startTrans();
            try {
                $this->closeTask($task, self::TASK_CANCELLED, 'version_changed', '');
                $this->insertAction($instance, $task, self::ACTION_CANCEL, $actorId, $delegateFromId,
                    '报价版本已变化，任务自动失效', $idempotencyKey, $ip, $quote ? $quote['final_price_hash'] : '');
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }
            throw new RuntimeException('审批任务已失效：报价版本已变化，请等待新版本重新提交');
        }

        // 职责分离：报价负责人/提交人不可处理特批节点任务（销售确认节点除外）
        if ($task['node'] !== self::NODE_SALES_CONFIRM
            && ($actorId === (int)$quote['owner_id'] || $actorId === (int)$instance['initiator_id'])) {
            throw new RuntimeException('报价创建人与审批人必须分离，不能处理本人报价的审批任务');
        }

        $actorName = (string)(Db::name('admin')->where('id', $actorId)->value('nickname') ?: ('admin#' . $actorId));
        $beforeHash = (string)$quote['final_price_hash'];

        Db::startTrans();
        try {
            switch ($action) {
                case self::ACTION_CONFIRM:
                case self::ACTION_APPROVE:
                    $result = $this->doApprove($instance, $task, $quote, $actorId, $actorName, $delegateFromId,
                        $comment, $reasonCategory, $idempotencyKey, $ip, $beforeHash);
                    break;
                case self::ACTION_REJECT:
                    $result = $this->doReject($instance, $task, $quote, $actorId, $actorName, $delegateFromId,
                        $comment, $reasonCategory, $idempotencyKey, $ip, $beforeHash);
                    break;
                case self::ACTION_RETURN:
                    $result = $this->doReturn($instance, $task, $quote, $actorId, $actorName, $delegateFromId,
                        $comment, $reasonCategory, $idempotencyKey, $ip, $beforeHash);
                    break;
                case self::ACTION_TRANSFER:
                    $result = $this->doTransfer($instance, $task, $quote, $actorId, $actorName, $delegateFromId,
                        $comment, $nextAssigneeId, $idempotencyKey, $ip, $beforeHash);
                    break;
                case self::ACTION_ADD_SIGN:
                    $result = $this->doAddSign($instance, $task, $quote, $actorId, $actorName, $delegateFromId,
                        $comment, $nextAssigneeId, $idempotencyKey, $ip, $beforeHash);
                    break;
                default:
                    throw new InvalidArgumentException('不支持的审批动作：' . $action);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return $result;
    }

    /**
     * 催办：记录动作（不改变任务状态），待办与详情页展示。
     */
    public function urge($taskId, $actorId)
    {
        $task = Db::name('cpq_approval_task')->where('id', (int)$taskId)->find();
        if (!$task) {
            throw new InvalidArgumentException('审批任务不存在');
        }
        $instance = Db::name('cpq_approval_instance')->where('id', (int)$task['instance_id'])->find();
        if (!$instance || $instance['status'] !== self::INSTANCE_ACTIVE || $task['status'] !== self::TASK_PENDING) {
            throw new RuntimeException('仅进行中的待办任务可以催办');
        }
        $quote = Db::name('cpq_quote')->where('id', (int)$task['quote_id'])->find();
        // 越权防护：仅发起人或报价负责人可催办（审批人处理自己的任务无需催办）
        $isInitiator = (int)$instance['initiator_id'] === (int)$actorId;
        $isOwner = $quote && (int)$quote['owner_id'] === (int)$actorId;
        if (!$isInitiator && !$isOwner) {
            throw new RuntimeException('无权催办该任务');
        }
        Db::name('cpq_approval_action')->insertGetId([
            'instance_id' => (int)$instance['id'],
            'task_id' => (int)$task['id'],
            'quote_id' => (int)$task['quote_id'],
            'revision_no' => (int)$task['revision_no'],
            'node' => (string)$task['node'],
            'action' => self::ACTION_URGE,
            'actor_id' => (int)$actorId,
            'actor_name' => (string)(Db::name('admin')->where('id', (int)$actorId)->value('nickname') ?: ''),
            'delegate_from_id' => 0,
            'comment' => '催办提醒',
            'reason_category' => '',
            'before_hash' => $quote ? (string)$quote['final_price_hash'] : '',
            'after_hash' => $quote ? (string)$quote['final_price_hash'] : '',
            'idempotency_key' => null,
            'ip' => '',
            'createtime' => time(),
        ]);
        return ['urged' => true, 'task_id' => (int)$task['id']];
    }

    // ------------------------------------------------------------------
    // 动作实现
    // ------------------------------------------------------------------

    private function doApprove(array $instance, array $task, array $quote, $actorId, $actorName, $delegateFromId,
                                $comment, $reasonCategory, $idempotencyKey, $ip, $beforeHash)
    {
        $action = $task['node'] === self::NODE_SALES_CONFIRM ? self::ACTION_CONFIRM : self::ACTION_APPROVE;
        $now = time();
        $this->closeTask($task, self::TASK_COMPLETED, $action, $comment, $reasonCategory, $actorId, $now);

        // 节点推进：会签任务（is_required=1）须全部处理完毕，候选人任务（或签）任一处理即计入
        $nodeComplete = $this->isNodeComplete((int)$instance['id'], $task['node']);
        $path = self::FIXED_PATHS[(string)$instance['approval_level']];
        $nextIndex = array_search($task['node'], $path, true) + 1;
        $afterHash = $beforeHash;

        if ($nodeComplete && $nextIndex < count($path)) {
            // 进入下一节点（产线 → 公司）
            $nextNode = $path[$nextIndex];
            $this->createNodeTasks((int)$instance['id'], $quote, $nextNode, (int)$instance['revision_no'],
                (int)$instance['initiator_id'], $now, true);
            $actionId = $this->insertAction($instance, $task, $action, $actorId, $delegateFromId, $comment,
                $idempotencyKey, $ip, $beforeHash, $afterHash, $actorName, $reasonCategory);
            return [
                'idempotent' => false,
                'action' => $action,
                'node' => $task['node'],
                'instance_status' => self::INSTANCE_ACTIVE,
                'task_status' => self::TASK_COMPLETED,
                'next_node' => $nextNode,
                'action_id' => $actionId,
            ];
        }

        $instanceStatus = self::INSTANCE_ACTIVE;
        if ($nodeComplete) {
            // 全部节点完成 → 报价批准
            $instanceStatus = self::INSTANCE_COMPLETED;
            Db::name('cpq_approval_instance')->where('id', (int)$instance['id'])->update([
                'status' => self::INSTANCE_COMPLETED,
                'current_node' => '',
                'completed_at' => $now,
                'updatetime' => $now,
            ]);
            Db::name('cpq_quote')->where('id', (int)$quote['id'])->update([
                'status' => QuoteModel::STATUS_APPROVED,
                'approved_at' => $now,
                'optimistic_lock_version' => (int)$quote['optimistic_lock_version'] + 1,
                'updatetime' => $now,
            ]);
            Db::name('cpq_quote_revision')
                ->where('quote_id', (int)$quote['id'])
                ->where('revision_no', (int)$instance['revision_no'])
                ->update(['status' => 'approved', 'updatetime' => $now]);
            Db::name('cpq_audit_log')->insertGetId([
                'trace_id' => bin2hex(random_bytes(12)),
                'user_id' => (int)$actorId,
                'action' => 'approve',
                'object_type' => 'cpq_quote',
                'object_id' => (int)$quote['id'],
                'object_code' => (string)$quote['code'],
                'detail_json' => json_encode([
                    'revision_no' => (int)$instance['revision_no'],
                    'approval_level' => (string)$instance['approval_level'],
                    'delegate_from' => $delegateFromId ?: null,
                ], JSON_UNESCAPED_UNICODE),
                'ip' => '',
                'createtime' => $now,
            ]);
        }
        $actionId = $this->insertAction($instance, $task, $action, $actorId, $delegateFromId, $comment,
            $idempotencyKey, $ip, $beforeHash, $afterHash, $actorName, $reasonCategory);
        return [
            'idempotent' => false,
            'action' => $action,
            'node' => $task['node'],
            'instance_status' => $instanceStatus,
            'task_status' => self::TASK_COMPLETED,
            'next_node' => null,
            'quote_status' => $nodeComplete ? QuoteModel::STATUS_APPROVED : null,
            'action_id' => $actionId,
        ];
    }

    private function doReject(array $instance, array $task, array $quote, $actorId, $actorName, $delegateFromId,
                               $comment, $reasonCategory, $idempotencyKey, $ip, $beforeHash)
    {
        if ($comment === '') {
            throw new InvalidArgumentException('驳回必须填写审批意见');
        }
        $now = time();
        $this->closeTask($task, self::TASK_REJECTED, self::ACTION_REJECT, $comment, $reasonCategory, $actorId, $now);
        // 同节点其余待办任务被本次处理取代
        $this->supersedeSiblingPendingTasks((int)$instance['id'], $task, $actorId);

        Db::name('cpq_approval_instance')->where('id', (int)$instance['id'])->update([
            'status' => self::INSTANCE_REJECTED,
            'completed_at' => $now,
            'updatetime' => $now,
        ]);
        Db::name('cpq_quote')->where('id', (int)$quote['id'])->update([
            'status' => QuoteModel::STATUS_REJECTED,
            'optimistic_lock_version' => (int)$quote['optimistic_lock_version'] + 1,
            'updatetime' => $now,
        ]);
        $actionId = $this->insertAction($instance, $task, self::ACTION_REJECT, $actorId, $delegateFromId, $comment,
            $idempotencyKey, $ip, $beforeHash, $beforeHash, $actorName, $reasonCategory);
        return [
            'idempotent' => false,
            'action' => self::ACTION_REJECT,
            'instance_status' => self::INSTANCE_REJECTED,
            'task_status' => self::TASK_REJECTED,
            'quote_status' => QuoteModel::STATUS_REJECTED,
            'action_id' => $actionId,
        ];
    }

    private function doReturn(array $instance, array $task, array $quote, $actorId, $actorName, $delegateFromId,
                              $comment, $reasonCategory, $idempotencyKey, $ip, $beforeHash)
    {
        if ($comment === '') {
            throw new InvalidArgumentException('退回修改必须填写退回原因');
        }
        $now = time();
        $this->closeTask($task, self::TASK_RETURNED, self::ACTION_RETURN, $comment, $reasonCategory, $actorId, $now);
        $this->supersedeSiblingPendingTasks((int)$instance['id'], $task, $actorId);

        Db::name('cpq_approval_instance')->where('id', (int)$instance['id'])->update([
            'status' => self::INSTANCE_RETURNED,
            'completed_at' => $now,
            'updatetime' => $now,
        ]);
        // 退回修改：回到可编辑态，重新提交产生新版本
        Db::name('cpq_quote')->where('id', (int)$quote['id'])->update([
            'status' => 'returned',
            'optimistic_lock_version' => (int)$quote['optimistic_lock_version'] + 1,
            'updatetime' => $now,
        ]);
        $actionId = $this->insertAction($instance, $task, self::ACTION_RETURN, $actorId, $delegateFromId, $comment,
            $idempotencyKey, $ip, $beforeHash, $beforeHash, $actorName, $reasonCategory);
        return [
            'idempotent' => false,
            'action' => self::ACTION_RETURN,
            'instance_status' => self::INSTANCE_RETURNED,
            'task_status' => self::TASK_RETURNED,
            'quote_status' => 'returned',
            'action_id' => $actionId,
        ];
    }

    private function doTransfer(array $instance, array $task, array $quote, $actorId, $actorName, $delegateFromId,
                                $comment, $nextAssigneeId, $idempotencyKey, $ip, $beforeHash)
    {
        $next = $this->assertValidAssignee((int)$nextAssigneeId, $quote, $instance, $task);
        if ((int)$next['id'] === (int)$task['assignee_id']) {
            throw new InvalidArgumentException('转交对象不能是当前审批人');
        }
        if ($comment === '') {
            throw new InvalidArgumentException('转交必须填写转交说明');
        }
        $now = time();
        $this->closeTask($task, self::TASK_TRANSFERRED, self::ACTION_TRANSFER, $comment, '', $actorId, $now);
        $newTaskId = Db::name('cpq_approval_task')->insertGetId([
            'instance_id' => (int)$instance['id'],
            'quote_id' => (int)$task['quote_id'],
            'revision_no' => (int)$task['revision_no'],
            'node' => (string)$task['node'],
            'assignee_id' => (int)$next['id'],
            'is_required' => 0,
            'status' => self::TASK_PENDING,
            'arrived_at' => $now,
            'sla_deadline' => $task['sla_deadline'] ?: $this->nodeSlaDeadline($instance, $task['node']),
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        $actionId = $this->insertAction($instance, $task, self::ACTION_TRANSFER, $actorId, $delegateFromId, $comment,
            $idempotencyKey, $ip, $beforeHash, $beforeHash, $actorName, '', (int)$newTaskId);
        return [
            'idempotent' => false,
            'action' => self::ACTION_TRANSFER,
            'instance_status' => self::INSTANCE_ACTIVE,
            'task_status' => self::TASK_TRANSFERRED,
            'new_task_id' => $newTaskId,
            'next_assignee' => $next['nickname'],
            'action_id' => $actionId,
        ];
    }

    private function doAddSign(array $instance, array $task, array $quote, $actorId, $actorName, $delegateFromId,
                               $comment, $nextAssigneeId, $idempotencyKey, $ip, $beforeHash)
    {
        $next = $this->assertValidAssignee((int)$nextAssigneeId, $quote, $instance, $task);
        if ((int)$next['id'] === (int)$task['assignee_id']) {
            throw new InvalidArgumentException('加签对象不能是当前审批人');
        }
        if ($comment === '') {
            throw new InvalidArgumentException('加签必须填写加签说明');
        }
        $now = time();
        // 加签任务为会签必办：被加签人处理完毕后节点才推进，当前任务保持待处理
        $newTaskId = Db::name('cpq_approval_task')->insertGetId([
            'instance_id' => (int)$instance['id'],
            'quote_id' => (int)$task['quote_id'],
            'revision_no' => (int)$task['revision_no'],
            'node' => (string)$task['node'],
            'assignee_id' => (int)$next['id'],
            'is_required' => 1,
            'status' => self::TASK_PENDING,
            'arrived_at' => $now,
            'sla_deadline' => $task['sla_deadline'] ?: $this->nodeSlaDeadline($instance, $task['node']),
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        $actionId = $this->insertAction($instance, $task, self::ACTION_ADD_SIGN, $actorId, $delegateFromId, $comment,
            $idempotencyKey, $ip, $beforeHash, $beforeHash, $actorName, '', (int)$newTaskId);
        return [
            'idempotent' => false,
            'action' => self::ACTION_ADD_SIGN,
            'instance_status' => self::INSTANCE_ACTIVE,
            'task_status' => self::TASK_PENDING,
            'new_task_id' => $newTaskId,
            'next_assignee' => $next['nickname'],
            'action_id' => $actionId,
        ];
    }

    // ------------------------------------------------------------------
    // 候选人解析（固定路径 + 产品线数据范围 + 职责分离）
    // ------------------------------------------------------------------

    /**
     * 解析指定节点的候选人。
     *
     * 销售确认节点：报价负责人（提交人）；
     * 产线/公司节点：审批规则（角色成员 ∪ 显式候选人）∩ 产品线数据范围，排除报价负责人与提交人。
     *
     * @param string $node
     * @param array  $quote
     * @param int    $initiatorId
     * @return array [adminId => nickname]
     * @throws InvalidArgumentException 无可用审批人（fail-closed）
     */
    public function resolveCandidates($node, array $quote, $initiatorId)
    {
        if ($node === self::NODE_SALES_CONFIRM) {
            $ownerId = (int)$quote['owner_id'] ?: (int)$initiatorId;
            $owner = Db::name('admin')->where('id', $ownerId)->where('status', 'normal')->find();
            if (!$owner) {
                throw new InvalidArgumentException('审批路径缺少可用审批人：报价负责人不存在或已禁用');
            }
            return [(int)$owner['id'] => (string)$owner['nickname']];
        }

        $rule = $this->matchRule($node, (string)$quote['product_line']);
        if (!$rule) {
            throw new InvalidArgumentException('审批路径缺少可用审批人：未配置' . $this->nodeText($node) . '审批规则');
        }

        $candidateIds = [];
        // 显式候选人
        $explicit = json_decode((string)($rule['candidate_admin_ids'] ?? ''), true);
        if (is_array($explicit)) {
            foreach ($explicit as $id) {
                if ((int)$id > 0) {
                    $candidateIds[(int)$id] = true;
                }
            }
        }
        // 角色成员（fa_auth_group.name 与 CPQ 角色编码精确匹配）
        if ((string)$rule['approver_role'] !== '') {
            $roleIds = Db::name('auth_group_access')
                ->alias('access')
                ->join('__ADMIN__ admin', 'admin.id = access.uid')
                ->join('__AUTH_GROUP__ auth_group', 'auth_group.id = access.group_id')
                ->where('auth_group.name', (string)$rule['approver_role'])
                ->where('auth_group.status', 'normal')
                ->where('admin.status', 'normal')
                ->column('admin.id');
            foreach ($roleIds as $id) {
                $candidateIds[(int)$id] = true;
            }
        }
        if (!$candidateIds) {
            throw new InvalidArgumentException('审批路径缺少可用审批人：' . $this->nodeText($node) . '无候选人');
        }

        // 职责分离（报价负责人/提交人不可审批自己的报价）+ 产品线数据范围
        $ownerId = (int)$quote['owner_id'];
        $initiatorId = (int)$initiatorId;
        $productLine = (string)$quote['product_line'];
        $candidates = [];
        foreach (array_keys($candidateIds) as $adminId) {
            if ($adminId === $ownerId || $adminId === $initiatorId) {
                continue;
            }
            $admin = Db::name('admin')->where('id', $adminId)->where('status', 'normal')->find();
            if (!$admin) {
                continue;
            }
            $scope = ProductLineScopeService::forAdmin($adminId);
            if (!$scope->isLineAllowed($productLine)) {
                continue;
            }
            $candidates[$adminId] = (string)$admin['nickname'];
        }
        if (!$candidates) {
            throw new InvalidArgumentException(
                '审批路径缺少可用审批人：' . $this->nodeText($node) . '候选人在职责分离或产品线数据范围过滤后为空'
            );
        }
        return $candidates;
    }

    /**
     * 匹配审批规则：优先精确产品线，其次通配产品线；仅启用状态参与。
     */
    public function matchRule($node, $productLine)
    {
        $rules = Db::name('cpq_approval_rule')
            ->where('node', $node)
            ->where('status', 'enabled')
            ->order('product_line desc')
            ->select();
        $fallback = null;
        foreach ($rules as $rule) {
            $line = trim((string)$rule['product_line']);
            if ($line === '') {
                $fallback = $rule;
            } elseif ($line === $productLine) {
                return $rule;
            }
        }
        return $fallback;
    }

    // ------------------------------------------------------------------
    // 查询（P02/P70/P72 页面数据）
    // ------------------------------------------------------------------

    /**
     * 我的审批任务列表（待处理/已处理/抄送我的）。
     *
     * @param int    $adminId
     * @param string $category pending|processed|cc
     * @param array  $filters product_line/node/status
     * @param int    $offset
     * @param int    $limit
     * @return array ['total' => int, 'rows' => array]
     */
    public function myTaskList($adminId, $category = 'pending', array $filters = [], $offset = 0, $limit = 20)
    {
        $now = time();
        $taskTable = 'cpq_approval_task';
        $where = [];
        $delegationScopes = [];
        if ($category === 'pending') {
            // 本人的待办 + 有效代理的待办（委托人任务，代理人可见可处理；产品线范围逐行校验）
            $delegationScopes = $this->delegationService->activeDelegationsOf($adminId, 'approval');
            $assigneeIds = array_merge([(int)$adminId], array_keys($delegationScopes));
            $where['t.assignee_id'] = ['in', $assigneeIds];
            $where['t.status'] = ['=', self::TASK_PENDING];
        } elseif ($category === 'processed') {
            $where['t.assignee_id'] = ['=', (int)$adminId];
            $where['t.status'] = ['in', [self::TASK_COMPLETED, self::TASK_REJECTED, self::TASK_RETURNED, self::TASK_TRANSFERRED]];
        } else { // cc：被转交/加签给我的任务（来源标记）
            $where['t.assignee_id'] = ['=', (int)$adminId];
        }

        if (!empty($filters['node'])) {
            $where['t.node'] = ['=', (string)$filters['node']];
        }

        $base = Db::name($taskTable)->alias('t')
            ->join('__CPQ_APPROVAL_INSTANCE__ i', 'i.id = t.instance_id', 'LEFT')
            ->join('__CPQ_QUOTE__ q', 'q.id = t.quote_id', 'LEFT')
            ->join('__CPQ_CUSTOMER__ c', 'c.id = q.customer_id', 'LEFT')
            ->join('__ADMIN__ a', 'a.id = t.assignee_id', 'LEFT')
            ->where($where);
        if (!empty($filters['product_line'])) {
            $base->where('q.product_line', (string)$filters['product_line']);
        }
        if (!empty($filters['keyword'])) {
            $base->where('q.code|q.name', 'like', '%' . trim((string)$filters['keyword']) . '%');
        }

        $total = (clone $base)->count();
        $rows = (clone $base)
            ->field('t.*, i.status AS instance_status, i.approval_level, i.initiator_id, i.current_node,'
                . ' q.code AS quote_code, q.name AS quote_name, q.customer_id, q.currency, q.product_line,'
                . ' q.owner_id, q.final_approval_level, q.current_revision_no AS quote_revision_no, q.final_price_hash,'
                . ' c.name AS customer_name, a.nickname AS assignee_name')
            ->order('t.id desc')
            ->limit((int)$offset, (int)$limit)
            ->select();

        $initiatorIds = array_values(array_unique(array_filter(array_map(function ($row) {
            return (int)$row['initiator_id'];
        }, $rows))));
        $initiators = $initiatorIds ? Db::name('admin')->where('id', 'in', $initiatorIds)->column('nickname', 'id') : [];

        foreach ($rows as &$row) {
            $row['initiator_name'] = $initiators[(int)$row['initiator_id']] ?? '';
            $row['node_text'] = $this->nodeText($row['node']);
            $row['business_type'] = 'quote';
            $row['is_delegate_view'] = ((int)$row['assignee_id'] !== (int)$adminId) ? 1 : 0;
            $row['delegator_name'] = '';
            if ($row['is_delegate_view'] && $category === 'pending') {
                $row['delegator_name'] = (string)(Db::name('admin')->where('id', (int)$row['assignee_id'])->value('nickname') ?: '');
                // 代理范围校验：委托的产品线须覆盖该报价的产品线（空=全部）
                $scope = isset($delegationScopes[(int)$row['assignee_id']]) ? $delegationScopes[(int)$row['assignee_id']] : '';
                if ($scope !== '' && $scope !== (string)$row['product_line']) {
                    $row['_out_of_delegation_scope'] = true;
                }
            }
            // 抄送来源：被谁转交/加签
            if ($category === 'cc') {
                $source = Db::name('cpq_approval_action')
                    ->where('task_id', (int)$row['id'])
                    ->where('action', 'in', [self::ACTION_TRANSFER, self::ACTION_ADD_SIGN])
                    ->order('id desc')
                    ->find();
                $row['cc_source'] = $source ? ((string)$source['actor_name'] . ' / ' . $this->actionText($source['action'])) : '';
                $row['cc_source_action'] = $source ? (string)$source['action'] : '';
            }
            $row['sla_remaining'] = null;
            $row['overdue'] = 0;
            if ($row['status'] === self::TASK_PENDING && $row['sla_deadline']) {
                $row['sla_remaining'] = (int)$row['sla_deadline'] - $now;
                $row['overdue'] = $now > (int)$row['sla_deadline'] ? 1 : 0;
            }
            $row['totals'] = $this->revisionTotals((int)$row['quote_id'], (int)$row['revision_no']);
            $row['total_amount'] = isset($row['totals']['total']) ? (string)$row['totals']['total'] : '';
        }
        unset($row);

        if ($category === 'pending') {
            // 剔除超出代理产品线范围的任务
            $rows = array_values(array_filter($rows, function ($row) {
                return empty($row['_out_of_delegation_scope']);
            }));
        }
        if ($category === 'cc') {
            // 抄送列表仅保留确实被转交/加签给我的任务
            $rows = array_values(array_filter($rows, function ($row) {
                return $row['cc_source_action'] !== '';
            }));
            $total = count($rows);
        }
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * 我发起的审批（P72）。
     */
    public function initiatedList($adminId, array $filters = [], $offset = 0, $limit = 20)
    {
        $where = ['i.initiator_id' => ['=', (int)$adminId]];
        if (!empty($filters['status'])) {
            $where['i.status'] = ['=', (string)$filters['status']];
        }
        $base = Db::name('cpq_approval_instance')->alias('i')
            ->join('__CPQ_QUOTE__ q', 'q.id = i.quote_id', 'LEFT')
            ->join('__CPQ_CUSTOMER__ c', 'c.id = q.customer_id', 'LEFT')
            ->where($where);
        if (!empty($filters['keyword'])) {
            $base->where('q.code|q.name', 'like', '%' . trim((string)$filters['keyword']) . '%');
        }
        $total = (clone $base)->count();
        $rows = (clone $base)
            ->field('i.*, q.code AS quote_code, q.name AS quote_name, q.status AS quote_status, q.product_line,'
                . ' q.currency, q.current_revision_no, c.name AS customer_name')
            ->order('i.id desc')
            ->limit((int)$offset, (int)$limit)
            ->select();

        $now = time();
        foreach ($rows as &$row) {
            $row['node_text'] = $row['current_node'] ? $this->nodeText($row['current_node']) : '';
            $row['status_text'] = (new \app\admin\model\cpq\ApprovalInstance())->getStatusList()[$row['status']] ?? $row['status'];
            $row['current_handlers'] = '';
            $row['current_task_id'] = 0;
            $row['dwell_seconds'] = null;
            $row['overdue'] = 0;
            $row['urge_count'] = (int)Db::name('cpq_approval_action')
                ->where('instance_id', (int)$row['id'])
                ->where('action', self::ACTION_URGE)
                ->count();
            if ($row['status'] === self::INSTANCE_ACTIVE && $row['current_node']) {
                $pendingTasks = Db::name('cpq_approval_task')
                    ->alias('t')
                    ->join('__ADMIN__ a', 'a.id = t.assignee_id', 'LEFT')
                    ->where('t.instance_id', (int)$row['id'])
                    ->where('t.node', (string)$row['current_node'])
                    ->where('t.status', self::TASK_PENDING)
                    ->field('t.id, a.nickname')
                    ->select();
                $row['current_handlers'] = implode('、', array_filter(array_column($pendingTasks, 'nickname')));
                $row['current_task_id'] = $pendingTasks ? (int)min(array_column($pendingTasks, 'id')) : 0;
                $earliest = Db::name('cpq_approval_task')
                    ->where('instance_id', (int)$row['id'])
                    ->where('node', (string)$row['current_node'])
                    ->where('status', self::TASK_PENDING)
                    ->min('arrived_at');
                $row['dwell_seconds'] = $earliest ? ($now - (int)$earliest) : null;
                if ($row['sla_deadline']) {
                    $row['overdue'] = $now > (int)$row['sla_deadline'] ? 1 : 0;
                }
            }
            $row['totals'] = $this->revisionTotals((int)$row['quote_id'], (int)$row['revision_no']);
        }
        unset($row);
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * P71 审批详情数据（按角色脱敏：成本/公司控制价不进入无权页面）。
     */
    public function taskDetail($taskId, $adminId)
    {
        $task = Db::name('cpq_approval_task')->where('id', (int)$taskId)->find();
        if (!$task) {
            throw new InvalidArgumentException('审批任务不存在');
        }
        $instance = Db::name('cpq_approval_instance')->where('id', (int)$task['instance_id'])->find();
        $quote = Db::name('cpq_quote')->where('id', (int)$task['quote_id'])->find();
        if (!$instance || !$quote) {
            throw new InvalidArgumentException('审批数据不完整');
        }

        // 可见性：审批人/有效代理人/发起人/超管可看；其他人无权
        $isAssignee = (int)$task['assignee_id'] === (int)$adminId;
        $isDelegate = $this->delegationService->resolveDelegator(
            (int)$task['assignee_id'], (int)$adminId, 'approval', (string)$quote['product_line']) > 0;
        $isInitiator = (int)$instance['initiator_id'] === (int)$adminId || (int)$quote['owner_id'] === (int)$adminId;
        $scope = ProductLineScopeService::forAdmin((int)$adminId);
        $isAuditor = $scope->isUnrestricted();
        if (!$isAssignee && !$isDelegate && !$isInitiator && !$isAuditor) {
            throw new RuntimeException('无权查看该审批详情');
        }
        $scope->assertLineAllowed((string)$quote['product_line'], '无该产品线的数据权限');

        $roles = SensitiveFieldService::rolesOfAdmin((int)$adminId);
        $pricingService = new PricingService();

        $customer = $quote['customer_id'] ? Db::name('cpq_customer')->where('id', (int)$quote['customer_id'])->find() : null;
        $revision = Db::name('cpq_quote_revision')
            ->where('quote_id', (int)$quote['id'])
            ->where('revision_no', (int)$task['revision_no'])
            ->find();
        $pricingResult = $revision ? json_decode((string)$revision['pricing_result_json'], true) : [];

        // 风险项：审批等级 + 逐行控制价分级 + 折扣原因 + 条款偏差
        $risks = $this->buildRiskItems($instance, $quote, $revision, $pricingResult, $roles);

        // 价格展示：逐行金额与汇总（敏感字段按角色脱敏后输出）
        $masked = is_array($pricingResult) && $pricingResult ? $pricingService->maskForRoles($pricingResult, $roles) : [];

        $actions = Db::name('cpq_approval_action')
            ->where('instance_id', (int)$instance['id'])
            ->order('id asc')
            ->select();
        foreach ($actions as &$action) {
            $action['action_text'] = $this->actionText($action['action']);
            $action['node_text'] = $action['node'] ? $this->nodeText($action['node']) : '';
        }
        unset($action);

        // 完整路径与各节点状态
        $path = self::FIXED_PATHS[(string)$instance['approval_level']] ?? [];
        $nodes = [];
        foreach ($path as $node) {
            $nodeTasks = Db::name('cpq_approval_task')
                ->alias('t')
                ->join('__ADMIN__ a', 'a.id = t.assignee_id', 'LEFT')
                ->where('t.instance_id', (int)$instance['id'])
                ->where('t.node', $node)
                ->field('t.*, a.nickname AS assignee_name')
                ->order('t.id asc')
                ->select();
            $nodes[] = [
                'node' => $node,
                'node_text' => $this->nodeText($node),
                'tasks' => $nodeTasks,
            ];
        }

        // 版本差异（与上一冻结版本）：统一走按角色脱敏出口，成本/毛利不进入无权页面
        $versionDiff = null;
        if ((int)$task['revision_no'] > 1 && method_exists('\\app\\common\\service\\cpq\\QuoteRevisionService', 'diffForRoles')) {
            try {
                $versionDiff = (new QuoteRevisionService())->diffForRoles((int)$quote['id'], (int)$task['revision_no'] - 1, (int)$task['revision_no'], $roles);
            } catch (\Throwable $e) {
                $versionDiff = null;
            }
        }

        // 敏感字段查看审计：可见成本/控制价的角色查看审批详情必须留痕（不含敏感值）
        $sensitiveService = new SensitiveFieldService();
        if ($sensitiveService->requiresAudit($roles)) {
            $sensitiveService->recordAccess(
                'view_sensitive',
                $revision ? 'cpq_quote_revision' : 'cpq_quote',
                [$revision ? (int)$revision['id'] : (int)$quote['id']],
                $roles
            );
        }

        $now = time();
        return [
            'task' => $task,
            'instance' => $instance,
            'instance_status_text' => (new \app\admin\model\cpq\ApprovalInstance())->getStatusList()[$instance['status']] ?? $instance['status'],
            'quote' => [
                'id' => (int)$quote['id'],
                'code' => (string)$quote['code'],
                'name' => (string)$quote['name'],
                'status' => (string)$quote['status'],
                'status_text' => (new QuoteModel())->getStatusList()[$quote['status']] ?? $quote['status'],
                'customer_name' => $customer ? (string)$customer['name'] : '',
                'product_line' => (string)$quote['product_line'],
                'currency' => (string)$quote['currency'],
                'current_revision_no' => (int)$quote['current_revision_no'],
                'final_approval_level' => (string)$quote['final_approval_level'],
                'owner_name' => (string)(Db::name('admin')->where('id', (int)$quote['owner_id'])->value('nickname') ?: ''),
                'initiator_name' => (string)(Db::name('admin')->where('id', (int)$instance['initiator_id'])->value('nickname') ?: ''),
                'submitted_at' => $quote['submitted_at'],
            ],
            'version_stale' => ((int)$quote['current_revision_no'] !== (int)$task['revision_no']
                || $quote['status'] !== QuoteModel::STATUS_SUBMITTED) ? 1 : 0,
            'can_act' => ($isAssignee || $isDelegate) && $task['status'] === self::TASK_PENDING
                && (int)$quote['current_revision_no'] === (int)$task['revision_no'] ? 1 : 0,
            'is_delegate' => $isDelegate && !$isAssignee ? 1 : 0,
            'delegator_name' => $isDelegate && !$isAssignee
                ? (string)(Db::name('admin')->where('id', (int)$task['assignee_id'])->value('nickname') ?: '') : '',
            'sla' => [
                'deadline' => $task['sla_deadline'],
                'overdue' => ($task['status'] === self::TASK_PENDING && $task['sla_deadline'] && $now > (int)$task['sla_deadline']) ? 1 : 0,
                'remaining' => $task['sla_deadline'] ? ((int)$task['sla_deadline'] - $now) : null,
            ],
            'pricing' => $masked,
            'risks' => $risks,
            'terms' => $revision
                ? Db::name('cpq_quote_term')->where('revision_id', (int)$revision['id'])->order('sort asc,id asc')->select()
                : [],
            'lines' => $revision
                ? Db::name('cpq_quote_price_snapshot')->where('revision_id', (int)$revision['id'])->order('id asc')->select()
                : [],
            'nodes' => $nodes,
            'actions' => $actions,
            'version_diff' => $versionDiff,
            'allowed_actions' => ['approve', 'reject', 'return', 'add_sign', 'transfer'],
        ];
    }

    /**
     * 审批记录（P73）：全部动作流水，支持按人/单号/产品线/时间筛选。
     */
    public function actionRecords(array $filters = [], $offset = 0, $limit = 20)
    {
        $where = [];
        if (!empty($filters['actor_id'])) {
            $where['act.actor_id'] = ['=', (int)$filters['actor_id']];
        }
        if (!empty($filters['action'])) {
            $where['act.action'] = ['=', (string)$filters['action']];
        }
        if (!empty($filters['keyword'])) {
            $where['q.code|q.name'] = ['like', '%' . trim((string)$filters['keyword']) . '%'];
        }
        $base = Db::name('cpq_approval_action')->alias('act')
            ->join('__CPQ_QUOTE__ q', 'q.id = act.quote_id', 'LEFT')
            ->where($where);
        if (!empty($filters['product_line'])) {
            $base->where('q.product_line', (string)$filters['product_line']);
        }
        if (!empty($filters['createtime'])) {
            $base->where('act.createtime', '>=', (int)$filters['createtime'][0])
                ->where('act.createtime', '<=', (int)$filters['createtime'][1]);
        }
        $total = (clone $base)->count();
        $rows = (clone $base)
            ->field('act.*, q.code AS quote_code, q.name AS quote_name, q.product_line')
            ->order('act.id desc')
            ->limit((int)$offset, (int)$limit)
            ->select();
        $delegateIds = [];
        foreach ($rows as $row) {
            if ((int)$row['delegate_from_id'] > 0) {
                $delegateIds[(int)$row['delegate_from_id']] = true;
            }
        }
        $delegatorNames = $delegateIds
            ? Db::name('admin')->where('id', 'in', array_keys($delegateIds))->column('nickname', 'id')
            : [];
        foreach ($rows as &$row) {
            $row['action_text'] = $this->actionText($row['action']);
            $row['node_text'] = $row['node'] ? $this->nodeText($row['node']) : '';
            $row['delegator_name'] = isset($delegatorNames[(int)$row['delegate_from_id']])
                ? $delegatorNames[(int)$row['delegate_from_id']] : '';
            $row['duration_seconds'] = null;
        }
        unset($row);
        return ['total' => $total, 'rows' => $rows];
    }

    // ------------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------------

    private function createNodeTasks($instanceId, array $quote, $node, $revisionNo, $initiatorId, $now, $updateInstance = false)
    {
        $candidates = $this->resolveCandidates($node, $quote, $initiatorId);
        $deadline = null;
        if ($node === self::NODE_SALES_CONFIRM) {
            $deadline = $now + self::SALES_CONFIRM_SLA_HOURS * 3600;
        } else {
            $rule = $this->matchRule($node, (string)$quote['product_line']);
            $slaHours = $rule ? max(0, (int)$rule['sla_hours']) : 24;
            $deadline = $now + $slaHours * 3600;
        }
        $insert = [];
        foreach ($candidates as $adminId => $nickname) {
            $insert[] = [
                'instance_id' => (int)$instanceId,
                'quote_id' => (int)$quote['id'],
                'revision_no' => (int)$revisionNo,
                'node' => $node,
                'assignee_id' => (int)$adminId,
                'is_required' => 0,
                'status' => self::TASK_PENDING,
                'arrived_at' => $now,
                'sla_deadline' => $deadline,
                'createtime' => $now,
                'updatetime' => $now,
            ];
        }
        if ($insert) {
            Db::name('cpq_approval_task')->insertAll($insert);
        }
        if ($updateInstance) {
            Db::name('cpq_approval_instance')->where('id', (int)$instanceId)->update([
                'current_node' => $node,
                'sla_deadline' => $deadline,
                'updatetime' => $now,
            ]);
        } else {
            Db::name('cpq_approval_instance')->where('id', (int)$instanceId)->update([
                'sla_deadline' => $deadline,
                'updatetime' => $now,
            ]);
        }
        return array_keys($candidates);
    }

    /**
     * 节点完成判定：无未完成的会签任务 且 存在已完成的候选人任务。
     */
    private function isNodeComplete($instanceId, $node)
    {
        $pendingRequired = Db::name('cpq_approval_task')
            ->where('instance_id', (int)$instanceId)
            ->where('node', $node)
            ->where('is_required', 1)
            ->where('status', self::TASK_PENDING)
            ->count();
        if ($pendingRequired > 0) {
            return false;
        }
        $completed = Db::name('cpq_approval_task')
            ->where('instance_id', (int)$instanceId)
            ->where('node', $node)
            ->where('status', 'in', [self::TASK_COMPLETED])
            ->count();
        return $completed > 0;
    }

    private function closeTask(array $task, $status, $action, $comment, $reasonCategory = '', $actorId = 0, $actedAt = null)
    {
        Db::name('cpq_approval_task')->where('id', (int)$task['id'])->update([
            'status' => $status,
            'action' => $action,
            'acted_at' => $actedAt ?: time(),
            'comment' => mb_substr((string)$comment, 0, 1000),
            'reason_category' => mb_substr((string)$reasonCategory, 0, 64),
            'updatetime' => time(),
        ]);
    }

    private function supersedeSiblingPendingTasks($instanceId, array $task, $actorId)
    {
        Db::name('cpq_approval_task')
            ->where('instance_id', (int)$instanceId)
            ->where('node', (string)$task['node'])
            ->where('id', '<>', (int)$task['id'])
            ->where('status', self::TASK_PENDING)
            ->update([
                'status' => self::TASK_SUPERSEDED,
                'action' => 'superseded',
                'acted_at' => time(),
                'updatetime' => time(),
            ]);
    }

    private function terminateInstance(array $instance, $instanceStatus, $action, $actorId, $comment)
    {
        $now = time();
        Db::name('cpq_approval_instance')->where('id', (int)$instance['id'])->update([
            'status' => $instanceStatus,
            'completed_at' => $now,
            'updatetime' => $now,
        ]);
        Db::name('cpq_approval_task')
            ->where('instance_id', (int)$instance['id'])
            ->where('status', self::TASK_PENDING)
            ->update([
                'status' => self::TASK_CANCELLED,
                'acted_at' => $now,
                'updatetime' => $now,
            ]);
        Db::name('cpq_approval_action')->insertGetId([
            'instance_id' => (int)$instance['id'],
            'task_id' => 0,
            'quote_id' => (int)$instance['quote_id'],
            'revision_no' => (int)$instance['revision_no'],
            'node' => '',
            'action' => $action,
            'actor_id' => (int)$actorId,
            'actor_name' => (string)(Db::name('admin')->where('id', (int)$actorId)->value('nickname') ?: ''),
            'delegate_from_id' => 0,
            'comment' => $comment,
            'reason_category' => '',
            'before_hash' => '',
            'after_hash' => '',
            'idempotency_key' => null,
            'ip' => '',
            'createtime' => $now,
        ]);
    }

    private function assertValidAssignee($adminId, array $quote, array $instance, array $task)
    {
        $admin = Db::name('admin')->where('id', (int)$adminId)->where('status', 'normal')->find();
        if (!$admin) {
            throw new InvalidArgumentException('目标审批人不存在或已禁用');
        }
        // 职责分离：目标不能是报价负责人/提交人（销售确认节点除外）
        if ($task['node'] !== self::NODE_SALES_CONFIRM
            && ((int)$adminId === (int)$quote['owner_id'] || (int)$adminId === (int)$instance['initiator_id'])) {
            throw new InvalidArgumentException('报价创建人与审批人必须分离，不能转交/加签给报价负责人');
        }
        // 数据权限：目标须有该产品线权限
        $scope = ProductLineScopeService::forAdmin((int)$adminId);
        $scope->assertLineAllowed((string)$quote['product_line'], '目标审批人无该产品线的数据权限');
        return $admin;
    }

    private function insertAction(array $instance, array $task, $action, $actorId, $delegateFromId, $comment,
                                   $idempotencyKey, $ip, $beforeHash, $afterHash = '', $actorName = '', $reasonCategory = '', $newTaskId = 0)
    {
        return Db::name('cpq_approval_action')->insertGetId([
            'instance_id' => (int)$instance['id'],
            'task_id' => (int)$task['id'],
            'quote_id' => (int)$task['quote_id'],
            'revision_no' => (int)$task['revision_no'],
            'node' => (string)$task['node'],
            'action' => $action,
            'actor_id' => (int)$actorId,
            'actor_name' => $actorName !== ''
                ? $actorName
                : (string)(Db::name('admin')->where('id', (int)$actorId)->value('nickname') ?: ''),
            'delegate_from_id' => (int)$delegateFromId,
            'comment' => mb_substr((string)$comment, 0, 1000),
            'reason_category' => mb_substr((string)$reasonCategory, 0, 64),
            'before_hash' => (string)$beforeHash,
            'after_hash' => (string)($afterHash !== '' ? $afterHash : $beforeHash),
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'ip' => mb_substr((string)$ip, 0, 50),
            'createtime' => time(),
        ]);
    }

    private function activeInstance($quoteId)
    {
        return Db::name('cpq_approval_instance')
            ->where('quote_id', (int)$quoteId)
            ->where('status', self::INSTANCE_ACTIVE)
            ->find();
    }

    private function instanceSummary($instanceId)
    {
        $instance = Db::name('cpq_approval_instance')->where('id', (int)$instanceId)->find();
        $tasks = Db::name('cpq_approval_task')
            ->alias('t')
            ->join('__ADMIN__ a', 'a.id = t.assignee_id', 'LEFT')
            ->where('t.instance_id', (int)$instanceId)
            ->where('t.status', self::TASK_PENDING)
            ->column('a.nickname');
        return [
            'instance_id' => (int)$instanceId,
            'status' => $instance['status'],
            'current_node' => $instance['current_node'],
            'path' => self::FIXED_PATHS[(string)$instance['approval_level']],
            'pending_approvers' => array_values(array_filter($tasks)),
        ];
    }

    private function instanceStatusOf($instanceId)
    {
        return (string)(Db::name('cpq_approval_instance')->where('id', (int)$instanceId)->value('status') ?: '');
    }

    private function nodeSlaDeadline(array $instance, $node)
    {
        $rule = $this->matchRule($node, (string)Db::name('cpq_quote')->where('id', (int)$instance['quote_id'])->value('product_line'));
        $hours = $rule ? max(0, (int)$rule['sla_hours']) : 24;
        return time() + $hours * 3600;
    }

    private function revisionTotals($quoteId, $revisionNo)
    {
        $resultJson = Db::name('cpq_quote_revision')
            ->where('quote_id', (int)$quoteId)
            ->where('revision_no', (int)$revisionNo)
            ->value('pricing_result_json');
        if (!$resultJson) {
            return [];
        }
        $result = json_decode((string)$resultJson, true);
        return is_array($result) && isset($result['totals']) && is_array($result['totals']) ? $result['totals'] : [];
    }

    private function buildRiskItems(array $instance, array $quote, $revision, array $pricingResult, array $roles)
    {
        $risks = [];
        $levelText = ['none' => '无需特批', 'line' => '需产线价格审批', 'company' => '需公司价格审批'];
        $risks[] = [
            'type' => 'approval_level',
            'title' => '审批等级',
            'detail' => $levelText[(string)$instance['approval_level']] ?? (string)$instance['approval_level'],
            'severity' => (string)$instance['approval_level'] === 'company' ? 'high' : ((string)$instance['approval_level'] === 'line' ? 'medium' : 'info'),
        ];

        // 逐行控制价分级（脱敏前的 classification 本身非敏感，可直接展示）
        $classText = ['normal' => '正常', 'line_approval' => '低于指导价', 'company_approval' => '低于产线控制价', 'forbidden' => '低于公司控制价'];
        if (isset($pricingResult['lines']) && is_array($pricingResult['lines'])) {
            foreach ($pricingResult['lines'] as $index => $line) {
                $class = isset($line['classification']) ? (string)$line['classification'] : '';
                if ($class && $class !== 'normal') {
                    $risks[] = [
                        'type' => 'classification',
                        'title' => '第' . ((int)($line['line_no'] ?? $index + 1)) . '行价格分级',
                        'detail' => $classText[$class] ?? $class,
                        'severity' => $class === 'forbidden' ? 'high' : ($class === 'company_approval' ? 'high' : 'medium'),
                    ];
                }
                if (!empty($line['manual_discount']) && (string)$line['manual_discount'] !== '1') {
                    $risks[] = [
                        'type' => 'discount',
                        'title' => '第' . ((int)($line['line_no'] ?? $index + 1)) . '行手工折扣',
                        'detail' => '折扣比例 ' . (string)$line['manual_discount']
                            . (empty($line['discount_reason']) ? '（未填写折扣原因）' : '（原因：' . (string)$line['discount_reason'] . '）'),
                        'severity' => 'medium',
                    ];
                }
            }
        }

        // 条款偏差（快照中的 term deviations 由 M2 提交时记录于 block_reasons/diff）
        if ($revision && !empty($revision['block_reasons_json'])) {
            $blockReasons = json_decode((string)$revision['block_reasons_json'], true);
            if (is_array($blockReasons)) {
                foreach ($blockReasons as $reason) {
                    if (is_array($reason) && isset($reason['message'])) {
                        $risks[] = [
                            'type' => 'term_deviation',
                            'title' => '条款偏差',
                            'detail' => (string)$reason['message'],
                            'severity' => 'medium',
                        ];
                    }
                }
            }
        }
        return $risks;
    }

    public function nodeText($node)
    {
        $map = [
            self::NODE_SALES_CONFIRM => '销售确认',
            self::NODE_LINE_APPROVAL => '产线价格审批',
            self::NODE_COMPANY_APPROVAL => '公司价格审批',
        ];
        return isset($map[$node]) ? $map[$node] : (string)$node;
    }

    public function actionText($action)
    {
        $map = [
            self::ACTION_CONFIRM => '销售确认',
            self::ACTION_APPROVE => '批准',
            self::ACTION_REJECT => '驳回',
            self::ACTION_RETURN => '退回修改',
            self::ACTION_ADD_SIGN => '加签',
            self::ACTION_TRANSFER => '转交',
            self::ACTION_WITHDRAW => '撤回',
            self::ACTION_URGE => '催办',
            self::ACTION_CANCEL => '取消',
        ];
        return isset($map[$action]) ? $map[$action] : (string)$action;
    }
}
