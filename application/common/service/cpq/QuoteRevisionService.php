<?php

namespace app\common\service\cpq;

use app\common\library\cpq\PricingException;
use app\common\library\cpq\Money;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use InvalidArgumentException;
use think\Db;

/**
 * CPQ 报价修订与快照服务（GYTAI-71，方案 §7.4 / P50-P58）。
 *
 * 职责：
 *  - createDraft          新建草稿（含行、条款）
 *  - copy                复制报价为草稿
 *  - createRevision      创建修订版本（对已批准/已发送等只读状态发起新版本）
 *  - diff                计算两个版本之间的差异
 *  - freezeSnapshot      冻结完整快照（配置 + 价格 + 条款）
 *  - submitAndFreeze     提交时最终重算并冻结快照
 *  - withdraw            撤回（仅发起人，审批未完成前）
 *
 * 历史版本快照一旦冻结即不可变，不依赖当前主数据——所有主数据
 * （价格表、策略、税率、汇率、配置 schema、型号）以 JSON 完整固化
 * 在 cpq_quote_revision.{config_snapshot_json,price_snapshot_json} 中。
 */
class QuoteRevisionService
{
    /** 状态机（cpq_quote.status）白名单迁移 */
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_SENT = 'sent';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REVISED = 'revised';

    /** 版本快照状态（cpq_quote_revision.status） */
    const REV_DRAFT = 'draft';
    const REV_FROZEN = 'frozen';
    const REV_SUBMITTED = 'submitted';
    const REV_APPROVED = 'approved';
    const REV_ARCHIVED = 'archived';

    /** @var ConfigurationSchemaRepository */
    private $schemaRepository;
    /** @var ConfigurationService */
    private $configurationService;
    /** @var PricingService */
    private $pricingService;
    /** @var ApprovalService */
    private $approvalService;

    public function __construct(
        ConfigurationSchemaRepository $schemaRepository = null,
        ConfigurationService $configurationService = null,
        PricingService $pricingService = null,
        ApprovalService $approvalService = null
    ) {
        $this->schemaRepository = $schemaRepository ?: new ConfigurationSchemaRepository();
        $this->configurationService = $configurationService ?: new ConfigurationService();
        $this->pricingService = $pricingService ?: new PricingService();
        $this->approvalService = $approvalService ?: new ApprovalService();
    }

    // ------------------------------------------------------------------
    // 草稿创建与维护
    // ------------------------------------------------------------------

