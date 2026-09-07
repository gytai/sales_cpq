<?php

namespace app\common\service\cpq;

use app\common\library\cpq\Money;
use app\common\library\cpq\PricingException;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use InvalidArgumentException;
use think\Db;

/**
 * CPQ 确定性定价引擎（GYTAI-68，方案 §7.2 / §7.3 / P36）。
 *
 * 固定流水线（每一步写入 price_trace）：
 *   型号基础价 + 选项增量价 + 配件/服务价
 *   → 适用价格规则 → 数量调整 → 客户/代理/区域调整 → 手工折扣
 *   → + 费用 → 未税金额 → + 税额 → 含税总额
 *   → 汇率换算与金额舍入 → 三层控制价比较 → 审批等级
 *
 * 确定性：同一输入 + 同一主数据版本 → 逐字节一致的结果与 price_hash；
 * 轨迹不含时间戳/随机数，全部排序带 id 决胜，金额一律 Money（bcmath 字符串）。
 *
 * 策略匹配八级优先级（§7.3，从最具体到最通用；"指定客户/指定代理商"同为
 * customer_id 维度——代理商主数据关联客户记录，归为第一级）：
 *   1 指定客户(customer_id) 2 等级+区域 3 等级 4 区域
 *   5 国内/国际 6 产品线 7 公司默认
 * 同级比固定维度数，再比 priority；仍并列 → 规则冲突，阻止提交（不猜测）。
 */
class PricingService
{
    /** 流水线版本（轨迹结构变更时递增） */
    const PIPELINE_VERSION = 1;

    /** 审批等级从宽到严（整单取最严格） */
    const APPROVAL_LEVELS = ['none', 'line', 'company', 'forbidden'];

    /** 价格规则调整对象的固定执行顺序 */
    const RULE_TARGET_ORDER = ['base', 'option', 'service', 'subtotal', 'freight'];

    /** @var array 实例级主数据缓存（单次试算内共享，支撑 100 行 < 2s） */
    private $cache = [];

    /** @var string 本次试算基准日期（Y-m-d，UTC） */
    private $date;

    /** @var ConfigurationSchemaRepository */
    private $schemaRepository;

    /** @var ConfigurationService */
    private $configurationService;

    public function __construct(ConfigurationSchemaRepository $schemaRepository = null, ConfigurationService $configurationService = null)
    {
        $this->schemaRepository = $schemaRepository ?: new ConfigurationSchemaRepository();
        $this->configurationService = $configurationService ?: new ConfigurationService();
    }

    // ------------------------------------------------------------------
    // 对外入口
    // ------------------------------------------------------------------