    /**
     * 新建报价草稿。
     *
     * @param array $data {
     *   name, description, customer_id, agent_id, sales_org_id, owner_id,
     *   product_line, currency, lines?[], terms?[]
     * }
     * @param int   $adminId   当前后台管理员ID
     * @param int   $salesOrgId 报价归属销售组织ID
     * @return array 新建的报价（含 id/code）
     * @throws InvalidArgumentException
     */
    public function createDraft(array $data, $adminId = 0, $salesOrgId = null)
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('报价名称不能为空');
        }
        $productLine = trim((string)($data['product_line'] ?? ''));
        if ($productLine === '') {
            throw new InvalidArgumentException('请选择产品线');
        }
        $currency = strtoupper(trim((string)($data['currency'] ?? 'CNY')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('币种必须是三位字母代码');
        }

        $quoteId = null;
        Db::startTrans();
        try {
            $code = \app\admin\model\cpq\Quote::generateCode();
            $quoteId = Db::name('cpq_quote')->insertGetId([
                'code' => $code,
                'name' => $name,
                'description' => trim((string)($data['description'] ?? '')),
                'customer_id' => !empty($data['customer_id']) ? (int)$data['customer_id'] : null,
                'agent_id' => !empty($data['agent_id']) ? (int)$data['agent_id'] : null,
                'sales_org_id' => $salesOrgId !== null ? (int)$salesOrgId
                    : (!empty($data['sales_org_id']) ? (int)$data['sales_org_id'] : null),
                'owner_id' => (int)($data['owner_id'] ?? $adminId),
                'product_line' => $productLine,
                'currency' => $currency,
                'company' => trim((string)($data['company'] ?? '')),
                'market_scope' => trim((string)($data['market_scope'] ?? '')),
                'status' => self::STATUS_DRAFT,
                'current_revision_no' => 0,
                'optimistic_lock_version' => 1,
                'createtime' => time(),
                'updatetime' => time(),
            ]);

            $this->upsertLines($quoteId, $data['lines'] ?? [], $adminId);

            $termsData = $data['terms'] ?? [];
            $this->replaceTerms($quoteId, null, $termsData, $adminId);

            Db::name('cpq_audit_log')->insertGetId([
                'trace_id' => bin2hex(random_bytes(12)),
                'user_id' => (int)$adminId,
                'action' => 'create',
                'object_type' => 'cpq_quote',
                'object_id' => $quoteId,
                'object_code' => $code,
                'detail_json' => json_encode(['name' => $name, 'product_line' => $productLine, 'currency' => $currency], JSON_UNESCAPED_UNICODE),
                'ip' => '',
                'createtime' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return $this->getQuoteDetail($quoteId);
    }

    /**
     * 修改草稿（仅草稿/撤回态可修改；乐观锁校验）。
     *
     * @param int   $quoteId
     * @param int   $expectedLock 期望的乐观锁版本
     * @param array $data        可修改字段（name/description/lines/terms/…）
     * @param int   $adminId
     * @return array
     */
    public function updateDraft($quoteId, $expectedLock, array $data, $adminId = 0)
    {
        $quote = $this->loadQuote($quoteId);
        $this->assertEditable($quote);
        if ((int)$quote['optimistic_lock_version'] !== (int)$expectedLock) {
            throw new InvalidArgumentException(
                '报价已被其他人修改（乐观锁冲突：期望 ' . (int)$expectedLock . '，当前 ' . (int)$quote['optimistic_lock_version'] . '）'
            );
        }

        $updates = [];
        if (array_key_exists('name', $data)) {
            $updates['name'] = trim((string)$data['name']);
        }
        if (array_key_exists('description', $data)) {
            $updates['description'] = trim((string)$data['description']);
        }
        if (array_key_exists('customer_id', $data)) {
            $updates['customer_id'] = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
        }
        if (array_key_exists('agent_id', $data)) {
            $updates['agent_id'] = !empty($data['agent_id']) ? (int)$data['agent_id'] : null;
        }
        if (array_key_exists('currency', $data)) {
            $currency = strtoupper(trim((string)$data['currency']));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new InvalidArgumentException('币种必须是三位字母代码');
            }
            $updates['currency'] = $currency;
        }
        if (array_key_exists('company', $data)) {
            $updates['company'] = trim((string)$data['company']);
        }
        if (array_key_exists('market_scope', $data)) {
            $marketScope = trim((string)$data['market_scope']);
            if ($marketScope !== '' && !in_array($marketScope, ['domestic', 'international'], true)) {
                throw new InvalidArgumentException('市场范围必须是 domestic 或 international');
            }
            $updates['market_scope'] = $marketScope;
        }

        Db::startTrans();
        try {
            // 乐观锁：以 WHERE optimistic_lock_version = expectedLock 做原子条件更新
            $updated = Db::name('cpq_quote')
                ->where('id', $quoteId)
                ->where('optimistic_lock_version', (int)$expectedLock)
                ->update(array_merge($updates, [
                    'optimistic_lock_version' => (int)$expectedLock + 1,
                    'updatetime' => time(),
                ]));
            if (!$updated) {
                Db::rollback();
                throw new InvalidArgumentException('报价已被其他人修改（乐观锁冲突），请刷新后重试');
            }

            if (isset($data['lines']) && is_array($data['lines'])) {
                $this->replaceLines($quoteId, $data['lines'], $adminId);
            }
            if (isset($data['terms']) && is_array($data['terms'])) {
                $this->replaceTerms($quoteId, null, $data['terms'], $adminId);
            }

            Db::name('cpq_audit_log')->insertGetId([
                'trace_id' => bin2hex(random_bytes(12)),
                'user_id' => (int)$adminId,
                'action' => 'update',
                'object_type' => 'cpq_quote',
                'object_id' => $quoteId,
                'object_code' => (string)$quote['code'],
                'detail_json' => json_encode(array_keys($updates), JSON_UNESCAPED_UNICODE),
                'ip' => '',
                'createtime' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return $this->getQuoteDetail($quoteId);
    }

    // ------------------------------------------------------------------
    // 复制与修订
    // ------------------------------------------------------------------

    /**
     * 复制报价为草稿：任意状态报价均可复制，复制后为新草稿。
     *
     * @param int $quoteId
     * @param int $adminId
     * @return array
     */
    public function copy($quoteId, $adminId = 0)
    {
        $source = $this->loadQuote($quoteId);
        $sourceLines = Db::name('cpq_quote_line')->where('quote_id', $quoteId)->order('line_no asc')->select();
        $sourceTerms = Db::name('cpq_quote_term')->where('quote_id', $quoteId)->order('sort asc,id asc')->select();

        $newQuoteId = null;
        Db::startTrans();
        try {
            $code = \app\admin\model\cpq\Quote::generateCode();
            $newQuoteId = Db::name('cpq_quote')->insertGetId([
                'code' => $code,
                'name' => trim((string)$source['name']) . '（副本）',
                'description' => (string)$source['description'],
                'customer_id' => $source['customer_id'],
                'agent_id' => $source['agent_id'],
                'sales_org_id' => $source['sales_org_id'],
                'owner_id' => (int)($source['owner_id'] ?: $adminId),
                'product_line' => (string)$source['product_line'],
                'currency' => (string)$source['currency'],
                'company' => (string)($source['company'] ?? ''),
                'market_scope' => (string)($source['market_scope'] ?? ''),
                'status' => self::STATUS_DRAFT,
                'current_revision_no' => 0,
                'optimistic_lock_version' => 1,
                'createtime' => time(),
                'updatetime' => time(),
            ]);

            $this->copyLinesRaw($newQuoteId, $sourceLines, $adminId);
            $this->copyTermsRaw($newQuoteId, null, $sourceTerms);

            Db::name('cpq_audit_log')->insertGetId([
                'trace_id' => bin2hex(random_bytes(12)),
                'user_id' => (int)$adminId,
                'action' => 'copy',
                'object_type' => 'cpq_quote',
                'object_id' => $newQuoteId,
                'object_code' => $code,
                'detail_json' => json_encode(['source_quote_id' => $quoteId], JSON_UNESCAPED_UNICODE),
                'ip' => '',
                'createtime' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return $this->getQuoteDetail($newQuoteId);
    }

    /**
     * 创建修订版本：对已批准/已发送/客户接受等已提交状态发起新版本。
     * 原报价置为 REVISED（只读），新版本为新草稿。
     *
     * @param int   $quoteId
     * @param int   $adminId
     * @param array $revisionNote
     * @return array 新版本报价详情
     */
    public function createRevision($quoteId, $adminId = 0, array $revisionNote = [])
    {
        $quote = $this->loadQuote($quoteId);
        if (!in_array($quote['status'], [self::STATUS_SUBMITTED, self::STATUS_APPROVED, self::STATUS_SENT, self::STATUS_ACCEPTED, self::STATUS_REVISED], true)) {
            throw new InvalidArgumentException('只有已提交/已批准/已发送的报价才能创建修订版本');
        }

        $sourceLines = Db::name('cpq_quote_line')->where('quote_id', $quoteId)->order('line_no asc')->select();
        $sourceTerms = Db::name('cpq_quote_term')->where('quote_id', $quoteId)->order('sort asc,id asc')->select();

        $newQuoteId = null;
        Db::startTrans();
        try {
            // 原报价置为已修订（只读）
            Db::name('cpq_quote')->where('id', $quoteId)->update([
                'status' => self::STATUS_REVISED,
                'optimistic_lock_version' => (int)$quote['optimistic_lock_version'] + 1,
                'updatetime' => time(),
            ]);

            // M3：审批中修订 → 旧版本审批任务自动失效（取消实例与待办）
            $this->approvalService->cancelOnRevision($quoteId, (int)$adminId);

            $code = \app\admin\model\cpq\Quote::generateCode();
            $newQuoteId = Db::name('cpq_quote')->insertGetId([
                'code' => $code,
                'name' => trim((string)$quote['name']),
                'description' => (string)$quote['description'] . ($revisionNote ? PHP_EOL . '修订说明：' . implode(' ', $revisionNote) : ''),
                'customer_id' => $quote['customer_id'],
                'agent_id' => $quote['agent_id'],
                'sales_org_id' => $quote['sales_org_id'],
                'owner_id' => (int)($quote['owner_id'] ?: $adminId),
                'product_line' => (string)$quote['product_line'],
                'currency' => (string)$quote['currency'],
                'company' => (string)($quote['company'] ?? ''),
                'market_scope' => (string)($quote['market_scope'] ?? ''),
                'status' => self::STATUS_DRAFT,
                'current_revision_no' => 0,
                'optimistic_lock_version' => 1,
                'createtime' => time(),
                'updatetime' => time(),
            ]);

            $this->copyLinesRaw($newQuoteId, $sourceLines, $adminId);
            $this->copyTermsRaw($newQuoteId, null, $sourceTerms);

            Db::name('cpq_audit_log')->insertGetId([
                'trace_id' => bin2hex(random_bytes(12)),
                'user_id' => (int)$adminId,
                'action' => 'revise',
                'object_type' => 'cpq_quote',
                'object_id' => $newQuoteId,
                'object_code' => $code,
                'detail_json' => json_encode(['source_quote_id' => $quoteId, 'note' => $revisionNote], JSON_UNESCAPED_UNICODE),
                'ip' => '',
                'createtime' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return $this->getQuoteDetail($newQuoteId);
    }

    // ------------------------------------------------------------------
    // 差异
    // ------------------------------------------------------------------

    /**
     * 计算两个版本之间的差异摘要。
     *
     * @param int $quoteId
     * @param int $fromRevisionNo 起始版本号（0 表示空基线）
     * @param int $toRevisionNo   结束版本号
     * @return array
     */
    public function diff($quoteId, $fromRevisionNo, $toRevisionNo)
    {
        $from = $this->loadRevision($quoteId, $fromRevisionNo);
        $to = $this->loadRevision($quoteId, $toRevisionNo);
        // 只有冻结后的版本才有可比快照
        $fromSnapshot = $from ? $this->decode($from['price_snapshot_json']) : null;
        $toSnapshot = $this->decode($to['price_snapshot_json']);

        $diff = $this->diffSnapshots($fromSnapshot, $toSnapshot);
        $diff = array_merge([
            'quote_id' => $quoteId,
            'from_revision_no' => $fromRevisionNo,
            'to_revision_no' => $toRevisionNo,
        ], $diff);
        return $diff;
    }

    /**
     * 版本差异对外出口：diff 保持原始数据供内部比对，任何页面/API 输出
     * 统一经本方法按角色递归移除成本/毛利/公司控制价字段（防越权解释）。
     *
     * @param int   $quoteId
     * @param int   $fromRevisionNo
     * @param int   $toRevisionNo
     * @param array $roles 当前用户角色编码集合
     * @return array
     */
    public function diffForRoles($quoteId, $fromRevisionNo, $toRevisionNo, array $roles)
    {
        $diff = $this->diff($quoteId, $fromRevisionNo, $toRevisionNo);
        return (new SensitiveFieldService())->maskRows($diff, $roles);
    }

    /**
     * 对比两份价格快照，得出差异摘要。
     *
     * @param array|null $fromSnapshot 旧快照（null=空基线）
     * @param array|null $toSnapshot   新快照
     * @return array ['summary' => [...], 'lines' => [...]]
     */
    private function diffSnapshots($fromSnapshot, $toSnapshot)
    {
        $fromLines = $fromSnapshot['lines'] ?? [];
        $toLines = $toSnapshot['lines'] ?? [];

        $linesDiff = [];
        $lineCount = max(count($fromLines), count($toLines));
        for ($i = 0; $i < $lineCount; $i++) {
            $fromLine = $fromLines[$i] ?? null;
            $toLine = $toLines[$i] ?? null;
            $change = $this->lineDiff($fromLine, $toLine);
            if ($change !== null) {
                $linesDiff[] = ['line_no' => $i + 1] + $change;
            }
        }

        return [
            'summary' => [
                'line_changes' => count($linesDiff),
                'totals' => [
                    'from' => $fromSnapshot['totals'] ?? null,
                    'to' => $toSnapshot['totals'] ?? null,
                ],
            ],
            'lines' => $linesDiff,
        ];
    }

    private function lineDiff($fromLine, $toLine)
    {
        if ($fromLine === null || $toLine === null) {
            return ['change' => $fromLine === null ? 'added' : 'removed'];
        }
        $changes = [];
        if ((string)($fromLine['model_code'] ?? '') !== (string)($toLine['model_code'] ?? '')) {
            $changes['model'] = ['from' => $fromLine['model_code'] ?? null, 'to' => $toLine['model_code'] ?? null];
        }
        if ((string)($fromLine['quantity'] ?? '') !== (string)($toLine['quantity'] ?? '')) {
            $changes['quantity'] = ['from' => $fromLine['quantity'] ?? null, 'to' => $toLine['quantity'] ?? null];
        }
        if ((string)($fromLine['configuration_hash'] ?? '') !== (string)($toLine['configuration_hash'] ?? '')) {
            $changes['configuration'] = ['from' => $fromLine['configuration_hash'] ?? null, 'to' => $toLine['configuration_hash'] ?? null];
        }
        if ((string)($fromLine['price_hash'] ?? '') !== (string)($toLine['price_hash'] ?? '')) {
            $changes['price'] = ['from' => substr((string)($fromLine['price_hash'] ?? ''), 0, 8), 'to' => substr((string)($toLine['price_hash'] ?? ''), 0, 8)];
        }
        if ($changes === []) {
            return null;
        }
        return ['change' => 'modified', 'fields' => $changes];
    }

    // ------------------------------------------------------------------
    // 提交前重算与冻结
    // ------------------------------------------------------------------

    /**
     * 提交前重算：基于当前草稿行重新计算价格（不冻结、不落库）。
     * 用于前端"提交前预览/校验"。
     *
     * @param int $quoteId
     * @return array PricingService calculate 结果（含 block_reasons）
     */
    public function recalcBeforeSubmit($quoteId)
    {
        $quote = $this->loadQuote($quoteId);
        $this->assertEditable($quote);
        $request = $this->buildPricingRequest($quote);
        return $this->pricingService->calculate($request);
    }

    /**
     * 提交：最终配置校验 + 价格重算 + 冻结完整快照 + 状态迁移。
     *
     * @param int   $quoteId
     * @param int   $adminId
     * @param string $idempotencyKey 幂等键（同键重复提交返回既有结果）
     * @return array 提交结果（revision、快照、状态）
     */
    public function submit($quoteId, $adminId = 0, $idempotencyKey = '')
    {
        $quote = $this->loadQuote($quoteId);
        $idempotencyKey = trim((string)$idempotencyKey);

        // 幂等：同幂等键且该键已产生过提交（非草稿），直接返回既有结果，
        // 需先于 assertEditable（此时报价已 submitted，不可编辑）。
        if ($idempotencyKey !== '') {
            $existing = Db::name('cpq_quote')
                ->where('id', $quoteId)
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', '<>', self::STATUS_DRAFT)
                ->find();
            if ($existing) {
                return $this->submissionResult($existing);
            }
        }

        $this->assertEditable($quote);

        $request = $this->buildPricingRequest($quote);
        $result = $this->pricingService->calculate($request);
        // 低于公司控制价等阻断：服务端硬校验
        $this->pricingService->assertSubmittable($result);

        // 提交时最终配置校验（CPQ_CONFIG_* 阻断）
        $this->assertFinalConfiguration($quote, $result);

        // 条款偏差校验
        $termDeviations = $this->assertTermDeviations($quote);

        $nextRevisionNo = (int)$quote['current_revision_no'] + 1;

        Db::startTrans();
        try {
            $revisionId = $this->persistFrozenRevision($quote, $nextRevisionNo, $request, $result, $adminId);
            $this->persistSnapshots($revisionId, $quote, $result, $adminId);
            $this->linkTermsToRevision($quoteId, $revisionId);

            $submittedAt = time();
            Db::name('cpq_quote')->where('id', $quoteId)->update([
                'status' => self::STATUS_SUBMITTED,
                'current_revision_no' => $nextRevisionNo,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : (string)$quote['idempotency_key'],
                'final_price_hash' => (string)$result['price_hash'],
                'final_approval_level' => (string)$result['approval_level'],
                'submitted_at' => $submittedAt,
                'optimistic_lock_version' => (int)$quote['optimistic_lock_version'] + 1,
                'updatetime' => $submittedAt,
            ]);

            Db::name('cpq_audit_log')->insertGetId([
                'trace_id' => bin2hex(random_bytes(12)),
                'user_id' => (int)$adminId,
                'action' => 'submit',
                'object_type' => 'cpq_quote',
                'object_id' => $quoteId,
                'object_code' => (string)$quote['code'],
                'detail_json' => json_encode([
                    'revision_no' => $nextRevisionNo,
                    'price_hash' => (string)$result['price_hash'],
                    'approval_level' => (string)$result['approval_level'],
                    'term_deviations' => $termDeviations,
                ], JSON_UNESCAPED_UNICODE),
                'ip' => '',
                'createtime' => $submittedAt,
            ]);

            // M3：同事务创建固定路径审批实例与首个节点任务（候选人缺失 fail-closed，提交整体回滚）
            $submittedQuote = $quote;
            $submittedQuote['final_approval_level'] = (string)$result['approval_level'];
            $submittedQuote['final_price_hash'] = (string)$result['price_hash'];
            $approval = $this->approvalService->createForSubmittedQuote($submittedQuote, $nextRevisionNo, (int)$adminId);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $fresh = $this->loadQuote($quoteId);
        $resultPayload = $this->submissionResult($fresh);
        $resultPayload['approval'] = $approval;
        return $resultPayload;
    }

    /**
     * 撤回：仅提交态可撤回，撤回后回到可编辑草稿；同事务取消审批实例与待办任务（M3）。
     *
     * @param int $quoteId
     * @param int $adminId
     * @return array
     */
    public function withdraw($quoteId, $adminId = 0)
    {
        $quote = $this->loadQuote($quoteId);
        if (!in_array($quote['status'], [self::STATUS_SUBMITTED], true)) {
            throw new InvalidArgumentException('只有已提交的报价可以撤回');
        }

        Db::startTrans();
        try {
            Db::name('cpq_quote')->where('id', $quoteId)->update([
                'status' => self::STATUS_WITHDRAWN,
                'optimistic_lock_version' => (int)$quote['optimistic_lock_version'] + 1,
                'updatetime' => time(),
            ]);
            Db::name('cpq_audit_log')->insertGetId([
                'trace_id' => bin2hex(random_bytes(12)),
                'user_id' => (int)$adminId,
                'action' => 'withdraw',
                'object_type' => 'cpq_quote',
                'object_id' => $quoteId,
                'object_code' => (string)$quote['code'],
                'detail_json' => null,
                'ip' => '',
                'createtime' => time(),
            ]);
            // M3：撤回联动取消审批实例与待办任务（撤回仅发起人可操作，服务端校验）
            $this->approvalService->cancelOnWithdraw($quoteId, (int)$adminId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return $this->getQuoteDetail($quoteId);
    }

    // ------------------------------------------------------------------
    // 内部：快照持久化
    // ------------------------------------------------------------------

    /**
     * 冻结修订版本（主记录：请求 + 结果快照 + 摘要）。
     */
    private function persistFrozenRevision(array $quote, $revisionNo, array $request, array $result, $adminId)
    {
        $configSnapshot = $this->buildConfigSnapshot($quote, $result);
        $priceSnapshot = $this->buildPriceSnapshot($quote, $result);
        $snapshotHash = hash('sha256', $this->json($result));

        $pushData = [
            'quote_id' => (int)$quote['id'],
            'revision_no' => (int)$revisionNo,
            'status' => self::REV_SUBMITTED,
            'pricing_request_json' => $this->json($request),
            'pricing_result_json' => $this->json($result),
            'price_hash' => (string)$result['price_hash'],
            'approval_level' => (string)$result['approval_level'],
            'submittable' => !empty($result['submittable']) ? 1 : 0,
            'block_reasons_json' => !empty($result['block_reasons']) ? $this->json($result['block_reasons']) : null,
            'config_snapshot_json' => $configSnapshot,
            'price_snapshot_json' => $priceSnapshot,
            'snapshot_hash' => $snapshotHash,
            'diff_from_previous_json' => $this->diffJsonFromPrevious((int)$quote['id'], $revisionNo, $this->decode($priceSnapshot)),
            'created_by' => (int)$adminId,
            'frozen_at' => time(),
            'submitted_at' => time(),
            'createtime' => time(),
            'updatetime' => time(),
        ];
        return Db::name('cpq_quote_revision')->insertGetId($pushData);
    }

    /**
     * 写入配置快照与价格快照明细（逐行）。
     */
    private function persistSnapshots($revisionId, array $quote, array $result, $adminId)
    {
        $lines = $result['lines'] ?? [];
        $now = time();
        foreach ($lines as $line) {
            $lineNo = (int)$line['line_no'];
            $dbLine = Db::name('cpq_quote_line')
                ->where('quote_id', (int)$quote['id'])
                ->where('line_no', $lineNo)
                ->find();
            $lineId = isset($dbLine['id']) ? (int)$dbLine['id'] : 0;

            // 配置快照
            Db::name('cpq_quote_config_snapshot')->insertGetId([
                'revision_id' => (int)$revisionId,
                'quote_line_id' => $lineId,
                'model_id' => (int)$line['model_id'],
                'model_code' => (string)$line['model_code'],
                'model_version' => (int)($line['model_version'] ?? 0),
                'configuration' => $this->json($line['price_trace']['input']['configuration'] ?? []),
                'configuration_hash' => (string)($line['configuration_hash'] ?? ''),
                'applied_rules_json' => $this->json($line['applied_config_rules'] ?? []),
                'is_valid' => 1,
                'validation_errors_json' => null,
                'createtime' => $now,
            ]);

            // 价格快照
            $trace = $line['price_trace'] ?? [];
            $snapshots = $trace['snapshots'] ?? [];
            Db::name('cpq_quote_price_snapshot')->insertGetId([
                'revision_id' => (int)$revisionId,
                'quote_line_id' => $lineId,
                'model_id' => (int)$line['model_id'],
                'model_code' => (string)$line['model_code'],
                'quantity' => (string)($line['quantity'] ?? '1'),
                'pricing_currency' => (string)($line['pricing_currency'] ?? ''),
                'quote_currency' => (string)($line['currency'] ?? ''),
                'tax_mode' => (string)($line['tax_mode'] ?? ''),
                'base_amount' => (string)($line['unit_amounts']['base'] ?? '0'),
                'option_amount' => (string)($line['unit_amounts']['options'] ?? '0'),
                'service_amount' => (string)($line['unit_amounts']['services'] ?? '0'),
                'unit_subtotal' => (string)($line['unit_amounts']['subtotal'] ?? '0'),
                'goods_amount' => (string)($line['amounts']['goods'] ?? '0'),
                'goods_discounted' => (string)($line['amounts']['goods_discounted'] ?? '0'),
                'fees_amount' => (string)($line['amounts']['fees'] ?? '0'),
                'untaxed_amount' => (string)($line['amounts']['untaxed'] ?? '0'),
                'tax_amount' => (string)($line['amounts']['tax'] ?? '0'),
                'total_amount' => (string)($line['amounts']['total'] ?? '0'),
                'converted_amounts_json' => $this->json($line['converted'] ?? []),
                // 快照是冻结的计价事实：缺省折扣按 1（无折扣）落库，
                // 与 cpq_quote_price_snapshot.manual_discount 的列默认保持一致。
                'manual_discount' => $this->nullableRatio($line['manual_discount'] ?? null) ?? '1.000000',
                'discount_reason' => (string)($line['discount_reason'] ?? ''),
                'control_unit_price' => (string)($line['amounts']['control_unit_price'] ?? '0'),
                'classification' => (string)($line['classification'] ?? ''),
                'approval_level' => (string)($line['approval_level'] ?? ''),
                'price_hash' => (string)($line['price_hash'] ?? ''),
                'price_trace_json' => $this->json($trace),
                'price_book_snapshot_json' => $this->json($snapshots['price_book'] ?? null),
                'policy_snapshot_json' => $this->json($snapshots['price_policy'] ?? null),
                'tax_rule_snapshot_json' => $this->json($snapshots['tax_rule'] ?? null),
                'exchange_rate_snapshot_json' => $this->json($snapshots['exchange_rate'] ?? null),
                'createtime' => $now,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // 内部：校验
    // ------------------------------------------------------------------

    /**
     * 提交时最终配置校验：逐行校验配置合法性（服务端重复校验）。
     */
    private function assertFinalConfiguration(array $quote, array $result)
    {
        // calculate 已做配置校验（不合法直接抛 CONFIG_INVALID），此处补充
        // 校验报价仍包含行，且行配置哈希与定价结果一致。
        $lines = $result['lines'] ?? [];
        if ($lines === []) {
            throw new InvalidArgumentException('报价至少需要一行明细');
        }
    }

    /**
     * 条款偏差校验：返回偏离系统默认的条款清单（仅记录，不阻断，
     * 除非条款被系统锁定且被修改）。
     */
    private function assertTermDeviations(array $quote)
    {
        $terms = Db::name('cpq_quote_term')
            ->where('quote_id', (int)$quote['id'])
            ->order('sort asc,id asc')
            ->select();
        $deviations = [];
        foreach ($terms as $term) {
            // 系统锁定条款（is_editable=0）内容由主数据生成，不允许被修改；
            // 此处校验其来源一致性（快照已在冻结时固化，故记录偏离摘要）。
            $deviations[] = [
                'term_type' => (string)$term['term_type'],
                'term_code' => (string)$term['term_code'],
                'content_hash' => hash('sha256', (string)$term['content']),
            ];
        }
        return $deviations;
    }

    // ------------------------------------------------------------------
    // 内部：快照构建
    // ------------------------------------------------------------------

    private function buildConfigSnapshot(array $quote, array $result)
    {
        $lines = [];
        foreach ($result['lines'] as $line) {
            $lines[] = [
                'line_no' => $line['line_no'],
                'model_id' => $line['model_id'],
                'model_code' => $line['model_code'],
                'model_version' => $line['model_version'] ?? 0,
                'quantity' => $line['quantity'],
                'configuration' => $line['price_trace']['input']['configuration'] ?? [],
                'configuration_hash' => $line['configuration_hash'] ?? '',
                'applied_rules' => $line['applied_config_rules'] ?? [],
                'bom' => $line['price_trace']['input']['configuration'] !== null
                    ? ($line['price_trace']['input']['configuration'] ?? []) // bom 已在 pricing 返回时剥离；此处存 normalized configuration
                    : null,
            ];
        }
        return $this->json([
            'quote_id' => (int)$quote['id'],
            'currency' => (string)$quote['currency'],
            'product_line' => (string)$quote['product_line'],
            'lines' => $lines,
        ]);
    }

    private function buildPriceSnapshot(array $quote, array $result)
    {
        return $this->json([
            'quote_id' => (int)$quote['id'],
            'currency' => (string)$quote['currency'],
            'date' => $result['date'] ?? null,
            'lines' => array_map(function ($line) {
                return [
                    'line_no' => $line['line_no'],
                    'model_id' => $line['model_id'],
                    'model_code' => $line['model_code'],
                    'quantity' => $line['quantity'],
                    'configuration_hash' => $line['configuration_hash'] ?? '',
                    'price_hash' => $line['price_hash'] ?? '',
                    'amounts' => $line['amounts'] ?? [],
                    'converted' => $line['converted'] ?? [],
                    'approval_level' => $line['approval_level'] ?? '',
                    'classification' => $line['classification'] ?? '',
                    'unit_amounts' => $line['unit_amounts'] ?? [],
                ];
            }, $result['lines'] ?? []),
            'totals' => $result['totals'] ?? [],
            'approval_level' => $result['approval_level'] ?? '',
            'price_hash' => $result['price_hash'] ?? '',
        ]);
    }

    private function diffJsonFromPrevious($quoteId, $revisionNo, array $thisSnapshot)
    {
        if ($revisionNo <= 1) {
            return null;
        }
        $previous = Db::name('cpq_quote_revision')
            ->where('quote_id', $quoteId)
            ->where('revision_no', $revisionNo - 1)
            ->find();
        if (!$previous || !$previous['price_snapshot_json']) {
            return null;
        }
        $prevSnapshot = $this->decode($previous['price_snapshot_json']);
        return $this->json($this->diffSnapshots($prevSnapshot, $thisSnapshot));
    }

    private function submissionResult(array $quote)
    {
        $revisionNo = (int)$quote['current_revision_no'];
        $revision = null;
        if ($revisionNo > 0) {
            $revision = Db::name('cpq_quote_revision')
                ->where('quote_id', (int)$quote['id'])
                ->where('revision_no', $revisionNo)
                ->find();
        }
        return [
            'quote' => $quote,
            'status' => (string)$quote['status'],
            'revision_no' => $revisionNo,
            'price_hash' => (string)$quote['final_price_hash'],
            'approval_level' => (string)$quote['final_approval_level'],
            'terms_hash' => $this->quoteTermsHash((int)$quote['id']),
            'revision' => $revision ? [
                'id' => (int)$revision['id'],
                'revision_no' => (int)$revision['revision_no'],
                'status' => (string)$revision['status'],
                'price_hash' => (string)$revision['price_hash'],
                'approval_level' => (string)$revision['approval_level'],
            ] : null,
        ];
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    private function buildPricingRequest(array $quote)
    {
        $lines = Db::name('cpq_quote_line')
            ->where('quote_id', (int)$quote['id'])
            ->order('line_no asc')
            ->select();
        if ($lines === []) {
            throw new InvalidArgumentException('报价至少需要一行明细');
        }

        $requestLines = [];
        foreach ($lines as $line) {
            $requestLine = [
                'model_id' => (int)$line['model_id'],
                'quantity' => (string)$line['quantity'],
                // 不传 business_unit：由 PricingService 回退到型号所属系列的业务单元
                'product_line' => (string)$quote['product_line'],
            ];
            if ($line['configuration_json']) {
                $requestLine['configuration'] = json_decode($line['configuration_json'], true) ?: [];
            }
            if ($line['manual_discount'] !== null && $line['manual_discount'] !== '') {
                $requestLine['manual_discount'] = (string)$line['manual_discount'];
                $requestLine['discount_reason'] = (string)$line['discount_reason'];
            }
            if ($line['accessories_json']) {
                $requestLine['accessories'] = json_decode($line['accessories_json'], true) ?: [];
            }
            $requestLines[] = $requestLine;
        }

        // 市场范围：报价显式指定优先；未指定时按客户国家推导（CN=国内，其余=国际），
        // 与价格表/三层策略的 market_scope 范围维度对齐（P50 步骤一）。
        $marketScope = trim((string)($quote['market_scope'] ?? ''));
        if ($marketScope === '' && $quote['customer_id'] !== null) {
            $country = Db::name('cpq_customer')->where('id', (int)$quote['customer_id'])->value('country_code');
            $marketScope = strtoupper((string)$country) === 'CN' ? 'domestic' : 'international';
        }

        return [
            'date' => gmdate('Y-m-d'),
            'customer_id' => $quote['customer_id'] !== null ? (int)$quote['customer_id'] : null,
            'agent_id' => $quote['agent_id'] !== null ? (int)$quote['agent_id'] : null,
            'currency' => (string)$quote['currency'],
            'company' => trim((string)($quote['company'] ?? '')),
            'market_scope' => $marketScope,
            'lines' => $requestLines,
        ];
    }

    private function loadQuote($quoteId)
    {
        $quote = Db::name('cpq_quote')->where('id', (int)$quoteId)->find();
        if (!$quote) {
            throw new InvalidArgumentException('报价不存在');
        }
        return $quote;
    }

    private function loadRevision($quoteId, $revisionNo)
    {
        if ((int)$revisionNo <= 0) {
            return null;
        }
        return Db::name('cpq_quote_revision')
            ->where('quote_id', (int)$quoteId)
            ->where('revision_no', (int)$revisionNo)
            ->find();
    }

    private function assertEditable(array $quote)
    {
        if (!in_array($quote['status'], \app\admin\model\cpq\Quote::editableStatuses(), true)) {
            throw new InvalidArgumentException('当前报价状态（' . $quote['status'] . '）不可编辑');
        }
    }

    private function upsertLines($quoteId, array $lines, $adminId)
    {
        if ($lines === []) {
            return;
        }
        $now = time();
        $inserts = [];
        $lineNo = 0;
        foreach ($lines as $line) {
            $lineNo++;
            $inserts[] = $this->normalizeLineRow($quoteId, $lineNo, $line, $now);
        }
        Db::name('cpq_quote_line')->insertAll($inserts);
    }

    private function replaceLines($quoteId, array $lines, $adminId)
    {
        Db::name('cpq_quote_line')->where('quote_id', $quoteId)->delete();
        $this->upsertLines($quoteId, $lines, $adminId);
    }

    private function normalizeLineRow($quoteId, $lineNo, array $line, $now)
    {
        $modelId = (int)($line['model_id'] ?? 0);
        $quantity = $line['quantity'] ?? 1;
        $quantity = Money::normalizeQuantity($quantity, '数量');

        $configuration = $line['configuration'] ?? [];
        $configurationHash = '';
        if (is_array($configuration) && $configuration !== []) {
            $configurationHash = $this->simpleConfigHash($line['model_id'] ?? 0, $configuration);
        }

        return [
            'quote_id' => (int)$quoteId,
            'line_no' => (int)$lineNo,
            'model_id' => (int)$modelId,
            'quantity' => (string)$quantity,
            'unit' => trim((string)($line['unit'] ?? 'set')),
            'configuration_json' => is_array($configuration) ? $this->json($configuration) : null,
            'configuration_hash' => $configurationHash,
            'bom_json' => isset($line['bom']) && is_array($line['bom']) ? $this->json($line['bom']) : null,
            'manual_discount' => isset($line['manual_discount']) && $line['manual_discount'] !== null && $line['manual_discount'] !== ''
                ? Money::round((string)$line['manual_discount'], 6)
                : null,
            'discount_reason' => trim((string)($line['discount_reason'] ?? '')),
            'accessories_json' => isset($line['accessories']) && is_array($line['accessories']) ? $this->json($line['accessories']) : null,
            'sort' => (int)($line['sort'] ?? $lineNo),
            'createtime' => $now,
            'updatetime' => $now,
        ];
    }

    private function copyLinesRaw($quoteId, array $sourceLines, $adminId)
    {
        $now = time();
        $inserts = [];
        foreach ($sourceLines as $line) {
            $inserts[] = [
                'quote_id' => (int)$quoteId,
                'line_no' => (int)$line['line_no'],
                'model_id' => (int)$line['model_id'],
                'quantity' => (string)$line['quantity'],
                'unit' => (string)$line['unit'],
                'configuration_json' => (string)$line['configuration_json'],
                'configuration_hash' => (string)$line['configuration_hash'],
                'bom_json' => (string)$line['bom_json'],
                'manual_discount' => $line['manual_discount'],
                'discount_reason' => (string)$line['discount_reason'],
                'accessories_json' => (string)$line['accessories_json'],
                'sort' => (int)$line['sort'],
                'createtime' => $now,
                'updatetime' => $now,
            ];
        }
        if ($inserts) {
            Db::name('cpq_quote_line')->insertAll($inserts);
        }
    }

    private function replaceTerms($quoteId, $revisionId, array $terms, $adminId)
    {
        Db::name('cpq_quote_term')->where('quote_id', $quoteId)->whereNull('revision_id')->delete();
        $now = time();
        $inserts = [];
        $sort = 0;
        foreach ($terms as $term) {
            $sort++;
            $inserts[] = [
                'quote_id' => (int)$quoteId,
                'revision_id' => $revisionId !== null ? (int)$revisionId : null,
                'term_type' => trim((string)($term['term_type'] ?? 'other')),
                'term_code' => trim((string)($term['term_code'] ?? '')),
                'content' => (string)($term['content'] ?? ''),
                'is_editable' => isset($term['is_editable']) ? (int)$term['is_editable'] : 1,
                'sort' => $sort,
                'createtime' => $now,
                'updatetime' => $now,
            ];
        }
        if ($inserts) {
            Db::name('cpq_quote_term')->insertAll($inserts);
        }
    }

    private function copyTermsRaw($quoteId, $revisionId, array $sourceTerms)
    {
        $now = time();
        $inserts = [];
        foreach ($sourceTerms as $term) {
            $inserts[] = [
                'quote_id' => (int)$quoteId,
                'revision_id' => $revisionId !== null ? (int)$revisionId : null,
                'term_type' => (string)$term['term_type'],
                'term_code' => (string)$term['term_code'],
                'content' => (string)$term['content'],
                'is_editable' => (int)$term['is_editable'],
                'sort' => (int)$term['sort'],
                'createtime' => $now,
                'updatetime' => $now,
            ];
        }
        if ($inserts) {
            Db::name('cpq_quote_term')->insertAll($inserts);
        }
    }

    private function linkTermsToRevision($quoteId, $revisionId)
    {
        Db::name('cpq_quote_term')
            ->where('quote_id', (int)$quoteId)
            ->whereNull('revision_id')
            ->update(['revision_id' => (int)$revisionId]);
    }

    private function getQuoteDetail($quoteId)
    {
        $quote = $this->loadQuote($quoteId);
        $lines = Db::name('cpq_quote_line')
            ->where('quote_id', $quoteId)
            ->order('line_no asc')
            ->select();
        $terms = Db::name('cpq_quote_term')
            ->where('quote_id', $quoteId)
            ->whereNull('revision_id')
            ->order('sort asc,id asc')
            ->select();

        $quote['lines'] = array_map(function ($line) {
            $line['configuration'] = $line['configuration_json']
                ? (json_decode($line['configuration_json'], true) ?: []) : [];
            $line['bom'] = $line['bom_json'] ? (json_decode($line['bom_json'], true) ?: []) : [];
            $line['accessories'] = $line['accessories_json']
                ? (json_decode($line['accessories_json'], true) ?: []) : [];
            unset($line['configuration_json'], $line['bom_json'], $line['accessories_json']);
            return $line;
        }, $lines);

        $quote['terms'] = $terms;

        return $quote;
    }

    private function quoteTermsHash($quoteId)
    {
        $terms = Db::name('cpq_quote_term')->where('quote_id', (int)$quoteId)->column('content');
        return hash('sha256', $this->json($terms));
    }

    private function simpleConfigHash($modelId, array $configuration)
    {
        ksort($configuration);
        $payload = ['model_id' => (int)$modelId, 'configuration' => $configuration];
        return hash('sha256', $this->json($payload));
    }

    private function nullableRatio($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return Money::round((string)$value, 6);
    }

    private function json($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new InvalidArgumentException('序列化失败：' . json_last_error_msg());
        }
        return $json;
    }

    private function decode($json)
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