    /**
     * 确定性价格试算（单行快捷输入或多行 lines 输入）。
     *
     * 输入：date、customer_id、currency、company、market_scope、region_code、
     * customer_level、agent_level、tax_mode（报价级）；lines[] 或顶层
     * model_id/configuration/accessories/quantity/manual_discount/discount_reason（行级）。
     *
     * @param array $input
     * @return array 含 lines/totals/approval_level/submittable/price_trace/price_hash
     * @throws PricingException
     */
    public function calculate(array $input)
    {
        $this->date = $this->normalizeDate($input['date'] ?? null);
        // 主数据缓存按试算日期过滤，跨次调用必须重建，避免同实例复用时串日期
        $this->cache = [];
        $context = $this->buildContext($input);

        $rawLines = isset($input['lines']) ? $input['lines'] : [$input];
        if (!is_array($rawLines) || $rawLines === []) {
            throw new PricingException('至少需要一行报价明细', PricingException::INVALID_INPUT);
        }

        $lines = [];
        $lineNo = 0;
        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                throw new PricingException('报价行必须是对象', PricingException::INVALID_INPUT);
            }
            $lines[] = $this->calculateLine(++$lineNo, $rawLine, $context);
        }

        return $this->aggregateResult($context, $lines);
    }

    /**
     * 提交前硬校验（Q-004）：低于公司控制价服务端阻断，整单策略冲突/
     * 缺失在 calculate 阶段已抛出。报价提交流程（M2 报价中心）必须调用。
     *
     * @param array $result calculate 返回结果
     * @throws PricingException
     */
    public function assertSubmittable(array $result)
    {
        foreach (($result['block_reasons'] ?? []) as $reason) {
            throw new PricingException(
                $reason['message'],
                $reason['business_code'],
                $reason['details']
            );
        }
    }

    /**
     * 按角色脱敏价格结果（越权解释防护）：递归移除无权字段，返回新数组。
     * 销售等角色不可见 cost/company_floor/毛利；产线价格管理员不可见
     * company_floor（口径同 SensitiveFieldService）。
     *
     * @param array $result calculate 返回结果
     * @param array $roles  CPQ 角色编码集合
     * @return array
     */
    public function maskForRoles(array $result, array $roles)
    {
        if (array_intersect($roles, SensitiveFieldService::FULL_ACCESS_ROLES)) {
            return $result;
        }
        $sensitiveKeys = array_intersect($roles, SensitiveFieldService::LINE_ACCESS_ROLES)
            ? ['company_floor']
            : ['cost', 'cost_total', 'company_floor', 'margin_amount', 'margin_rate'];
        return $this->maskRecursive($result, $sensitiveKeys);
    }

    /**
     * 授权范围内的价格解释（explain）：试算 + 按角色脱敏 + 敏感查看审计。
     *
     * @param array $input 同 calculate
     * @param array $roles CPQ 角色编码集合
     * @return array calculate 结果（已脱敏）
     * @throws PricingException
     */
    public function explain(array $input, array $roles)
    {
        $result = $this->calculate($input);
        $masked = $this->maskForRoles($result, $roles);
        $sensitive = new SensitiveFieldService();
        if ($sensitive->requiresAudit($roles)) {
            $modelIds = [];
            foreach ($result['lines'] as $line) {
                $modelIds[] = (int)$line['model_id'];
            }
            $sensitive->recordAccess('view_sensitive', 'cpq_price_trace', $modelIds, $roles);
        }
        return $masked;
    }

    // ------------------------------------------------------------------
    // 输入规范化与上下文
    // ------------------------------------------------------------------

    private function normalizeDate($date)
    {
        if ($date === null || $date === '') {
            return gmdate('Y-m-d');
        }
        $text = trim((string)$date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('UTC'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)
            || $parsed === false
            || $parsed->format('Y-m-d') !== $text) {
            throw new PricingException('试算日期必须是合法的 Y-m-d 日期', PricingException::INVALID_INPUT);
        }
        return $text;
    }

    private function buildContext(array $input)
    {
        $customer = null;
        $customerId = (int)($input['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = $this->loadCustomer($customerId);
        }

        $agentId = (int)($input['agent_id'] ?? ($customer['agent_id'] ?? 0));
        $agent = $agentId > 0 ? $this->loadAgent($agentId) : null;

        $countryCode = $input['country_code'] ?? ($customer['country_code'] ?? 'CN');
        $marketScope = (string)($input['market_scope'] ?? '');
        if ($marketScope !== 'domestic' && $marketScope !== 'international') {
            $marketScope = strtoupper(trim((string)$countryCode)) === 'CN' ? 'domestic' : 'international';
        }
        $currency = strtoupper(trim((string)($input['currency'] ?? '')));
        if ($currency === '') {
            $currency = $customer !== null ? strtoupper((string)$customer['default_currency']) : 'CNY';
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new PricingException('币种必须是三位字母代码', PricingException::INVALID_INPUT);
        }

        return [
            'date' => $this->date,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'customer_code' => $customer !== null ? $customer['code'] : '',
            'country_code' => strtoupper(trim((string)$countryCode)),
            'region_code' => (string)($input['region_code'] ?? ($customer !== null ? $customer['region_code'] : '')),
            'customer_level' => (string)($input['customer_level'] ?? ($customer !== null ? $customer['customer_level'] : '')),
            'agent_id' => $agentId > 0 ? $agentId : null,
            'agent_code' => $agent !== null ? $agent['code'] : '',
            'agent_level' => $agent !== null
                ? $agent['agent_level']
                : (string)($input['agent_level'] ?? ''),
            'customer_level_discount' => isset($input['customer_level'])
                ? $this->levelDiscount('cpq_customer_level', (string)$input['customer_level'])
                : ($customer !== null ? $customer['customer_level_discount'] : null),
            'agent_level_discount' => $agent !== null
                ? $agent['agent_level_discount']
                : (isset($input['agent_level'])
                    ? $this->levelDiscount('cpq_agent_level', (string)$input['agent_level'])
                    : null),
            'via_agent' => $agent !== null || trim((string)($input['agent_level'] ?? '')) !== '',
            'market_scope' => $marketScope,
            'company' => trim((string)($input['company'] ?? '')),
            'currency' => $currency,
        ];
    }

    /** 等级编码 → 默认折扣率（等级不存在返回 null） */
    private function levelDiscount($table, $code)
    {
        $code = trim((string)$code);
        if ($code === '') {
            return null;
        }
        $level = Db::name($table)->where('code', $code)->where('status', 'normal')->find();
        return $level && $level['default_discount'] !== null ? (string)$level['default_discount'] : null;
    }

    /**
     * 客户上下文：区域、客户等级与所属代理商 ID 一次带出。
     *
     * @param int $customerId
     * @return array
     * @throws PricingException
     */
    private function loadCustomer($customerId)
    {
        $key = 'customer:' . (int)$customerId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $customer = Db::name('cpq_customer')
            ->where('id', (int)$customerId)
            ->where('status', 'normal')
            ->find();
        if (!$customer) {
            throw new PricingException('客户不存在或已停用：' . (int)$customerId, PricingException::INVALID_INPUT);
        }

        $row = [
            'code' => (string)$customer['code'],
            'country_code' => strtoupper(trim((string)$customer['country_code'])),
            'default_currency' => strtoupper(trim((string)$customer['default_currency'])),
            'region_code' => '',
            'customer_level' => '',
            'customer_level_discount' => null,
            'agent_id' => !empty($customer['agent_id']) ? (int)$customer['agent_id'] : null,
        ];

        if (!empty($customer['region_id'])) {
            $region = Db::name('cpq_region')->where('id', (int)$customer['region_id'])->find();
            if ($region) {
                $row['region_code'] = (string)$region['code'];
            }
        }
        if (!empty($customer['customer_level_id'])) {
            $level = Db::name('cpq_customer_level')->where('id', (int)$customer['customer_level_id'])->find();
            if ($level) {
                $row['customer_level'] = (string)$level['code'];
                $row['customer_level_discount'] = $level['default_discount'] !== null ? (string)$level['default_discount'] : null;
            }
        }
        $this->cache[$key] = $row;
        return $row;
    }

    /** 指定代理商上下文：有效期、等级与默认折扣。 */
    private function loadAgent($agentId)
    {
        $key = 'agent:' . (int)$agentId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $agent = Db::name('cpq_agent')
            ->where('id', (int)$agentId)
            ->where('status', 'normal')
            ->find();
        if (!$agent
            || (!empty($agent['auth_start_date']) && (string)$agent['auth_start_date'] > $this->date)
            || (!empty($agent['auth_end_date']) && (string)$agent['auth_end_date'] < $this->date)) {
            throw new PricingException(
                '代理商不存在、已停用或在试算日期无有效授权：' . (int)$agentId,
                PricingException::INVALID_INPUT,
                ['agent_id' => (int)$agentId, 'date' => $this->date]
            );
        }
        $row = [
            'id' => (int)$agent['id'],
            'code' => (string)$agent['code'],
            'agent_level' => '',
            'agent_level_discount' => null,
        ];
        if (!empty($agent['agent_level_id'])) {
            $level = Db::name('cpq_agent_level')
                ->where('id', (int)$agent['agent_level_id'])
                ->where('status', 'normal')
                ->find();
            if ($level) {
                $row['agent_level'] = (string)$level['code'];
                $row['agent_level_discount'] = $level['default_discount'] !== null
                    ? (string)$level['default_discount']
                    : null;
            }
        }
        $this->cache[$key] = $row;
        return $row;
    }

    // ------------------------------------------------------------------
    // 单行流水线
    // ------------------------------------------------------------------

    private function calculateLine($lineNo, array $line, array $context)
    {
        $modelId = (int)($line['model_id'] ?? 0);
        if ($modelId <= 0) {
            throw new PricingException('第' . $lineNo . '行缺少 model_id', PricingException::INVALID_INPUT, ['line' => $lineNo]);
        }
        $model = $this->loadModel($modelId);
        $quantity = Money::normalizeQuantity($line['quantity'] ?? 1, '第' . $lineNo . '行数量');

        $lineContext = $context;
        $lineContext['business_unit'] = trim((string)($line['business_unit'] ?? $model['business_unit']));
        $lineContext['product_line'] = trim((string)($line['product_line'] ?? $model['product_line']));

        // 配置校验（服务端重复校验，前端绕过无效）：选中项取校验后配置
        $configurationHash = null;
        $normalizedConfiguration = null;
        $appliedConfigRules = [];
        $selectedOptions = [];
        if (array_key_exists('configuration', $line) && $line['configuration'] !== null) {
            $schema = $this->loadSchema($modelId);
            $configuration = is_array($line['configuration']) ? $line['configuration'] : [];
            $validation = $this->configurationService->validate($schema, $configuration, [
                'customer_id' => $context['customer_id'],
                'region_code' => $context['region_code'],
                'market_scope' => $context['market_scope'],
            ]);
            if (!$validation['is_valid']) {
                $first = $validation['errors'][0]['message'] ?? '';
                throw new PricingException(
                    '第' . $lineNo . '行配置不合法，不能计价' . ($first !== '' ? '：' . $first : ''),
                    PricingException::CONFIG_INVALID,
                    ['line' => $lineNo, 'errors' => $validation['errors']]
                );
            }
            $configurationHash = $validation['configuration_hash'];
            $normalizedConfiguration = $validation['configuration'];
            $appliedConfigRules = $validation['applied_rules'];
            $selectedOptions = $this->collectSelectedOptions($schema, $validation['configuration']);
        }

        $steps = [];
        $bookMatch = $this->matchPriceBook($lineContext, $model, $quantity);
        $book = $bookMatch['book'];
        $baseEntry = $bookMatch['entry'];
        $entries = $this->loadEntries((int)$book['id']);
        $pricingCurrency = (string)$book['currency'];
        $taxMode = (string)$book['tax_mode'];

        // 1. 型号基础价（matchPriceBook 已按数量分段选中条目）
        $base = Money::fromString($baseEntry['amount'], $pricingCurrency, '型号基础价');
        $steps[] = $this->step('base_price', $base, [
            'price_book' => $this->bookSnapshot($book),
            'price_entry' => $this->entrySnapshot($baseEntry),
        ]);

        // 2. 选项增量价（选中项 × 默认数量；无条目按 0 计并记录）
        $optionsAmount = Money::of('0', $pricingCurrency);
        $optionItems = [];
        foreach ($selectedOptions as $selected) {
            $optionEntry = $this->pickEntry($entries, 'option', (int)$selected['option_id'], $quantity);
            $unitPrice = $optionEntry !== null
                ? Money::fromString($optionEntry['amount'], $pricingCurrency, '选项价格')
                : Money::of('0', $pricingCurrency);
            $optionQty = Money::normalizeQuantity($selected['default_qty'], '选项默认数量');
            $lineAmount = $unitPrice->mulByQty($optionQty);
            $optionsAmount = $optionsAmount->add($lineAmount);
            $optionItems[] = [
                'group_code' => $selected['group_code'],
                'option_code' => $selected['option_code'],
                'option_id' => (int)$selected['option_id'],
                'unit_price' => $unitPrice->getAmount(),
                'default_qty' => $optionQty,
                'amount' => $lineAmount->getAmount(),
                'has_price_entry' => $optionEntry !== null,
                'price_entry' => $optionEntry !== null ? $this->entrySnapshot($optionEntry) : null,
            ];
        }
        $steps[] = $this->step('option_prices', $optionsAmount, ['items' => $optionItems]);

        // 3. 配件/服务价（显式加购，缺少价格条目视为数据缺口直接阻断）
        $accessories = $this->normalizeAccessories($line['accessories'] ?? [], $lineNo);
        $servicesAmount = Money::of('0', $pricingCurrency);
        $accessoryItems = [];
        foreach ($accessories as $accessory) {
            $accessoryEntry = $this->pickEntry($entries, 'accessory_service', (int)$accessory['id'], $accessory['quantity']);
            if ($accessoryEntry === null) {
                throw new PricingException(
                    '价格表 ' . $book['code'] . ' 缺少配件/服务 ' . $accessory['code'] . ' 的价格条目',
                    PricingException::ENTRY_MISSING,
                    ['price_book' => $book['code'], 'target_type' => 'accessory_service', 'accessory_code' => $accessory['code']]
                );
            }
            $unitPrice = Money::fromString($accessoryEntry['amount'], $pricingCurrency, '配件/服务价格');
            $lineAmount = $unitPrice->mulByQty($accessory['quantity']);
            $servicesAmount = $servicesAmount->add($lineAmount);
            $accessoryItems[] = [
                'accessory_id' => (int)$accessory['id'],
                'accessory_code' => $accessory['code'],
                'unit_price' => $unitPrice->getAmount(),
                'quantity' => $accessory['quantity'],
                'amount' => $lineAmount->getAmount(),
                'price_entry' => $this->entrySnapshot($accessoryEntry),
            ];
        }
        $steps[] = $this->step('accessory_prices', $servicesAmount, ['items' => $accessoryItems]);

        // 4. 适用价格规则（互斥组单命中、can_stack 抑制、保底/封顶、freight 顺延到费用步）
        $unitAmounts = ['base' => $base, 'option' => $optionsAmount, 'service' => $servicesAmount];
        $ruleOutcome = $this->applyPriceRules($lineContext, $model, $quantity, $unitAmounts, $pricingCurrency, $lineNo);
        $unitAmounts = $ruleOutcome['amounts'];
        // 小计级规则命中时使用调整后小计；分项单价保持规则前口径
        $unitSubtotal = $ruleOutcome['subtotal'] !== null ? $ruleOutcome['subtotal'] : $this->sumUnits($unitAmounts);
        $steps[] = $this->step(
            'price_rules',
            $unitSubtotal,
            ['matched' => $ruleOutcome['matched'], 'suppressed' => $ruleOutcome['suppressed']]
        );

        // 5. 数量调整
        $goods = $unitSubtotal->mulByQty($quantity);
        $steps[] = $this->step('quantity', $goods, [
            'unit_subtotal' => $unitSubtotal->getAmount(),
            'quantity' => $quantity,
        ]);

        // 6. 客户/代理/区域调整（代理渠道价优先于客户等级默认折扣；
        //    折扣一律为支付比例语义：0.95 = 九五折）
        $channelApplied = [];
        $afterChannel = $goods;
        $channelSource = null;
        if ($context['via_agent'] && $context['agent_level_discount'] !== null) {
            $channelSource = ['type' => 'agent_level', 'code' => $context['agent_level'], 'discount' => (string)$context['agent_level_discount']];
        } elseif ($context['customer_level_discount'] !== null) {
            $channelSource = ['type' => 'customer_level', 'code' => $context['customer_level'], 'discount' => (string)$context['customer_level_discount']];
        }
        if ($channelSource !== null && bccomp($channelSource['discount'], '1', 8) !== 0) {
            $afterChannel = $goods->mulByRatio($channelSource['discount']);
            $channelApplied[] = [
                'source' => $channelSource['type'],
                'code' => $channelSource['code'],
                'discount' => Money::round($channelSource['discount'], 6),
                'before' => $goods->getAmount(),
                'after' => $afterChannel->getAmount(),
            ];
        }
        $steps[] = $this->step('channel_adjustments', $afterChannel, ['applied' => $channelApplied]);

        // 7. 手工折扣（支付比例语义，0.95 = 九五折；低于 1 必须填写理由）
        $manualRate = trim((string)($line['manual_discount'] ?? '1'));
        if ($manualRate === '') {
            $manualRate = '1';
        }
        if (!is_numeric($manualRate)) {
            throw new PricingException('第' . $lineNo . '行手工折扣必须是数值', PricingException::INVALID_INPUT, ['line' => $lineNo]);
        }
        if (bccomp($manualRate, '0', 8) <= 0 || bccomp($manualRate, '1', 8) > 0) {
            throw new PricingException('第' . $lineNo . '行手工折扣必须是 (0,1] 之间的支付比例（如 0.95 = 九五折）', PricingException::INVALID_INPUT, ['line' => $lineNo]);
        }
        if (bccomp($manualRate, '1', 8) < 0 && trim((string)($line['discount_reason'] ?? '')) === '') {
            throw new PricingException('第' . $lineNo . '行手工折扣必须填写理由', PricingException::INVALID_INPUT, ['line' => $lineNo]);
        }
        $afterManual = bccomp($manualRate, '1', 8) < 0
            ? $afterChannel->mulByRatio($manualRate)
            : $afterChannel;
        $steps[] = $this->step('manual_discount', $afterManual, [
            'discount' => Money::round($manualRate, 6),
            'discount_reason' => trim((string)($line['discount_reason'] ?? '')),
            'before' => $afterChannel->getAmount(),
        ]);
        $goodsDiscounted = $afterManual;

        // 8. 费用（freight/insurance/installation/other；运费受价格规则 freight 调整）
        $feesOutcome = $this->applyFees($lineContext, $model, $quantity, $goodsDiscounted, $ruleOutcome['freight_rules'], $pricingCurrency);
        $steps[] = $this->step('fees', $feesOutcome['total'], [
            'items' => $feesOutcome['items'],
            'freight_adjustments' => $feesOutcome['freight_adjustments'],
        ]);

        // 9～11. 未税金额 / 税额 / 含税总额
        $untaxed = $goodsDiscounted->add($feesOutcome['total']);
        $steps[] = $this->step('untaxed_amount', $untaxed, [
            'goods_discounted' => $goodsDiscounted->getAmount(),
            'fees' => $feesOutcome['total']->getAmount(),
        ]);

        $taxRule = $this->matchTaxRule($lineContext, $model);
        $taxRate = $taxRule !== null ? (string)$taxRule['rate'] : '0';
        if ($taxMode === 'tax_inclusive') {
            $netAmount = Money::of(bcdiv($untaxed->getAmount(), bcadd('1', $taxRate, 8), 8), $pricingCurrency);
            $tax = $untaxed->sub($netAmount);
            $total = $untaxed;
        } else {
            $tax = $untaxed->mulByRatio($taxRate);
            $total = $untaxed->add($tax);
        }
        $steps[] = $this->step('tax', $tax, [
            'tax_mode' => $taxMode,
            'matched' => $taxRule !== null ? $this->taxSnapshot($taxRule) : null,
            'rate' => $taxRate,
            'note' => $taxRule === null ? '未配置税率规则，按 0 计税' : '',
        ]);
        $steps[] = $this->step('total_with_tax', $total, ['untaxed' => $untaxed->getAmount(), 'tax' => $tax->getAmount()]);

        // 13. 三层控制价比较（先按价格表币种求值，未税口径，含计入价格控制的费用，按单价比较）
        $policy = $this->matchPolicy($lineContext, $model, $pricingCurrency, (string)$baseEntry['unit']);
        $controlAmount = $goodsDiscounted;
        foreach ($feesOutcome['items'] as $feeItem) {
            if (!empty($feeItem['include_in_floor'])) {
                $controlAmount = $controlAmount->add(Money::of($feeItem['amount'], $pricingCurrency));
            }
        }
        $controlUnit = Money::of(bcdiv($controlAmount->getAmount(), $quantity, 8), $pricingCurrency);
        $classification = PricePolicyService::classifyAgainstFloors($controlUnit->getAmount(), $policy);
        $approvalLevel = $this->approvalLevelOf($classification);

        // 毛利（敏感：成本来自匹配策略；计入毛利的费用口径，价格表币种）
        $marginRevenue = $goodsDiscounted;
        foreach ($feesOutcome['items'] as $feeItem) {
            if (!empty($feeItem['include_in_margin'])) {
                $marginRevenue = $marginRevenue->add(Money::of($feeItem['amount'], $pricingCurrency));
            }
        }
        $cost = Money::of((string)$policy['cost'], $pricingCurrency);
        $costTotal = $cost->mulByQty($quantity);
        $marginAmount = $marginRevenue->sub($costTotal);
        $marginRate = $marginRevenue->isZero() ? '0.000000' : Money::round(
            bcdiv($marginAmount->getAmount(), $marginRevenue->getAmount(), 8),
            6
        );

        // 12. 汇率换算与金额舍入（输出币种 ≠ 价格表币种时使用有效汇率快照）
        $conversion = $this->convertCurrency(
            $context['currency'],
            $pricingCurrency,
            [
                'goods_discounted' => $goodsDiscounted,
                'fees' => $feesOutcome['total'],
                'untaxed' => $untaxed,
                'tax' => $tax,
                'total' => $total,
                'control_unit' => $controlUnit,
                'margin_amount' => $marginAmount,
            ]
        );
        $steps[] = [
            'step' => 'currency_conversion',
            'output' => $conversion['amounts'],
            'rate' => $conversion['rate'],
            'rounding' => 'HALF_UP scale ' . Money::SCALE,
        ];
        $steps[] = [
            'step' => 'floor_comparison',
            'control_unit_price' => $controlUnit->getAmount(),
            'policy' => $this->policySnapshot($policy),
            'classification' => $classification,
        ];
        $steps[] = $this->step('approval_level', $controlUnit, [
            'classification' => $classification,
            'approval_level' => $approvalLevel,
        ]);

        $lineResult = [
            'line_no' => $lineNo,
            'model_id' => (int)$model['id'],
            'model_code' => (string)$model['code'],
            'model_version' => (int)$model['version'],
            'quantity' => $quantity,
            'pricing_currency' => $pricingCurrency,
            'currency' => $context['currency'],
            'tax_mode' => $taxMode,
            'configuration_hash' => $configurationHash,
            'applied_config_rules' => $appliedConfigRules,
            'unit_amounts' => [
                'base' => $unitAmounts['base']->getAmount(),
                'options' => $unitAmounts['option']->getAmount(),
                'services' => $unitAmounts['service']->getAmount(),
                'subtotal' => $unitSubtotal->getAmount(),
            ],
            'amounts' => [
                'goods' => $goods->getAmount(),
                'goods_discounted' => $goodsDiscounted->getAmount(),
                'fees' => $feesOutcome['total']->getAmount(),
                'untaxed' => $untaxed->getAmount(),
                'tax' => $tax->getAmount(),
                'total' => $total->getAmount(),
                'control_unit_price' => $controlUnit->getAmount(),
            ],
            'converted' => $conversion['amounts'],
            'fees_by_type' => $feesOutcome['by_type'],
            'margin' => [
                'cost' => $cost->getAmount(),
                'cost_total' => $costTotal->getAmount(),
                'revenue' => $marginRevenue->getAmount(),
                'margin_amount' => $marginAmount->getAmount(),
                'margin_rate' => $marginRate,
            ],
            'classification' => $classification,
            'approval_level' => $approvalLevel,
        ];

        $trace = [
            'pipeline_version' => self::PIPELINE_VERSION,
            'line_no' => $lineNo,
            'input' => [
                'model_id' => (int)$model['id'],
                'model_code' => (string)$model['code'],
                'quantity' => $quantity,
                'configuration_hash' => $configurationHash,
                'configuration' => $normalizedConfiguration,
                'applied_config_rules' => $appliedConfigRules,
                'currency' => $context['currency'],
                'pricing_currency' => $pricingCurrency,
                'tax_mode' => $taxMode,
                'manual_discount' => Money::round($manualRate, 6),
                'discount_reason' => trim((string)($line['discount_reason'] ?? '')),
                'accessories' => array_map(function ($accessory) {
                    return [
                        'id' => (int)$accessory['id'],
                        'code' => (string)$accessory['code'],
                        'quantity' => $accessory['quantity'],
                    ];
                }, $accessories),
            ],
            'model' => [
                'id' => (int)$model['id'],
                'code' => (string)$model['code'],
                'version' => (int)$model['version'],
                'unit' => (string)$baseEntry['unit'],
                'series_code' => (string)$model['series_code'],
                'product_line' => $lineContext['product_line'],
                'business_unit' => $lineContext['business_unit'],
            ],
            'steps' => $steps,
            'snapshots' => [
                'price_book' => $this->bookSnapshot($book),
                'price_policy' => $this->policySnapshot($policy),
                'price_rules' => $ruleOutcome['snapshots'],
                'fee_rules' => $feesOutcome['snapshots'],
                'tax_rule' => $taxRule !== null ? $this->taxSnapshot($taxRule) : null,
                'exchange_rate' => $conversion['snapshot'],
            ],
        ];
        $lineResult['price_trace'] = $trace;
        $lineResult['price_hash'] = $this->canonicalHash($trace);
        return $lineResult;
    }

    /**
     * 整单汇总：金额合计（输出币种）+ 最严格审批等级 + 提交阻断原因。
     */
    private function aggregateResult(array $context, array $lines)
    {
        $totalFields = ['goods_discounted', 'fees', 'untaxed', 'tax', 'total', 'margin_amount'];
        $totals = array_fill_keys($totalFields, '0');
        foreach ($lines as $line) {
            foreach ($totalFields as $field) {
                $totals[$field] = bcadd($totals[$field], $line['converted'][$field], Money::SCALE);
            }
        }

        $approvalLevel = 'none';
        $blockReasons = [];
        foreach ($lines as $line) {
            $level = $line['approval_level'];
            if (array_search($level, self::APPROVAL_LEVELS, true) > array_search($approvalLevel, self::APPROVAL_LEVELS, true)) {
                $approvalLevel = $level;
            }
            if ($level === 'forbidden') {
                $blockReasons[] = [
                    'line' => (int)$line['line_no'],
                    'business_code' => PricingException::BELOW_COMPANY_FLOOR,
                    'message' => '第' . $line['line_no'] . '行报价单价低于公司控制价（策略 '
                        . $line['price_trace']['snapshots']['price_policy']['code'] . '），禁止提交',
                    'details' => [
                        'line' => (int)$line['line_no'],
                        'model_code' => $line['model_code'],
                        'control_unit_price' => $line['amounts']['control_unit_price'],
                        'policy_code' => $line['price_trace']['snapshots']['price_policy']['code'],
                    ],
                ];
                continue;
            }
        }

        $result = [
            'date' => $this->date,
            'currency' => $context['currency'],
            'context' => $context,
            'lines' => $lines,
            'totals' => $totals,
            'approval_level' => $approvalLevel,
            'submittable' => $blockReasons === [],
            'block_reasons' => $blockReasons,
            'price_trace' => [
                'pipeline_version' => self::PIPELINE_VERSION,
                'date' => $this->date,
                'context' => $context,
                'line_price_hashes' => array_map(function ($line) {
                    return $line['price_hash'];
                }, $lines),
                'approval_level' => $approvalLevel,
            ],
        ];
        $result['price_hash'] = $this->canonicalHash($result['price_trace']);
        return $result;
    }

    // ------------------------------------------------------------------
    // 主数据匹配（价格表 / 三层策略 / 规则 / 税 / 费 / 汇率）
    // ------------------------------------------------------------------

    /**
     * 价格表匹配（P32）：已发布、生效中、适用范围命中（空维度=不限）且
     * 含该型号当前数量分段的价格条目，按 [币种一致, 优先级, 固定维度数, id]
     * 排序取第一（发布期已拦截同范围同优先级重叠，运行期无需再判冲突）。
     * 无范围命中 → BOOK_MISSING；有条目的范围命中全部缺条目 → ENTRY_MISSING。
     *
     * @return array ['book' => 行, 'entry' => 型号条目行]
     */
    private function matchPriceBook(array $lineContext, array $model, $quantity)
    {
        $scopeCandidates = [];
        $scopeDims = [
            'date' => $this->date,
            'company' => $lineContext['company'],
            'business_unit' => $lineContext['business_unit'],
            'market_scope' => $lineContext['market_scope'],
            'currency' => $lineContext['currency'],
        ];
        foreach ($this->loadBooks() as $book) {
            if ($this->scopeMatches($book, $lineContext)) {
                $scopeCandidates[] = $book;
            }
        }
        if ($scopeCandidates === []) {
            throw new PricingException(
                '试算日期 ' . $this->date . ' 无适用价格表',
                PricingException::BOOK_MISSING,
                $scopeDims
            );
        }

        $candidates = [];
        foreach ($scopeCandidates as $book) {
            $entries = $this->loadEntries((int)$book['id']);
            $modelEntry = $this->pickEntry($entries, 'model', (int)$model['id'], $quantity);
            if ($modelEntry === null) {
                continue;
            }
            $pinned = 0;
            foreach (['company', 'business_unit'] as $field) {
                if (trim((string)$book[$field]) !== '') {
                    $pinned++;
                }
            }
            if ((string)$book['market_scope'] !== 'all') {
                $pinned++;
            }
            $candidates[] = [
                'book' => $book,
                'entry' => $modelEntry,
                'currency_match' => strcasecmp((string)$book['currency'], $lineContext['currency']) === 0 ? 1 : 0,
                'priority' => (int)$book['priority'],
                'pinned' => $pinned,
            ];
        }
        if ($candidates === []) {
            throw new PricingException(
                '试算日期 ' . $this->date . ' 适用价格表均缺少型号 ' . $model['code'] . ' 的价格条目',
                PricingException::ENTRY_MISSING,
                ['model_code' => $model['code']] + $scopeDims
            );
        }
        usort($candidates, function ($left, $right) {
            return [$right['currency_match'], $right['priority'], $right['pinned'], -$right['book']['id']]
                <=> [$left['currency_match'], $left['priority'], $left['pinned'], -$left['book']['id']];
        });
        return $candidates[0];
    }

    /**
     * 三层价格策略匹配（八级优先级，§7.3）：同具体度比固定维度数，再比
     * priority；仍并列 → 策略冲突（不猜测、阻止提交）；无命中 → 指出缺失维度。
     * 策略币种 = 价格表（定价）币种。
     */
    private function matchPolicy(array $lineContext, array $model, $pricingCurrency, $unit)
    {
        $candidates = [];
        foreach ($this->loadPolicies('model', (int)$model['id'], $pricingCurrency, $unit) as $policy) {
            $match = $this->policyDimensionMatches($policy, $lineContext);
            if ($match !== true) {
                continue;
            }
            list($specificity, $pinned) = $this->policySpecificity($policy);
            $candidates[] = [
                'policy' => $policy,
                'specificity' => $specificity,
                'pinned' => $pinned,
                'priority' => (int)$policy['priority'],
            ];
        }
        if ($candidates === []) {
            throw new PricingException(
                '缺少型号 ' . $model['code'] . ' 的三层价格策略（无命中维度组合）',
                PricingException::POLICY_MISSING,
                [
                    'model_code' => $model['code'],
                    'target_type' => 'model',
                    'currency' => $pricingCurrency,
                    'unit' => $unit,
                    'company' => $lineContext['company'],
                    'business_unit' => $lineContext['business_unit'],
                    'market_scope' => $lineContext['market_scope'],
                    'region_code' => $lineContext['region_code'],
                    'customer_level' => $lineContext['customer_level'],
                    'agent_level' => $lineContext['agent_level'],
                    'product_line' => $lineContext['product_line'],
                    'date' => $this->date,
                ]
            );
        }
        usort($candidates, function ($left, $right) {
            return [$right['specificity'], $right['pinned'], $right['priority'], -$right['policy']['id']]
                <=> [$left['specificity'], $left['pinned'], $left['priority'], -$left['policy']['id']];
        });
        $top = $candidates[0];
        $ties = array_values(array_filter($candidates, function ($candidate) use ($top) {
            return $candidate['specificity'] === $top['specificity']
                && $candidate['pinned'] === $top['pinned']
                && $candidate['priority'] === $top['priority'];
        }));
        if (count($ties) > 1) {
            $codes = array_map(function ($candidate) {
                return (string)$candidate['policy']['code'];
            }, $ties);
            throw new PricingException(
                '型号 ' . $model['code'] . ' 命中多条相同具体度和优先级的价格策略（'
                . implode(' / ', $codes) . '），无法确定唯一策略',
                PricingException::POLICY_CONFLICT,
                [
                    'model_code' => $model['code'],
                    'policies' => $codes,
                    'specificity' => $top['specificity'],
                    'priority' => $top['priority'],
                ]
            );
        }
        return $top['policy'];
    }

    /** 策略维度逐一匹配：空维度=不限，customer_id 空=不限 */
    private function policyDimensionMatches(array $policy, array $lineContext)
    {
        // agent_id 不在本循环内按空维度匹配：它语义上「<=0 即未指定」，须与
        // 下方显式的 $policyAgent（>0 才 pin 住）保持一致；否则 agent_id=0 会被
        // 误当作字符串 '0' 的固定维度，在上下文中无代理时把策略错误拒绝。
        foreach ([
            'company' => $lineContext['company'],
            'business_unit' => $lineContext['business_unit'],
            'region_code' => $lineContext['region_code'],
            'customer_level' => $lineContext['customer_level'],
            'agent_level' => $lineContext['agent_level'],
            'product_line' => $lineContext['product_line'],
        ] as $field => $contextValue) {
            $policyValue = trim((string)$policy[$field]);
            if ($policyValue !== '' && $policyValue !== (string)$contextValue) {
                return false;
            }
        }
        if ((string)$policy['market_scope'] !== 'all'
            && (string)$policy['market_scope'] !== $lineContext['market_scope']) {
            return false;
        }
        $policyCustomer = $policy['customer_id'] === null ? 0 : (int)$policy['customer_id'];
        if ($policyCustomer > 0 && $policyCustomer !== (int)$lineContext['customer_id']) {
            return false;
        }
        $policyAgent = $policy['agent_id'] === null ? 0 : (int)$policy['agent_id'];
        if ($policyAgent > 0 && $policyAgent !== (int)$lineContext['agent_id']) {
            return false;
        }
        return true;
    }

    /**
     * 策略具体度（§7.3 八级）：指定客户 → 指定代理商 →
     * 等级+区域 → 等级 → 区域 → 市场 → 产品线 → 公司默认。
     * 固定维度数只用于同级决胜。
     *
     * @param array $policy
     * @return array [specificity, pinned]
     */
    private function policySpecificity(array $policy)
    {
        $hasCustomer = $policy['customer_id'] !== null && (int)$policy['customer_id'] > 0;
        $hasAgent = $policy['agent_id'] !== null && (int)$policy['agent_id'] > 0;
        $hasCustomerLevel = trim((string)$policy['customer_level']) !== '';
        $hasAgentLevel = trim((string)$policy['agent_level']) !== '';
        $hasRegion = trim((string)$policy['region_code']) !== '';
        $hasMarket = trim((string)$policy['market_scope']) !== '' && (string)$policy['market_scope'] !== 'all';
        $hasLine = trim((string)$policy['product_line']) !== '';

        if ($hasCustomer) {
            $specificity = 8;
        } elseif ($hasAgent) {
            $specificity = 7;
        } elseif (($hasCustomerLevel || $hasAgentLevel) && $hasRegion) {
            $specificity = 6;
        } elseif ($hasCustomerLevel || $hasAgentLevel) {
            $specificity = 5;
        } elseif ($hasRegion) {
            $specificity = 4;
        } elseif ($hasMarket) {
            $specificity = 3;
        } elseif ($hasLine) {
            $specificity = 2;
        } else {
            $specificity = 1;
        }

        $pinned = 0;
        foreach (['company', 'business_unit', 'region_code', 'customer_level', 'agent_level', 'product_line'] as $field) {
            if (trim((string)$policy[$field]) !== '') {
                $pinned++;
            }
        }
        if ($hasCustomer) {
            $pinned++;
        }
        if ($hasAgent) {
            $pinned++;
        }
        if ($hasMarket) {
            $pinned++;
        }
        return [$specificity, $pinned];
    }

    /** 价格表适用范围维度匹配（公司/板块/市场，空=不限） */
    private function scopeMatches(array $book, array $lineContext)
    {
        if (trim((string)$book['company']) !== '' && trim((string)$book['company']) !== $lineContext['company']) {
            return false;
        }
        if (trim((string)$book['business_unit']) !== '' && trim((string)$book['business_unit']) !== $lineContext['business_unit']) {
            return false;
        }
        if ((string)$book['market_scope'] !== 'all' && (string)$book['market_scope'] !== $lineContext['market_scope']) {
            return false;
        }
        return true;
    }

    /**
     * 适用价格规则：条件命中 → 互斥组取最高优先级一条（并列取 id 小者，
     * 发布期已拦截互斥组冲突）→ 按 [优先级, id] 应用到调整对象；
     * can_stack=0 的规则在同一对象上只生效第一条，其余抑制并记录。
     * freight 对象的调整不在此步改金额，顺延至费用步执行。
     */
    private function applyPriceRules(array $lineContext, array $model, $quantity, array $unitAmounts, $pricingCurrency, $lineNo)
    {
        $values = $this->conditionValues($lineContext, $model, $quantity, $pricingCurrency);
        $matched = [];
        $byGroup = [];
        foreach ($this->loadPriceRules() as $rule) {
            $condition = json_decode((string)$rule['condition_json'], true);
            if (!is_array($condition) || !$this->evaluateCondition($condition, $values)) {
                continue;
            }
            $matched[] = $rule;
            $group = trim((string)$rule['exclusive_group']);
            if ($group !== '') {
                $byGroup[$group][] = $rule;
            }
        }

        // 互斥组：每组保留最高优先级（id 决胜），组内其余抑制
        $suppressed = [];
        $selected = [];
        $selectedIds = [];
        foreach ($byGroup as $group => $groupRules) {
            usort($groupRules, function ($left, $right) {
                return [$right['priority'], -$right['id']] <=> [$left['priority'], -$left['id']];
            });
            if (count($groupRules) > 1
                && (int)$groupRules[0]['priority'] === (int)$groupRules[1]['priority']) {
                $conflictingCodes = array_map(function ($rule) use ($groupRules) {
                    return (int)$rule['priority'] === (int)$groupRules[0]['priority']
                        ? (string)$rule['code']
                        : null;
                }, $groupRules);
                $conflictingCodes = array_values(array_filter($conflictingCodes));
                throw new PricingException(
                    '第' . $lineNo . '行价格规则互斥组 ' . $group . ' 同优先级命中冲突（'
                    . implode(' / ', $conflictingCodes) . '）',
                    PricingException::RULE_CONFLICT,
                    ['line' => $lineNo, 'exclusive_group' => $group, 'rules' => $conflictingCodes]
                );
            }
            $selectedIds[(int)$groupRules[0]['id']] = true;
            foreach (array_slice($groupRules, 1) as $loser) {
                $suppressed[] = $this->ruleSnapshot($loser, 'exclusive_group:' . $group);
            }
        }
        foreach ($matched as $rule) {
            // 无互斥组的规则全部保留；有互斥组的仅保留组内胜出者
            if (trim((string)$rule['exclusive_group']) === '' || isset($selectedIds[(int)$rule['id']])) {
                $selected[] = $rule;
            }
        }
        usort($selected, function ($left, $right) {
            return [$right['priority'], -$right['id']] <=> [$left['priority'], -$left['id']];
        });

        $amounts = $unitAmounts;
        $subtotalOverride = null;
        $appliedRecords = [];
        $freightRules = [];
        $targetApplied = [];
        $snapshots = [];
        foreach ($selected as $rule) {
            $target = (string)$rule['adjustment_target'];
            $snapshots[] = $this->ruleSnapshot($rule, 'matched');
            if ($target === 'freight') {
                $freightRules[] = $rule;
                continue;
            }
            if (!in_array($target, ['base', 'option', 'service', 'subtotal'], true)) {
                $target = 'subtotal';
            }
            if (!(int)$rule['can_stack'] && !empty($targetApplied[$target])) {
                $suppressed[] = $this->ruleSnapshot($rule, 'non_stackable_target:' . $target);
                continue;
            }
            $before = $target === 'subtotal'
                ? ($subtotalOverride !== null ? $subtotalOverride : $this->sumUnits($amounts))
                : $amounts[$target];
            $after = $this->applyAdjustment($before, $rule, $pricingCurrency);
            if ($target === 'subtotal') {
                // 小计级调整不改写分项单价，单独携带，避免污染 base/option/service 口径
                $subtotalOverride = $after;
            } else {
                $amounts[$target] = $after;
            }
            $targetApplied[$target] = true;
            $appliedRecords[] = [
                'code' => (string)$rule['code'],
                'version' => (int)$rule['version'],
                'priority' => (int)$rule['priority'],
                'target' => $target,
                'adjustment_type' => (string)$rule['adjustment_type'],
                'adjustment_value' => (string)$rule['adjustment_value'],
                'before' => $before->getAmount(),
                'after' => $after->getAmount(),
            ];
        }
        return [
            'amounts' => $amounts,
            'subtotal' => $subtotalOverride,
            'matched' => $appliedRecords,
            'suppressed' => $suppressed,
            'freight_rules' => $freightRules,
            'snapshots' => $snapshots,
        ];
    }

    /** 单条规则调整：fixed/amount/discount/factor + 保底/封顶 + 非负 */
    private function applyAdjustment(Money $amount, array $rule, $currency)
    {
        $type = (string)$rule['adjustment_type'];
        $value = (string)$rule['adjustment_value'];
        switch ($type) {
            case 'fixed':
                $result = Money::fromString($value, $currency, '规则一口价');
                break;
            case 'amount':
                $result = Money::of(bcadd($amount->getAmount(), $value, Money::RATIO_SCALE), $currency);
                break;
            case 'discount':
                // 折扣率 = 支付比例（0.9 = 九折）
                $result = $amount->mulByRatio($value);
                break;
            case 'factor':
            default:
                $result = $amount->mulByRatio($value);
                break;
        }
        if ($rule['minimum_amount'] !== null && bccomp($result->getAmount(), (string)$rule['minimum_amount'], Money::SCALE) < 0) {
            $result = Money::of((string)$rule['minimum_amount'], $currency);
        }
        if ($rule['maximum_amount'] !== null && bccomp($result->getAmount(), (string)$rule['maximum_amount'], Money::SCALE) > 0) {
            $result = Money::of((string)$rule['maximum_amount'], $currency);
        }
        if ($result->isNegative()) {
            $result = Money::of('0', $currency);
        }
        return $result;
    }

    /**
     * 费用规则：fixed / per_quantity / percentage，全部命中费用累加；
     * 币种与价格表不一致时先按有效汇率折算；freight 类型受价格规则调整。
     */
    private function applyFees(array $lineContext, array $model, $quantity, Money $goodsDiscounted, array $freightRules, $pricingCurrency)
    {
        $values = $this->conditionValues($lineContext, $model, $quantity, $pricingCurrency);
        $items = [];
        $byType = [];
        $snapshots = [];
        $candidates = $this->loadFeeRules();
        usort($candidates, function ($left, $right) {
            return [$right['priority'], -$right['id']] <=> [$left['priority'], -$left['id']];
        });
        foreach ($candidates as $feeRule) {
            $condition = json_decode((string)$feeRule['condition_json'], true);
            if (!is_array($condition) || !$this->evaluateCondition($condition, $values)) {
                continue;
            }
            $feeSnapshot = [
                'id' => (int)$feeRule['id'],
                'code' => (string)$feeRule['code'],
                'name' => (string)$feeRule['name'],
                'fee_type' => (string)$feeRule['fee_type'],
                'calculation_type' => (string)$feeRule['calculation_type'],
                'value' => (string)$feeRule['value'],
                'currency' => (string)$feeRule['currency'],
                'condition' => json_decode((string)$feeRule['condition_json'], true),
                'priority' => (int)$feeRule['priority'],
                'effective_date' => (string)$feeRule['effective_date'],
                'expiry_date' => $feeRule['expiry_date'] === null ? null : (string)$feeRule['expiry_date'],
                'include_in_margin' => (int)$feeRule['include_in_margin'] === 1,
                'include_in_floor' => (int)$feeRule['include_in_floor'] === 1,
            ];
            switch ((string)$feeRule['calculation_type']) {
                case 'per_quantity':
                    $amount = bcmul((string)$feeRule['value'], $quantity, Money::RATIO_SCALE);
                    break;
                case 'percentage':
                    $amount = bcmul($goodsDiscounted->getAmount(), (string)$feeRule['value'], Money::RATIO_SCALE);
                    break;
                case 'fixed':
                default:
                    $amount = (string)$feeRule['value'];
                    break;
            }
            $feeRate = $this->exchangeRate((string)$feeRule['currency'], $pricingCurrency);
            if ($feeRate['direction'] !== 'identity') {
                $amount = bcmul($amount, $feeRate['rate'], Money::RATIO_SCALE);
            }
            $feeSnapshot['exchange_rate'] = $feeRate;
            $snapshots[] = $feeSnapshot;
            $item = [
                'code' => (string)$feeRule['code'],
                'fee_type' => (string)$feeRule['fee_type'],
                'calculation_type' => (string)$feeRule['calculation_type'],
                'amount' => Money::round($amount, Money::SCALE),
                'include_in_margin' => (int)$feeRule['include_in_margin'] === 1,
                'include_in_floor' => (int)$feeRule['include_in_floor'] === 1,
            ];
            $items[] = $item;
            $byType[$item['fee_type']] = bcadd($byType[$item['fee_type']] ?? '0', $item['amount'], Money::SCALE);
        }

        // 运费受价格规则 freight 调整（对运费小计执行，逐条记录）
        $freightAdjustments = [];
        if (isset($byType['freight']) && bccomp($byType['freight'], '0', Money::SCALE) !== 0) {
            $freight = Money::of($byType['freight'], $pricingCurrency);
            foreach ($freightRules as $rule) {
                $before = $freight;
                $freight = $this->applyAdjustment($freight, $rule, $pricingCurrency);
                $freightAdjustments[] = [
                    'code' => (string)$rule['code'],
                    'version' => (int)$rule['version'],
                    'adjustment_type' => (string)$rule['adjustment_type'],
                    'adjustment_value' => (string)$rule['adjustment_value'],
                    'before' => $before->getAmount(),
                    'after' => $freight->getAmount(),
                ];
            }
            $delta = $freight->sub(Money::of($byType['freight'], $pricingCurrency));
            $byType['freight'] = $freight->getAmount();
            // 运费调整差额并入费用合计
            $items[] = [
                'code' => 'freight_rule_adjustment',
                'fee_type' => 'freight_adjustment',
                'calculation_type' => 'rule',
                'amount' => $delta->getAmount(),
                'include_in_margin' => true,
                'include_in_floor' => true,
            ];
        }

        $total = Money::of('0', $pricingCurrency);
        foreach ($items as $item) {
            $total = $total->add(Money::of($item['amount'], $pricingCurrency));
        }
        return [
            'items' => $items,
            'by_type' => $byType,
            'total' => $total,
            'snapshots' => $snapshots,
            'freight_adjustments' => $freightAdjustments,
        ];
    }

    /** 税率规则匹配：国家/区域/产品类型固定维度多者优先，同级取最新生效 */
    private function matchTaxRule(array $lineContext, array $model)
    {
        $candidates = [];
        foreach ($this->loadTaxRules() as $taxRule) {
            $pinned = 0;
            $matches = true;
            foreach ([
                'country_code' => $lineContext['country_code'],
                'region_code' => $lineContext['region_code'],
                'product_type' => (string)$model['category_code'],
            ] as $field => $contextValue) {
                $ruleValue = trim((string)$taxRule[$field]);
                if ($ruleValue !== '') {
                    $pinned++;
                    if ($ruleValue !== (string)$contextValue) {
                        $matches = false;
                    }
                }
            }
            if ($matches) {
                $candidates[] = ['rule' => $taxRule, 'pinned' => $pinned, 'effective' => (string)$taxRule['effective_date']];
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, function ($left, $right) {
            return [$right['pinned'], $right['effective'], -$left['rule']['id']]
                <=> [$left['pinned'], $left['effective'], -$left['rule']['id']];
        });
        return $candidates[0]['rule'];
    }

    /**
     * 汇率快照：直接汇率优先，其次倒数汇率（1/rate，scale 8 舍入）；
     * 同币种恒等。报价保存后汇率更新不影响历史（P35：快照冻结在轨迹里）。
     */
    private function exchangeRate($fromCurrency, $toCurrency)
    {
        $fromCurrency = strtoupper((string)$fromCurrency);
        $toCurrency = strtoupper((string)$toCurrency);
        if ($fromCurrency === $toCurrency) {
            return [
                'direction' => 'identity',
                'id' => null,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'source_currency' => $fromCurrency,
                'target_currency' => $toCurrency,
                'rate' => '1.00000000',
                'stored_rate' => '1.00000000',
                'source' => 'identity',
                'effective_date' => null,
            ];
        }
        $key = 'rate:' . $fromCurrency . '>' . $toCurrency;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $direct = Db::name('cpq_exchange_rate')
            ->where('source_currency', $fromCurrency)
            ->where('target_currency', $toCurrency)
            ->where('status', 'normal')
            ->where('effective_date', '<=', $this->date)
            ->order('effective_date desc,id asc')
            ->find();
        if ($direct) {
            $rate = [
                'direction' => 'direct',
                'id' => (int)$direct['id'],
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'source_currency' => $fromCurrency,
                'target_currency' => $toCurrency,
                'rate' => (string)$direct['rate'],
                'stored_rate' => (string)$direct['rate'],
                'source' => (string)$direct['source'],
                'effective_date' => (string)$direct['effective_date'],
            ];
            $this->cache[$key] = $rate;
            return $rate;
        }
        $inverse = Db::name('cpq_exchange_rate')
            ->where('source_currency', $toCurrency)
            ->where('target_currency', $fromCurrency)
            ->where('status', 'normal')
            ->where('effective_date', '<=', $this->date)
            ->order('effective_date desc,id asc')
            ->find();
        if ($inverse) {
            $rate = [
                'direction' => 'inverse',
                'id' => (int)$inverse['id'],
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'source_currency' => $toCurrency,
                'target_currency' => $fromCurrency,
                'rate' => Money::round(bcdiv('1', (string)$inverse['rate'], 12), Money::RATIO_SCALE),
                'stored_rate' => (string)$inverse['rate'],
                'source' => (string)$inverse['source'],
                'effective_date' => (string)$inverse['effective_date'],
            ];
            $this->cache[$key] = $rate;
            return $rate;
        }
        throw new PricingException(
            '缺少 ' . $fromCurrency . '→' . $toCurrency . ' 的有效汇率（试算日期 ' . $this->date . '）',
            PricingException::RATE_MISSING,
            ['from_currency' => $fromCurrency, 'to_currency' => $toCurrency, 'date' => $this->date]
        );
    }

    /** 行金额 → 输出币种（汇率快照 + HALF_UP 舍入） */
    private function convertCurrency($outputCurrency, $pricingCurrency, array $amounts)
    {
        $rate = $this->exchangeRate($pricingCurrency, $outputCurrency);
        $converted = [];
        foreach ($amounts as $field => $money) {
            $converted[$field] = Money::round(
                bcmul($money->getAmount(), $rate['rate'], Money::RATIO_SCALE),
                Money::SCALE
            );
        }
        return ['amounts' => $converted, 'rate' => $rate, 'snapshot' => $rate];
    }

    // ------------------------------------------------------------------
    // 条件求值（与 ConfigurationService 同一 DSL 语义，价格域字段）
    // ------------------------------------------------------------------

    private function conditionValues(array $lineContext, array $model, $quantity, $pricingCurrency)
    {
        return [
            'customer_id' => $lineContext['customer_id'],
            'agent_id' => $lineContext['agent_id'],
            'customer_level' => $lineContext['customer_level'],
            'agent_level' => $lineContext['agent_level'],
            'region_code' => $lineContext['region_code'],
            'business_unit' => $lineContext['business_unit'],
            'product_line' => $lineContext['product_line'],
            'model_id' => (int)$model['id'],
            'model_code' => (string)$model['code'],
            'market_scope' => $lineContext['market_scope'],
            'currency' => $lineContext['currency'],
            'pricing_currency' => $pricingCurrency,
            'quantity' => $quantity,
            'min_qty' => $quantity,
            'max_qty' => $quantity,
        ];
    }

    private function evaluateCondition(array $condition, array $values)
    {
        if ($condition === []) {
            return true;
        }
        if (isset($condition['all'])) {
            foreach ((array)$condition['all'] as $child) {
                if (!$this->evaluateCondition((array)$child, $values)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($condition['any'])) {
            foreach ((array)$condition['any'] as $child) {
                if ($this->evaluateCondition((array)$child, $values)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($condition['not'])) {
            return !$this->evaluateCondition((array)$condition['not'], $values);
        }

        $field = (string)($condition['field'] ?? '');
        $operator = strtolower((string)($condition['operator'] ?? '='));
        $expected = $condition['value'] ?? null;
        $actual = $this->readPath($values, $field);
        switch ($operator) {
            case '=':
            case 'eq':
                return $actual == $expected;
            case '!=':
            case 'neq':
                return $actual != $expected;
            case '>':
                return is_numeric($actual) && is_numeric($expected) && bccomp((string)$actual, (string)$expected, 8) > 0;
            case '>=':
                return is_numeric($actual) && is_numeric($expected) && bccomp((string)$actual, (string)$expected, 8) >= 0;
            case '<':
                return is_numeric($actual) && is_numeric($expected) && bccomp((string)$actual, (string)$expected, 8) < 0;
            case '<=':
                return is_numeric($actual) && is_numeric($expected) && bccomp((string)$actual, (string)$expected, 8) <= 0;
            case 'in':
                return in_array($actual, (array)$expected, true);
            case 'not_in':
                return !in_array($actual, (array)$expected, true);
            case 'contains':
            case 'selected':
                return is_array($actual) && in_array($expected, $actual, true);
            case 'empty':
                return $actual === null || $actual === '' || $actual === [];
            case 'not_empty':
                return !($actual === null || $actual === '' || $actual === []);
            default:
                return false;
        }
    }

    private function readPath(array $values, $path)
    {
        $segments = explode('.', (string)$path);
        $current = $values;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    // ------------------------------------------------------------------
    // 主数据加载（实例级缓存）
    // ------------------------------------------------------------------

    private function loadModel($modelId)
    {
        $key = 'model:' . (int)$modelId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $model = Db::name('cpq_product_model')->where('id', (int)$modelId)->find();
        if (!$model || (string)$model['status'] !== 'published') {
            throw new PricingException('产品型号不存在或未发布：' . (int)$modelId, PricingException::INVALID_INPUT, ['model_id' => (int)$modelId]);
        }
        if (($model['effective_date'] && $model['effective_date'] > $this->date)
            || ($model['expiry_date'] && $model['expiry_date'] < $this->date)) {
            throw new PricingException('产品型号在试算日期不在生效期内：' . (string)$model['code'], PricingException::INVALID_INPUT, ['model_code' => (string)$model['code']]);
        }
        $series = Db::name('cpq_product_series')->where('id', (int)$model['series_id'])->find();
        if (!$series || (string)$series['status'] !== 'published') {
            throw new PricingException('产品系列不存在或未发布：' . (int)$model['series_id'], PricingException::INVALID_INPUT);
        }
        $row = [
            'id' => (int)$model['id'],
            'code' => (string)$model['code'],
            'version' => (int)$model['version'],
            'category_code' => (string)$model['category_code'],
            'unit' => (string)$model['unit'],
            'series_code' => (string)$series['code'],
            'business_unit' => (string)$series['business_unit'],
            'product_line' => (string)$series['product_line'],
        ];
        $this->cache[$key] = $row;
        return $row;
    }

    private function loadSchema($modelId)
    {
        $key = 'schema:' . (int)$modelId;
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $this->schemaRepository->getPublishedSchema((int)$modelId);
        }
        return $this->cache[$key];
    }

    private function loadBooks()
    {
        if (!isset($this->cache['books'])) {
            $this->cache['books'] = Db::name('cpq_price_book')
                ->where('status', 'published')
                ->where('effective_date', '<=', $this->date)
                ->where(function ($query) {
                    $query->whereNull('expiry_date')->whereOr('expiry_date', '>=', $this->date);
                })
                ->order('id asc')
                ->select();
        }
        return $this->cache['books'];
    }

    private function loadEntries($bookId)
    {
        $key = 'entries:' . (int)$bookId;
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = Db::name('cpq_price_entry')
                ->where('price_book_id', (int)$bookId)
                ->order('id asc')
                ->select();
        }
        return $this->cache[$key];
    }

    /** 数量分段条目：min_qty <= 数量 <= max_qty(空=不限)，取 min_qty 最大的分段 */
    private function pickEntry(array $entries, $targetType, $targetId, $quantity)
    {
        $best = null;
        foreach ($entries as $entry) {
            if ((string)$entry['target_type'] !== $targetType || (int)$entry['target_id'] !== (int)$targetId) {
                continue;
            }
            if (bccomp((string)$entry['min_qty'], (string)$quantity, Money::SCALE) > 0) {
                continue;
            }
            if ($entry['max_qty'] !== null && bccomp((string)$entry['max_qty'], (string)$quantity, Money::SCALE) < 0) {
                continue;
            }
            if ($best === null || bccomp((string)$entry['min_qty'], (string)$best['min_qty'], Money::SCALE) > 0) {
                $best = $entry;
            }
        }
        return $best;
    }

    private function loadPolicies($targetType, $targetId, $currency, $unit)
    {
        $key = 'policies:' . $targetType . ':' . $targetId . ':' . $currency . ':' . $unit;
        if (!isset($this->cache[$key])) {
            $this->cache[$key] = Db::name('cpq_price_policy')
                ->where('target_type', $targetType)
                ->where('target_id', (int)$targetId)
                ->where('currency', $currency)
                ->where('unit', $unit)
                ->where('status', 'published')
                ->where('effective_date', '<=', $this->date)
                ->where(function ($query) {
                    $query->whereNull('expiry_date')->whereOr('expiry_date', '>=', $this->date);
                })
                ->order('id asc')
                ->select();
        }
        return $this->cache[$key];
    }

    private function loadPriceRules()
    {
        if (!isset($this->cache['price_rules'])) {
            $this->cache['price_rules'] = Db::name('cpq_price_rule')
                ->where('status', 'published')
                ->where('effective_date', '<=', $this->date)
                ->where(function ($query) {
                    $query->whereNull('expiry_date')->whereOr('expiry_date', '>=', $this->date);
                })
                ->order('id asc')
                ->select();
        }
        return $this->cache['price_rules'];
    }

    private function loadTaxRules()
    {
        if (!isset($this->cache['tax_rules'])) {
            $this->cache['tax_rules'] = Db::name('cpq_tax_rule')
                ->where('status', 'normal')
                ->where('effective_date', '<=', $this->date)
                ->where(function ($query) {
                    $query->whereNull('expiry_date')->whereOr('expiry_date', '>=', $this->date);
                })
                ->order('id asc')
                ->select();
        }
        return $this->cache['tax_rules'];
    }

    private function loadFeeRules()
    {
        if (!isset($this->cache['fee_rules'])) {
            $this->cache['fee_rules'] = Db::name('cpq_fee_rule')
                ->where('status', 'normal')
                ->where('effective_date', '<=', $this->date)
                ->where(function ($query) {
                    $query->whereNull('expiry_date')->whereOr('expiry_date', '>=', $this->date);
                })
                ->order('id asc')
                ->select();
        }
        return $this->cache['fee_rules'];
    }

    // ------------------------------------------------------------------
    // 行内辅助
    // ------------------------------------------------------------------

    /** 配置组选中项 → 选项定价清单（单选/多选；数量/文本/只读组不参与定价） */
    private function collectSelectedOptions(array $schema, array $configuration)
    {
        $groupsByCode = [];
        foreach ($schema['groups'] as $group) {
            $groupsByCode[(string)$group['code']] = $group;
        }
        $selected = [];
        foreach ($configuration as $groupCode => $value) {
            $group = $groupsByCode[(string)$groupCode] ?? null;
            if ($group === null || !in_array($group['input_type'], ['single', 'multiple'], true)) {
                continue;
            }
            $optionsByCode = [];
            foreach ($group['options'] as $option) {
                $optionsByCode[(string)$option['code']] = $option;
            }
            foreach (is_array($value) ? $value : [$value] as $optionCode) {
                $option = $optionsByCode[(string)$optionCode] ?? null;
                if ($option === null) {
                    continue;
                }
                $selected[] = [
                    'group_code' => (string)$group['code'],
                    'option_code' => (string)$option['code'],
                    'option_id' => (int)$option['id'],
                    'default_qty' => $option['default_qty'] !== null && (string)$option['default_qty'] !== ''
                        ? (string)$option['default_qty'] : '1',
                ];
            }
        }
        return $selected;
    }

    private function normalizeAccessories($accessories, $lineNo)
    {
        if (!is_array($accessories) || $accessories === []) {
            return [];
        }
        $ids = [];
        foreach ($accessories as $accessory) {
            if (!is_array($accessory) || (int)($accessory['id'] ?? 0) <= 0) {
                throw new PricingException('第' . $lineNo . '行配件/服务缺少 id', PricingException::INVALID_INPUT, ['line' => $lineNo]);
            }
            $ids[] = (int)$accessory['id'];
        }
        $rows = Db::name('cpq_accessory_service')
            ->where('id', 'in', $ids)
            ->where('status', 'normal')
            ->select();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $normalized = [];
        foreach ($accessories as $accessory) {
            $id = (int)$accessory['id'];
            if (!isset($byId[$id])) {
                throw new PricingException('第' . $lineNo . '行配件/服务不存在或已停用：' . $id, PricingException::INVALID_INPUT, ['line' => $lineNo, 'accessory_id' => $id]);
            }
            $normalized[] = [
                'id' => $id,
                'code' => (string)$byId[$id]['code'],
                'quantity' => Money::normalizeQuantity($accessory['quantity'] ?? 1, '配件/服务数量'),
            ];
        }
        return $normalized;
    }

    private function approvalLevelOf($classification)
    {
        switch ($classification) {
            case 'line_approval':
                return 'line';
            case 'company_approval':
                return 'company';
            case 'forbidden':
                return 'forbidden';
            default:
                return 'none';
        }
    }

    private function sumUnits(array $unitAmounts)
    {
        return $unitAmounts['base']
            ->add($unitAmounts['option'])
            ->add($unitAmounts['service']);
    }

    private function step($name, Money $output, array $context = [])
    {
        return [
            'step' => $name,
            'output' => $output->getAmount(),
        ] + $context;
    }

    private function bookSnapshot(array $book)
    {
        return [
            'id' => (int)$book['id'],
            'code' => (string)$book['code'],
            'name' => (string)$book['name'],
            'version' => (int)$book['version'],
            'currency' => (string)$book['currency'],
            'tax_mode' => (string)$book['tax_mode'],
            'priority' => (int)$book['priority'],
            'company' => (string)$book['company'],
            'business_unit' => (string)$book['business_unit'],
            'market_scope' => (string)$book['market_scope'],
            'effective_date' => (string)$book['effective_date'],
            'expiry_date' => $book['expiry_date'] === null ? null : (string)$book['expiry_date'],
        ];
    }

    private function entrySnapshot(array $entry)
    {
        return [
            'id' => (int)$entry['id'],
            'target_type' => (string)$entry['target_type'],
            'target_id' => (int)$entry['target_id'],
            'amount' => (string)$entry['amount'],
            'unit' => (string)$entry['unit'],
            'min_qty' => (string)$entry['min_qty'],
            'max_qty' => $entry['max_qty'] === null ? null : (string)$entry['max_qty'],
        ];
    }

    private function policySnapshot(array $policy)
    {
        return [
            'id' => (int)$policy['id'],
            'code' => (string)$policy['code'],
            'name' => (string)$policy['name'],
            'version' => (int)$policy['version'],
            'guide_price' => (string)$policy['guide_price'],
            'line_floor' => (string)$policy['line_floor'],
            'company_floor' => (string)$policy['company_floor'],
            'cost' => (string)$policy['cost'],
            'priority' => (int)$policy['priority'],
            'currency' => (string)$policy['currency'],
            'unit' => (string)$policy['unit'],
            'dimensions' => [
                'company' => (string)$policy['company'],
                'business_unit' => (string)$policy['business_unit'],
                'market_scope' => (string)$policy['market_scope'],
                'region_code' => (string)$policy['region_code'],
                'customer_level' => (string)$policy['customer_level'],
                'agent_level' => (string)$policy['agent_level'],
                'customer_id' => $policy['customer_id'] === null ? null : (int)$policy['customer_id'],
                'agent_id' => $policy['agent_id'] === null ? null : (int)$policy['agent_id'],
                'product_line' => (string)$policy['product_line'],
            ],
            'effective_date' => (string)$policy['effective_date'],
            'expiry_date' => $policy['expiry_date'] === null ? null : (string)$policy['expiry_date'],
        ];
    }

    private function ruleSnapshot(array $rule, $state)
    {
        return [
            'id' => (int)$rule['id'],
            'code' => (string)$rule['code'],
            'name' => (string)$rule['name'],
            'version' => (int)$rule['version'],
            'priority' => (int)$rule['priority'],
            'adjustment_type' => (string)$rule['adjustment_type'],
            'adjustment_target' => (string)$rule['adjustment_target'],
            'adjustment_value' => (string)$rule['adjustment_value'],
            'can_stack' => (int)$rule['can_stack'] === 1,
            'exclusive_group' => (string)$rule['exclusive_group'],
            'condition' => json_decode((string)$rule['condition_json'], true),
            'minimum_amount' => $rule['minimum_amount'] === null ? null : (string)$rule['minimum_amount'],
            'maximum_amount' => $rule['maximum_amount'] === null ? null : (string)$rule['maximum_amount'],
            'effective_date' => (string)$rule['effective_date'],
            'expiry_date' => $rule['expiry_date'] === null ? null : (string)$rule['expiry_date'],
            'state' => $state,
        ];
    }

    private function taxSnapshot(array $taxRule)
    {
        return [
            'id' => (int)$taxRule['id'],
            'code' => (string)$taxRule['code'],
            'country_code' => (string)$taxRule['country_code'],
            'region_code' => (string)$taxRule['region_code'],
            'product_type' => (string)$taxRule['product_type'],
            'rate' => (string)$taxRule['rate'],
            'effective_date' => (string)$taxRule['effective_date'],
            'expiry_date' => $taxRule['expiry_date'] === null ? null : (string)$taxRule['expiry_date'],
        ];
    }

    /** 规范化哈希：ksort 递归 + JSON_UNESCAPED_UNICODE + sha256 */
    private function canonicalHash($value)
    {
        return hash('sha256', json_encode($this->sortKeysRecursive($value), JSON_UNESCAPED_UNICODE));
    }

    private function sortKeysRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortKeysRecursive($item);
        }
        ksort($sorted);
        return $sorted;
    }

    private function maskRecursive($value, array $sensitiveKeys)
    {
        if (!is_array($value)) {
            return $value;
        }
        $masked = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $sensitiveKeys, true)) {
                continue;
            }
            $masked[$key] = $this->maskRecursive($item, $sensitiveKeys);
        }
        return $masked;
    }
}
