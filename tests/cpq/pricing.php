<?php
/**
 * M2 确定性定价引擎测试（GYTAI-68，方案 §7.2/§7.3、P36、Q-001～Q-008）。
 *
 * 覆盖：Money 值对象（HALF_UP 舍入/不可变/币种校验）、固定流水线与
 * price_trace 步骤、八级策略匹配优先级与冲突/缺失维度、价格规则
 * （固定价/加减/折扣/系数/互斥组/叠加抑制/保底封顶/运费调整）、数量分段、
 * 渠道折扣（客户等级/代理等级）、手工折扣理由、费用（固定/按量/比例/
 * 计入控制价与毛利）、税（价外/价内/维度具体度）、汇率快照（直接/倒数/
 * 生效日选择/缺失）、三层控制价分级与边界（Q-001~Q-004）、多行最严格
 * 审批（Q-007）、发布后按日期重算不变（Q-008）、确定性（同输入同哈希）、
 * 角色脱敏与敏感查看审计（越权解释）、提交硬阻断、100 行性能 < 2s。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/pricing.php
 *
 * 使用独立临时库 cpq_m2_pricing_test（执行 install.sql 建最终结构），
 * 结束时自动 DROP；所有种子数据编码带 CPQ-TEST- 前缀。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\library\cpq\Money;
use app\common\library\cpq\PricingException;
use app\common\service\cpq\PricingService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m2_pricing_test';
const PREFIX = 'fa_';

$assertCount = 0;
function check($condition, $message)
{
    global $assertCount;
    $assertCount++;
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo "[ok] {$message}\n";
}

function expectPricingError(callable $fn, $businessCode, $message)
{
    global $assertCount;
    $assertCount++;
    try {
        $fn();
    } catch (PricingException $exception) {
        if ($exception->getBusinessCode() !== $businessCode) {
            throw new RuntimeException('[FAIL] ' . $message . ' —— 业务码期望 ' . $businessCode
                . ' 实际 ' . $exception->getBusinessCode());
        }
        echo "[ok] {$message}（{$exception->getBusinessCode()}：{$exception->getMessage()}）\n";
        return $exception;
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出 ' . $businessCode . ' 但未抛出');
}

/** 递归断言数组中不含指定键（脱敏验证） */
function assertNoKeysAnywhere($value, array $forbiddenKeys, $path = '$')
{
    if (!is_array($value)) {
        return;
    }
    foreach ($value as $key => $item) {
        if (is_string($key) && in_array($key, $forbiddenKeys, true)) {
            throw new RuntimeException('[FAIL] 脱敏结果中出现禁止字段 ' . $path . '.' . $key);
        }
        assertNoKeysAnywhere($item, $forbiddenKeys, $path . '.' . $key);
    }
}

/** ksort 递归副本（哈希复算校验用） */
function ksortRecursiveCopy($value)
{
    if (!is_array($value)) {
        return $value;
    }
    $sorted = [];
    foreach ($value as $key => $item) {
        $sorted[$key] = ksortRecursiveCopy($item);
    }
    ksort($sorted);
    return $sorted;
}

/** 从行轨迹中取指定步骤 */
function stepOf(array $line, $name)
{
    foreach ($line['price_trace']['steps'] as $step) {
        if ($step['step'] === $name) {
            return $step;
        }
    }
    return null;
}

/** 命中策略（floor_comparison 步骤） */
function matchedPolicyOf(array $result)
{
    $step = stepOf($result['lines'][0], 'floor_comparison');
    return $step !== null ? $step['policy'] : null;
}

// ---------------------------------------------------------------------
// 临时库：建库 → 执行 install.sql → 指向临时库；结束时 DROP
// ---------------------------------------------------------------------
$dbConfig = Config::get('database');
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbConfig['hostname'], $dbConfig['hostport'] ?: 3306);
$pdo = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$pdo->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `' . TEST_DB . '`');
$installSql = file_get_contents(dirname(__DIR__, 2) . '/database/cpq/install.sql');
$pdo->exec(str_replace('__PREFIX__', PREFIX, $installSql));

register_shutdown_function(function () use ($rootDsn, $dbConfig) {
    try {
        $cleanup = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cleanup->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
        echo "[cleanup] 临时库 " . TEST_DB . " 已删除\n";
    } catch (\Throwable $exception) {
        echo "[cleanup] 临时库清理失败：" . $exception->getMessage() . "\n";
    }
});

Config::set('database.database', TEST_DB);
check(Db::name('cpq_price_policy')->count() === 0, '临时库已就位（fa_cpq_price_policy 为空）');

$now = time();

// ---------------------------------------------------------------------
// 种子：产品 / 选项 / 配件
// ---------------------------------------------------------------------
$seriesId = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-TEST-SERIES', 'name' => '定价测试系列', 'business_unit' => 'CPQ-TEST-BU', 'product_line' => 'CPQ-TEST-LINE',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$series2Id = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-TEST-SERIES-2', 'name' => '定价测试系列2', 'business_unit' => 'CPQ-TEST-BU-2', 'product_line' => 'CPQ-TEST-LINE-2',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$models = [];
foreach ([
    'CPQ-TEST-MODEL-A' => $seriesId,   // 主流水线型号（三层 120000/108000/96000）
    'CPQ-TEST-MODEL-B' => $seriesId,   // 无价格条目（ENTRY_MISSING）
    'CPQ-TEST-MODEL-C' => $seriesId,   // 八级优先级阶梯
    'CPQ-TEST-MODEL-D' => $series2Id,  // 公司默认策略（不同产品线）
    'CPQ-TEST-MODEL-F' => $seriesId,   // 仅华东区域策略（Q-006）
    'CPQ-TEST-MODEL-G' => $seriesId,   // 仅含税价目有条目（价内税）
] as $code => $sid) {
    $models[$code] = (int)Db::name('cpq_product_model')->insertGetId([
        'series_id' => $sid, 'code' => $code, 'name' => $code, 'category_code' => 'CPQ-TEST-CAT',
        'base_item_code' => 'BASE-' . $code, 'unit' => 'set',
        'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
    ]);
}

$powerGroupId = (int)Db::name('cpq_option_group')->insertGetId([
    'code' => 'CPQ-TEST-G-POWER', 'name' => '功率等级', 'input_type' => 'single', 'is_required' => 0,
    'affects_price' => 1, 'affects_bom' => 0, 'sort' => 10, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_option_value')->insert([
    'group_id' => $powerGroupId, 'code' => 'std', 'name' => '标准功率', 'material_code' => 'POWER-STD',
    'default_qty' => 1, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$optionHighId = (int)Db::name('cpq_option_value')->insertGetId([
    'group_id' => $powerGroupId, 'code' => 'high', 'name' => '高功率', 'material_code' => 'POWER-HIGH',
    'default_qty' => 1, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_model_option_group')->insert([
    'model_id' => $models['CPQ-TEST-MODEL-A'], 'group_id' => $powerGroupId,
    'sort' => 10, 'is_visible' => 1, 'is_required' => 0, 'createtime' => $now, 'updatetime' => $now,
]);
$accessoryInstallId = (int)Db::name('cpq_accessory_service')->insertGetId([
    'code' => 'CPQ-TEST-ACC-INSTALL', 'name' => '安装调试服务', 'type' => 'service', 'unit' => 'service',
    'tax_category' => 'service', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$accessoryNoPriceId = (int)Db::name('cpq_accessory_service')->insertGetId([
    'code' => 'CPQ-TEST-ACC-NOPRICE', 'name' => '无价格条目配件', 'type' => 'accessory', 'unit' => 'item',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);

// ---------------------------------------------------------------------
// 种子：客户 / 等级 / 区域 / 代理
// ---------------------------------------------------------------------
$levelVipId = (int)Db::name('cpq_customer_level')->insertGetId([
    'code' => 'CPQ-TEST-LV-VIP', 'name' => 'VIP', 'sort' => 10, 'default_discount' => 0.950000,
    'market_scope' => 'all', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$levelStdId = (int)Db::name('cpq_customer_level')->insertGetId([
    'code' => 'CPQ-TEST-LV-STD', 'name' => '标准', 'sort' => 20, 'default_discount' => 1.000000,
    'market_scope' => 'all', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$agentLevelId = (int)Db::name('cpq_agent_level')->insertGetId([
    'code' => 'CPQ-TEST-ALV-CORE', 'name' => '核心代理', 'sort' => 10, 'default_discount' => 0.850000,
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$regionIds = [];
foreach (['CPQ-TEST-REGION-EAST' => '华东', 'CPQ-TEST-REGION-NORTH' => '华北', 'CPQ-TEST-REGION-SEA' => '东南亚'] as $code => $name) {
    $regionIds[$code] = (int)Db::name('cpq_region')->insertGetId([
        'code' => $code, 'name' => $name, 'parent_id' => 0, 'path' => '/', 'level' => 1,
        'default_currency' => 'CNY', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
    ]);
    Db::name('cpq_region')->where('id', $regionIds[$code])->update(['path' => '/' . $regionIds[$code] . '/']);
}

$customerVipId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-TEST-CUST-VIP', 'name' => 'VIP客户', 'type' => 'direct', 'country_code' => 'CN',
    'region_id' => $regionIds['CPQ-TEST-REGION-EAST'], 'customer_level_id' => $levelVipId, 'default_currency' => 'CNY',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$customerStdId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-TEST-CUST-STD', 'name' => '标准客户', 'type' => 'direct', 'country_code' => 'CN',
    'region_id' => $regionIds['CPQ-TEST-REGION-NORTH'], 'customer_level_id' => $levelStdId, 'default_currency' => 'CNY',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$customerIntlId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-TEST-CUST-INTL', 'name' => '国际客户', 'type' => 'direct', 'country_code' => 'SG',
    'region_id' => $regionIds['CPQ-TEST-REGION-SEA'], 'customer_level_id' => $levelStdId, 'default_currency' => 'USD',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$agentCustomerId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-TEST-CUST-AGENT-TERM', 'name' => '代理终端客户', 'type' => 'terminal', 'country_code' => 'CN',
    'region_id' => $regionIds['CPQ-TEST-REGION-NORTH'], 'customer_level_id' => $levelStdId, 'default_currency' => 'CNY',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$agentId = (int)Db::name('cpq_agent')->insertGetId([
    'code' => 'CPQ-TEST-AGENT-01', 'customer_id' => $agentCustomerId, 'agent_level_id' => $agentLevelId,
    'auth_start_date' => '2026-01-01', 'auth_end_date' => '2026-12-31',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_customer')->where('id', $agentCustomerId)->update(['agent_id' => $agentId]);

// ---------------------------------------------------------------------
// 种子：价格表 / 条目 / 策略 / 规则 / 税 / 费 / 汇率
// ---------------------------------------------------------------------
$bookIds = [];
foreach ([
    'CPQ-TEST-BOOK-CNY' => ['测试国内价目', 'CPQ-TEST-BU', 'domestic', 'CNY', 'tax_exclusive', 10],
    'CPQ-TEST-BOOK-USD' => ['测试国际价目', 'CPQ-TEST-BU', 'international', 'USD', 'tax_exclusive', 10],
    'CPQ-TEST-BOOK-INTL-CNY' => ['测试国际人民币价目', 'CPQ-TEST-BU', 'international', 'CNY', 'tax_exclusive', 5],
    'CPQ-TEST-BOOK-ALL' => ['测试通用价目', '', 'all', 'CNY', 'tax_exclusive', 1],
    'CPQ-TEST-BOOK-INCL' => ['测试含税价目', 'CPQ-TEST-BU', 'domestic', 'CNY', 'tax_inclusive', 1],
] as $code => $def) {
    $bookIds[$code] = (int)Db::name('cpq_price_book')->insertGetId([
        'code' => $code, 'name' => $def[0], 'company' => '', 'business_unit' => $def[1],
        'market_scope' => $def[2], 'currency' => $def[3], 'tax_mode' => $def[4], 'priority' => $def[5],
        'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'version' => 1, 'status' => 'published',
        'createtime' => $now, 'updatetime' => $now,
    ]);
}
$entry = function ($bookId, $targetType, $targetId, $amount, $minQty = 0, $unit = 'set') use ($now) {
    return (int)Db::name('cpq_price_entry')->insertGetId([
        'price_book_id' => $bookId, 'target_type' => $targetType, 'target_id' => $targetId,
        'amount' => $amount, 'unit' => $unit, 'min_qty' => $minQty, 'max_qty' => null,
        'createtime' => $now, 'updatetime' => $now,
    ]);
};
$bookCnyId = $bookIds['CPQ-TEST-BOOK-CNY'];
$entry($bookCnyId, 'model', $models['CPQ-TEST-MODEL-A'], 120000);
$entry($bookCnyId, 'model', $models['CPQ-TEST-MODEL-A'], 110000, 10);        // 数量分段：10+ 台
$entry($bookCnyId, 'option', $optionHighId, 8000);
$entry($bookCnyId, 'accessory_service', $accessoryInstallId, 5000, 0, 'service');
$entry($bookCnyId, 'model', $models['CPQ-TEST-MODEL-C'], 100000);
$entry($bookCnyId, 'model', $models['CPQ-TEST-MODEL-F'], 100000);
$entry($bookIds['CPQ-TEST-BOOK-INTL-CNY'], 'model', $models['CPQ-TEST-MODEL-C'], 100000);
$entry($bookIds['CPQ-TEST-BOOK-ALL'], 'model', $models['CPQ-TEST-MODEL-D'], 90000);
$entry($bookIds['CPQ-TEST-BOOK-INCL'], 'model', $models['CPQ-TEST-MODEL-G'], 100000);
$entry($bookIds['CPQ-TEST-BOOK-USD'], 'model', $models['CPQ-TEST-MODEL-A'], 20000);

$policy = function (array $dims, $guide, $line, $company, $cost, $priority = 10, $effective = '2026-01-01', $expiry = '2026-12-31') use ($now, $models) {
    static $seq = 0;
    $seq++;
    $row = array_merge([
        'code' => 'CPQ-TEST-POLICY-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT),
        'name' => '策略' . $seq,
        'dimension_key' => '',
        'company' => '', 'business_unit' => '', 'market_scope' => 'all', 'region_code' => '',
        'customer_level' => '', 'agent_level' => '', 'customer_id' => null, 'agent_id' => null, 'product_line' => '',
        'target_type' => 'model', 'target_id' => $models['CPQ-TEST-MODEL-A'], 'currency' => 'CNY', 'unit' => 'set',
        'guide_price' => $guide, 'line_floor' => $line, 'company_floor' => $company, 'cost' => $cost,
        'priority' => $priority, 'effective_date' => $effective, 'expiry_date' => $expiry,
        'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
    ], $dims);
    $row['dimension_key'] = app\common\service\cpq\PricePolicyService::dimensionKey($row);
    return (int)Db::name('cpq_price_policy')->insertGetId($row);
};

// 型号A：三层 120000/108000/96000，成本 60000（Q-001~Q-004 主策略）
$policy([], 120000, 108000, 96000, 60000, 10, '2026-01-01', '2026-10-31');
// 型号A：11 月起生效的新版本策略（Q-008 按日期重算）
$policy([], 130000, 117000, 104000, 60000, 10, '2026-11-01', '2026-12-31');
// 型号C 八级阶梯
$ladder = function (array $dims, $guide) use ($policy, $models) {
    return $policy(array_merge($dims, ['target_id' => $models['CPQ-TEST-MODEL-C']]), $guide, $guide - 2000, $guide - 4000, $guide / 2);
};
$ladder(['customer_id' => $customerVipId], 100000);                                                // L1 指定客户
$ladder(['agent_id' => $agentId], 100500);                                                         // L2 指定代理商
$ladder(['customer_level' => 'CPQ-TEST-LV-VIP', 'region_code' => 'CPQ-TEST-REGION-EAST'], 101000); // L3 等级+区域
$ladder(['customer_level' => 'CPQ-TEST-LV-VIP'], 102000);                                          // L4 等级
$ladder(['region_code' => 'CPQ-TEST-REGION-EAST'], 103000);                                        // L5 区域
$ladder(['market_scope' => 'domestic'], 104000);                                                   // L6 国内/国际
$ladder(['product_line' => 'CPQ-TEST-LINE'], 105000);                                              // L7 产品线
$ladder([], 106000);                                                                               // L8 公司默认
// 型号D：公司默认（不同产品线）
$policy(['target_id' => $models['CPQ-TEST-MODEL-D']], 90000, 81000, 72000, 45000);
// 型号F：仅华东区域策略（Q-006 缺失维度）
$policy(['target_id' => $models['CPQ-TEST-MODEL-F'], 'region_code' => 'CPQ-TEST-REGION-EAST'], 100000, 90000, 80000, 50000);
// 型号G：含税价目策略
$policy(['target_id' => $models['CPQ-TEST-MODEL-G']], 100000, 90000, 80000, 50000);
// 型号A USD 策略（Q-005 国际价目）
$policy(['target_id' => $models['CPQ-TEST-MODEL-A'], 'currency' => 'USD', 'market_scope' => 'international'], 20000, 18000, 16000, 10000);

$priceRule = function ($code, $condition, $type, $target, $value, $extras = []) use ($now) {
    return (int)Db::name('cpq_price_rule')->insertGetId(array_merge([
        'code' => $code, 'name' => $code, 'condition_json' => $condition,
        'adjustment_type' => $type, 'adjustment_target' => $target, 'adjustment_value' => $value,
        'can_stack' => 0, 'exclusive_group' => '', 'minimum_amount' => null, 'maximum_amount' => null,
        'priority' => 10, 'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
        'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
    ], $extras));
};
$eastModelA = json_encode(['all' => [
    ['field' => 'model_id', 'operator' => '=', 'value' => $models['CPQ-TEST-MODEL-A']],
    ['field' => 'region_code', 'operator' => '=', 'value' => 'CPQ-TEST-REGION-EAST'],
]]);
$priceRule('CPQ-TEST-RULE-BASE-DISC', $eastModelA, 'discount', 'base', 0.95000000);
$priceRule('CPQ-TEST-RULE-OPT-ADD', $eastModelA, 'amount', 'option', 500);
$priceRule('CPQ-TEST-RULE-FACTOR-SVC', $eastModelA, 'factor', 'service', 1.10000000);
$priceRule('CPQ-TEST-RULE-FREIGHT', $eastModelA, 'amount', 'freight', -100, ['priority' => 30]);
$priceRule('CPQ-TEST-RULE-EXCL-HI', $eastModelA, 'amount', 'subtotal', -1000, ['exclusive_group' => 'CPQ-TEST-GRP', 'priority' => 20]);
$priceRule('CPQ-TEST-RULE-EXCL-LO', $eastModelA, 'amount', 'subtotal', -5000, ['exclusive_group' => 'CPQ-TEST-GRP', 'priority' => 10]);
$priceRule('CPQ-TEST-RULE-FIXED', $eastModelA, 'fixed', 'subtotal', 100000, ['can_stack' => 1]);
$priceRule('CPQ-TEST-RULE-STACK-A', $eastModelA, 'amount', 'subtotal', -100, ['can_stack' => 1]);
$priceRule('CPQ-TEST-RULE-STACK-B', $eastModelA, 'amount', 'subtotal', -200, ['can_stack' => 1]);
$priceRule('CPQ-TEST-RULE-MINMAX', $eastModelA, 'discount', 'subtotal', 0.50000000, ['minimum_amount' => 60000, 'can_stack' => 1, 'priority' => 5]);
$priceRule('CPQ-TEST-RULE-NONSTACK', $eastModelA, 'amount', 'subtotal', -300, ['priority' => 1]);
$priceRule('CPQ-TEST-RULE-QTY10', json_encode(['all' => [
    ['field' => 'model_id', 'operator' => '=', 'value' => $models['CPQ-TEST-MODEL-A']],
    ['field' => 'quantity', 'operator' => '>=', 'value' => 10],
]]), 'discount', 'subtotal', 0.90000000);

$taxRule = function ($code, $country, $region, $rate) use ($now) {
    Db::name('cpq_tax_rule')->insert([
        'code' => $code, 'country_code' => $country, 'region_code' => $region, 'product_type' => '',
        'rate' => $rate, 'effective_date' => '2026-01-01', 'status' => 'normal',
        'createtime' => $now, 'updatetime' => $now,
    ]);
};
$taxRule('CPQ-TEST-TAX-CN', 'CN', '', 0.130000);
$taxRule('CPQ-TEST-TAX-CN-EAST', 'CN', 'CPQ-TEST-REGION-EAST', 0.090000);

$feeRule = function ($code, $feeType, $condition, $calcType, $value, $inMargin = 1, $inFloor = 1) use ($now) {
    Db::name('cpq_fee_rule')->insert([
        'code' => $code, 'name' => $code, 'fee_type' => $feeType, 'condition_json' => $condition,
        'calculation_type' => $calcType, 'value' => $value, 'currency' => 'CNY',
        'include_in_margin' => $inMargin, 'include_in_floor' => $inFloor, 'priority' => 10,
        'effective_date' => '2026-01-01', 'expiry_date' => null, 'status' => 'normal',
        'createtime' => $now, 'updatetime' => $now,
    ]);
};
$eastFee = json_encode(['field' => 'region_code', 'operator' => '=', 'value' => 'CPQ-TEST-REGION-EAST']);
$feeRule('CPQ-TEST-FEE-FREIGHT', 'freight', $eastFee, 'fixed', 500.00000000);
$feeRule('CPQ-TEST-FEE-INSURANCE', 'insurance', $eastFee, 'percentage', 0.01000000, 1, 0);
$feeRule('CPQ-TEST-FEE-INSTALL', 'installation', $eastFee, 'per_quantity', 300.00000000);

Db::name('cpq_exchange_rate')->insert([
    'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => 7.10000000, 'source' => 'manual',
    'effective_date' => '2026-01-01', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_exchange_rate')->insert([
    'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => 7.20000000, 'source' => 'manual',
    'effective_date' => '2026-06-01', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);

// ---------------------------------------------------------------------
// 1. Money 值对象（明确舍入策略）
// ---------------------------------------------------------------------
echo "\n== 1. Money 值对象 ==\n";
check(Money::round('0.00005', 4) === '0.0001', 'HALF_UP：0.00005 → 0.0001');
check(Money::round('0.00004', 4) === '0.0000', 'HALF_UP：0.00004 → 0.0000');
check(Money::round('-0.00005', 4) === '-0.0001', '负数对称舍入：-0.00005 → -0.0001');
check(Money::fromString('1.005', 'CNY')->getAmount() === '1.0050', '金额规范化：1.005 → 1.0050');
try {
    Money::fromString('-1', 'CNY');
    throw new RuntimeException('[FAIL] 负数金额应被拒绝');
} catch (InvalidArgumentException $exception) {
    check(true, '负数金额被拒绝');
}
try {
    Money::fromString('abc', 'CNY');
    throw new RuntimeException('[FAIL] 非数值金额应被拒绝');
} catch (InvalidArgumentException $exception) {
    check(true, '非数值金额被拒绝');
}
$money100 = Money::of('100', 'CNY');
check($money100->mulByRatio('0.9')->getAmount() === '90.0000', '比率乘法：100×0.9=90.0000（折扣=支付比例）');
check($money100->mulByRatio('0.14084507')->getAmount() === '14.0845', '比率乘法含舍入：100×0.14084507=14.0845');
check($money100->add(Money::of('0.00005', 'CNY'))->getAmount() === '100.0001', '加法进位：100+0.00005=100.0001');
try {
    $money100->add(Money::of('1', 'USD'));
    throw new RuntimeException('[FAIL] 跨币种相加应被拒绝');
} catch (InvalidArgumentException $exception) {
    check(true, '跨币种相加被拒绝');
}
check(Money::normalizeQuantity('1.5') === '1.5000' && Money::normalizeQuantity('2') === '2.0000', '数量规范化');
try {
    Money::normalizeQuantity('0');
    throw new RuntimeException('[FAIL] 零数量应被拒绝');
} catch (InvalidArgumentException $exception) {
    check(true, '零/负数量被拒绝');
}
$mutated = $money100->mulByRatio('0.5');
check($money100->getAmount() === '100.0000' && $mutated->getAmount() === '50.0000', '值对象不可变（乘法返回新实例）');

// ---------------------------------------------------------------------
// 2. Q-001~Q-004：三层控制价分级与边界（华北：无规则无费用）
// ---------------------------------------------------------------------
echo "\n== 2. Q-001~Q-004 三层控制价分级 ==\n";
$service = new PricingService();
$baseInput = ['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerStdId, 'date' => '2026-09-01'];
expectPricingError(function () use ($service, $models, $customerStdId) {
    $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerStdId, 'date' => '2026-02-30']);
}, PricingException::INVALID_INPUT, '不存在的自然日被拒绝');

$result = $service->calculate($baseInput);
check($result['lines'][0]['amounts']['goods'] === '120000.0000', 'Q-001 基础价 120000 命中价格表条目');
check($result['lines'][0]['classification'] === 'normal', 'Q-001 报价等于指导价 → normal（可批准）');
check($result['approval_level'] === 'none' && $result['submittable'] === true, 'Q-001 审批等级 none，可提交');
check($result['lines'][0]['amounts']['tax'] === '15600.0000', 'Q-001 价外税：120000×13%=15600');
check($result['lines'][0]['amounts']['total'] === '135600.0000', 'Q-001 含税总额 135600');

$result = $service->calculate($baseInput + ['manual_discount' => '0.9', 'discount_reason' => '战略让利']);
check($result['lines'][0]['amounts']['goods_discounted'] === '108000.0000', 'Q-002 九折后 108000');
check($result['lines'][0]['classification'] === 'line_approval', 'Q-002 低于指导价但不低于产线控制价（==108000 边界）→ line_approval');
check($result['approval_level'] === 'line', 'Q-002 整单进入产线审批');

$result = $service->calculate($baseInput + ['manual_discount' => '0.85', 'discount_reason' => '年度框架让利']);
check($result['lines'][0]['amounts']['goods_discounted'] === '102000.0000', 'Q-003 八五折后 102000');
check($result['lines'][0]['classification'] === 'company_approval', 'Q-003 低于产线控制价但不低于公司控制价 → company_approval');
check($result['approval_level'] === 'company', 'Q-003 整单进入公司审批（须填写理由）');
check(stepOf($result['lines'][0], 'manual_discount')['discount_reason'] === '年度框架让利', 'Q-003 折扣理由写入轨迹');

$result = $service->calculate($baseInput + ['manual_discount' => '0.75', 'discount_reason' => '超低价测试']);
check($result['lines'][0]['amounts']['goods_discounted'] === '90000.0000', 'Q-004 七五折后 90000');
check($result['lines'][0]['classification'] === 'forbidden', 'Q-004 低于公司控制价（96000）→ forbidden');
check($result['submittable'] === false, 'Q-004 整单不可提交');
check($result['block_reasons'][0]['business_code'] === PricingException::BELOW_COMPANY_FLOOR, 'Q-004 阻断原因业务码 CPQ_PRICE_BELOW_COMPANY_FLOOR');
expectPricingError(function () use ($service, $result) {
    $service->assertSubmittable($result);
}, PricingException::BELOW_COMPANY_FLOOR, 'Q-004 assertSubmittable 服务端硬阻断');
expectPricingError(function () use ($service, $baseInput) {
    $service->calculate($baseInput + ['manual_discount' => '0.9']);
}, PricingException::INVALID_INPUT, '手工折扣缺少理由被拒绝');
expectPricingError(function () use ($service, $baseInput) {
    $service->calculate($baseInput + ['manual_discount' => '1.5', 'discount_reason' => 'x']);
}, PricingException::INVALID_INPUT, '手工折扣超过 1 被拒绝');

// ---------------------------------------------------------------------
// 3. 固定流水线与 price_trace / 配置 / 配件 / 数量分段
// ---------------------------------------------------------------------
echo "\n== 3. 固定流水线与 price_trace ==\n";
$result = $service->calculate($baseInput);
$trace = $result['lines'][0]['price_trace'];
$stepNames = array_map(function ($step) {
    return $step['step'];
}, $trace['steps']);
check($stepNames === [
    'base_price', 'option_prices', 'accessory_prices', 'price_rules', 'quantity',
    'channel_adjustments', 'manual_discount', 'fees', 'untaxed_amount', 'tax',
    'total_with_tax', 'currency_conversion', 'floor_comparison', 'approval_level',
], '14 步固定流水线顺序一致');
check($trace['snapshots']['price_book']['code'] === 'CPQ-TEST-BOOK-CNY', '价格表快照');
check($trace['snapshots']['price_policy']['guide_price'] === '120000.0000', '三层策略快照');
check($trace['snapshots']['exchange_rate']['direction'] === 'identity', '同币种恒等汇率快照');
check($trace['snapshots']['tax_rule']['code'] === 'CPQ-TEST-TAX-CN', '税规则快照记录命中规则');
check(isset($trace['snapshots']['price_policy']['dimensions'])
    && array_key_exists('effective_date', $trace['snapshots']['price_policy']), '价格策略快照包含匹配维度与生效区间');
check($result['price_hash'] === hash('sha256', json_encode(ksortRecursiveCopy($result['price_trace']), JSON_UNESCAPED_UNICODE)), '整单 price_hash 可由规范化轨迹复算');

$result = $service->calculate($baseInput + ['configuration' => ['CPQ-TEST-G-POWER' => 'high']]);
check($result['lines'][0]['unit_amounts']['options'] === '8000.0000', '配置选中高功率选项 → 选项价 8000');
check($result['lines'][0]['unit_amounts']['subtotal'] === '128000.0000', '单价小计 128000');
check($result['lines'][0]['configuration_hash'] !== null, '配置哈希写入行结果');
check($result['lines'][0]['price_trace']['input']['configuration']['CPQ-TEST-G-POWER'] === 'high', '规范化配置写入价格轨迹');
expectPricingError(function () use ($service, $models, $customerStdId) {
    $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerStdId, 'date' => '2026-09-01',
        'configuration' => ['CPQ-TEST-G-POWER' => 'nonexistent']]);
}, PricingException::CONFIG_INVALID, '非法配置（未知选项）不能计价');

$result = $service->calculate($baseInput + ['accessories' => [['id' => $accessoryInstallId, 'quantity' => 2]]]);
check($result['lines'][0]['unit_amounts']['services'] === '10000.0000', '安装服务 5000×2=10000');
expectPricingError(function () use ($service, $baseInput, $accessoryNoPriceId) {
    $service->calculate($baseInput + ['accessories' => [['id' => $accessoryNoPriceId, 'quantity' => 1]]]);
}, PricingException::ENTRY_MISSING, '配件缺少价格条目被阻断');
expectPricingError(function () use ($service, $models, $customerStdId) {
    $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-B'], 'customer_id' => $customerStdId, 'date' => '2026-09-01']);
}, PricingException::ENTRY_MISSING, '型号缺少价格条目被阻断（型号B）');
expectPricingError(function () use ($service, $baseInput, $customerStdId) {
    $service->calculate(['model_id' => $baseInput['model_id'], 'customer_id' => $customerStdId, 'date' => '2025-06-01']);
}, PricingException::BOOK_MISSING, '试算日期无生效价格表被阻断');

// 数量分段 + 数量阶梯规则（>=10 九折）
$result = $service->calculate($baseInput + ['quantity' => 10]);
$baseStep = stepOf($result['lines'][0], 'base_price');
check($baseStep['price_entry']['min_qty'] === '10.0000', '数量 10 命中 min_qty=10 分段');
check($result['lines'][0]['unit_amounts']['base'] === '110000.0000', '分段价 110000 生效');
check($result['lines'][0]['amounts']['goods'] === '990000.0000', '110000×0.9(数量规则)×10=990000');
check($result['lines'][0]['classification'] === 'company_approval', '单价 99000 介于公司控制价与产线控制价之间 → company_approval（仍可提交）');
check($result['submittable'] === true, '数量阶梯折扣后整单可提交');

// ---------------------------------------------------------------------
// 4. 价格规则（华东：规则链齐全）
// ---------------------------------------------------------------------
echo "\n== 4. 价格规则链 ==\n";
$eastInput = ['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerVipId, 'date' => '2026-09-01',
    'configuration' => ['CPQ-TEST-G-POWER' => 'high'], 'accessories' => [['id' => $accessoryInstallId, 'quantity' => 1]]];
$result = $service->calculate($eastInput);
$line = $result['lines'][0];
$rulesStep = stepOf($line, 'price_rules');
$appliedCodes = array_map(function ($item) {
    return $item['code'];
}, $rulesStep['matched']);
$suppressedCodes = array_map(function ($item) {
    return $item['code'];
}, $rulesStep['suppressed']);

// 基础 120000×0.95=114000；选项 8000+500=8500；服务 5000×1.1=5500
$baseRule = null;
$optionRule = null;
foreach ($rulesStep['matched'] as $item) {
    if ($item['code'] === 'CPQ-TEST-RULE-BASE-DISC') {
        $baseRule = $item;
    }
    if ($item['code'] === 'CPQ-TEST-RULE-OPT-ADD') {
        $optionRule = $item;
    }
}
check($baseRule['before'] === '120000.0000' && $baseRule['after'] === '114000.0000', '基础价折扣规则（九五折）：120000→114000');
check($optionRule['before'] === '8000.0000' && $optionRule['after'] === '8500.0000', '选项加价规则：8000→8500');
check(stepOf($line, 'option_prices')['items'][0]['unit_price'] === '8000.0000', '选项原始价 8000 来自价格条目');
check(stepOf($line, 'option_prices')['items'][0]['group_name'] === '功率等级'
    && stepOf($line, 'option_prices')['items'][0]['option_name'] === '高功率', '选项加价轨迹携带选项组/选项名称（GYTAI-85）');
// 小计链：128000 → 互斥组高优先级 -1000=127000 → 一口价 100000 → 叠加 -100/-200=99700 → 五折 49850 → 保底 60000
check(in_array('CPQ-TEST-RULE-EXCL-HI', $appliedCodes, true), '互斥组高优先级规则生效');
check(in_array('CPQ-TEST-RULE-EXCL-LO', $suppressedCodes, true) && !in_array('CPQ-TEST-RULE-EXCL-LO', $appliedCodes, true), '互斥组低优先级规则被抑制并记录');
check(in_array('CPQ-TEST-RULE-FIXED', $appliedCodes, true), '一口价规则生效（can_stack=1）');
check(in_array('CPQ-TEST-RULE-STACK-A', $appliedCodes, true) && in_array('CPQ-TEST-RULE-STACK-B', $appliedCodes, true), '可叠加规则全部生效');
$minmax = null;
$fixed = null;
foreach ($rulesStep['matched'] as $item) {
    if ($item['code'] === 'CPQ-TEST-RULE-MINMAX') {
        $minmax = $item;
    }
    if ($item['code'] === 'CPQ-TEST-RULE-FIXED') {
        $fixed = $item;
    }
}
check($fixed['after'] === '100000.0000', '一口价规则：小计 127000→100000');
check($minmax['before'] === '99700.0000' && $minmax['after'] === '60000.0000', '保底规则：×0.5=49850 → 保底 60000');
check(in_array('CPQ-TEST-RULE-NONSTACK', $suppressedCodes, true), 'can_stack=0 规则在对象已被调整后抑制');
check($line['unit_amounts']['subtotal'] === '60000.0000', '单价小计经规则链后 60000');
check(isset($line['price_trace']['snapshots']['price_rules'][0]['condition'])
    && array_key_exists('effective_date', $line['price_trace']['snapshots']['price_rules'][0]), '价格规则快照包含条件与生效区间');

$runtimeConflictRuleId = $priceRule('CPQ-TEST-RULE-EXCL-TIE', $eastModelA, 'amount', 'subtotal', -50, [
    'exclusive_group' => 'CPQ-TEST-GRP', 'priority' => 20,
]);
expectPricingError(function () use ($service, $eastInput) {
    $service->calculate($eastInput);
}, PricingException::RULE_CONFLICT, '运行期互斥组同优先级命中冲突被阻断');
Db::name('cpq_price_rule')->where('id', $runtimeConflictRuleId)->delete();

// ---------------------------------------------------------------------
// 5. 渠道折扣 / 费用 / 运费规则调整 / 区域税率 / 毛利
// ---------------------------------------------------------------------
echo "\n== 5. 渠道折扣与费用 ==\n";
check($line['amounts']['goods'] === '60000.0000', '数量 1：单价小计 60000');
$channelStep = stepOf($line, 'channel_adjustments');
check($channelStep['applied'][0]['source'] === 'customer_level' && $channelStep['applied'][0]['discount'] === '0.950000', 'VIP 客户等级九五折生效');
check($line['amounts']['goods_discounted'] === '57000.0000', '渠道折扣后 57000');
$feesStep = stepOf($line, 'fees');
check($feesStep['freight_adjustments'][0]['code'] === 'CPQ-TEST-RULE-FREIGHT' && $feesStep['freight_adjustments'][0]['after'] === '400.0000', '运费 500 经价格规则 -100 后 400');
check($line['fees_by_type']['freight'] === '400.0000', '运费小计 400');
check($line['fees_by_type']['insurance'] === '570.0000', '保险费 57000×1%=570');
check($line['fees_by_type']['installation'] === '300.0000', '安装费 300×1=300');
check($line['amounts']['fees'] === '1270.0000', '费用合计 1270');
check($line['price_trace']['snapshots']['fee_rules'][0]['exchange_rate']['direction'] === 'identity', '费用规则快照包含计价币种换算依据');
check($line['amounts']['untaxed'] === '58270.0000', '未税金额 57000+1270=58270');
check(stepOf($line, 'tax')['matched']['code'] === 'CPQ-TEST-TAX-CN-EAST', '区域税率维度更具体优先命中（9%）');
check($line['amounts']['tax'] === '5244.3000', '税额 58270×9%=5244.30');
check($line['amounts']['total'] === '63514.3000', '含税总额 63514.30');
check($line['amounts']['control_unit_price'] === '57700.0000', '控制价口径 57000+400+300=57700（保险不计入控制价）');
check($line['margin']['revenue'] === '58270.0000' && $line['margin']['margin_amount'] === '-1730.0000', '毛利额 = 收入 58270 - 成本 60000 = -1730');

// 代理终端客户：代理等级八五折优先于客户等级
$result = $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $agentCustomerId, 'date' => '2026-09-01']);
$channelStep = stepOf($result['lines'][0], 'channel_adjustments');
check($channelStep['applied'][0]['source'] === 'agent_level' && $channelStep['applied'][0]['discount'] === '0.850000', '代理终端客户按代理等级八五折（优先于客户等级）');
check($result['lines'][0]['amounts']['goods_discounted'] === '102000.0000', '120000×0.85=102000');

// 价内税（型号G 仅含税价目有条目；华东费用照常计入）
$result = $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-G'], 'customer_id' => $customerVipId, 'date' => '2026-09-01']);
$line = $result['lines'][0];
check(stepOf($line, 'tax')['tax_mode'] === 'tax_inclusive', '含税价目被选中（型号G 唯一条目来源）');
// 100000×0.95=95000；费用 500+950+300=1750；未税口径 96750；价内税 = 96750 - 96750/1.09
check($line['amounts']['untaxed'] === '96750.0000', '价内模式未税口径=货款+费用 96750');
check($line['amounts']['tax'] === '7988.5321', '价内税额 96750-96750/1.09=7988.5321');
check($line['amounts']['total'] === '96750.0000', '价内含税总额不变');

// ---------------------------------------------------------------------
// 6. 八级策略匹配优先级（型号C）
// ---------------------------------------------------------------------
echo "\n== 6. 八级策略匹配优先级 ==\n";
$modelCInput = ['model_id' => $models['CPQ-TEST-MODEL-C'], 'date' => '2026-09-01'];

$policy1 = matchedPolicyOf($service->calculate($modelCInput + ['customer_id' => $customerVipId]));
check($policy1['guide_price'] === '100000.0000', 'L1 指定客户优先于等级+区域等全部低级（guide=100000）');
$policy2 = matchedPolicyOf($service->calculate($modelCInput + ['agent_id' => $agentId]));
check($policy2['guide_price'] === '100500.0000', 'L2 指定代理商优先于代理等级/区域等低级（guide=100500）');
$policy2FromCustomer = matchedPolicyOf($service->calculate($modelCInput + ['customer_id' => $agentCustomerId]));
check($policy2FromCustomer['guide_price'] === '100500.0000', '终端客户自动带出所属代理商并命中 L2 策略');
$policy3 = matchedPolicyOf($service->calculate($modelCInput + ['customer_level' => 'CPQ-TEST-LV-VIP', 'region_code' => 'CPQ-TEST-REGION-EAST']));
check($policy3['guide_price'] === '101000.0000', 'L3 等级+区域优先于等级/区域/市场/产品线/公司默认（guide=101000）');
$policy4 = matchedPolicyOf($service->calculate($modelCInput + ['customer_level' => 'CPQ-TEST-LV-VIP']));
check($policy4['guide_price'] === '102000.0000', 'L4 等级优先于区域/市场/产品线（guide=102000）');
$policy5 = matchedPolicyOf($service->calculate($modelCInput + ['customer_level' => 'CPQ-TEST-LV-STD', 'region_code' => 'CPQ-TEST-REGION-EAST']));
check($policy5['guide_price'] === '103000.0000', 'L5 区域优先于市场/产品线（guide=103000）');
$policy6 = matchedPolicyOf($service->calculate($modelCInput + ['customer_level' => 'CPQ-TEST-LV-STD']));
check($policy6['guide_price'] === '104000.0000', 'L6 国内/国际优先于产品线/公司默认（guide=104000）');
$policy7 = matchedPolicyOf($service->calculate($modelCInput + ['customer_level' => 'CPQ-TEST-LV-STD', 'market_scope' => 'international']));
check($policy7['guide_price'] === '105000.0000', 'L7 产品线默认优先于公司默认（guide=105000，国际场景）');
$policy8 = matchedPolicyOf($service->calculate(['model_id' => $models['CPQ-TEST-MODEL-D'], 'date' => '2026-09-01']));
check($policy8['guide_price'] === '90000.0000', 'L8 公司默认兜底命中（型号D 其他产品线）');

// 同级不同优先级：高者优先
$highPriorityId = $policy(['customer_level' => 'CPQ-TEST-LV-VIP', 'target_id' => $models['CPQ-TEST-MODEL-C']], 102500, 92250, 82000, 50000, 30);
$policyAfter = matchedPolicyOf($service->calculate($modelCInput + ['customer_level' => 'CPQ-TEST-LV-VIP']));
check($policyAfter['guide_price'] === '102500.0000', '同级具体度取高优先级（priority=30）');
Db::name('cpq_price_policy')->where('id', $highPriorityId)->delete();

// 同级同优先级：规则冲突，阻止提交
$conflictId = $policy(['customer_level' => 'CPQ-TEST-LV-VIP', 'target_id' => $models['CPQ-TEST-MODEL-C']], 102900, 92610, 82320, 50000, 10, '2026-06-01');
$conflict = expectPricingError(function () use ($service, $modelCInput) {
    $service->calculate($modelCInput + ['customer_level' => 'CPQ-TEST-LV-VIP']);
}, PricingException::POLICY_CONFLICT, '同级同优先级命中多条策略 → 规则冲突阻止报价');
check(count($conflict->getDetails()['policies']) === 2, '冲突明细包含两条策略编码');
Db::name('cpq_price_policy')->where('id', $conflictId)->delete();

// ---------------------------------------------------------------------
// 7. Q-006：缺少区域价格策略 → 阻止并指出缺失维度
// ---------------------------------------------------------------------
echo "\n== 7. Q-006 缺失维度 ==\n";
$missing = expectPricingError(function () use ($service, $models, $customerStdId) {
    $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-F'], 'customer_id' => $customerStdId, 'date' => '2026-09-01']);
}, PricingException::POLICY_MISSING, 'Q-006 华北区域无型号F策略 → 阻止并指出缺失维度');
$details = $missing->getDetails();
check($details['model_code'] === 'CPQ-TEST-MODEL-F' && $details['region_code'] === 'CPQ-TEST-REGION-NORTH'
    && $details['market_scope'] === 'domestic' && $details['currency'] === 'CNY', 'Q-006 缺失维度明细包含型号/区域/市场/币种');

// ---------------------------------------------------------------------
// 8. Q-005：国际客户 USD 价目 + 汇率快照
// ---------------------------------------------------------------------
echo "\n== 8. Q-005 汇率快照 ==\n";
$result = $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerIntlId, 'date' => '2026-09-01', 'currency' => 'CNY']);
$line = $result['lines'][0];
check($line['pricing_currency'] === 'USD' && $line['currency'] === 'CNY', 'Q-005 定价币种 USD、输出币种 CNY');
check($line['price_trace']['snapshots']['exchange_rate']['direction'] === 'direct', 'Q-005 使用直接汇率快照');
check($line['price_trace']['snapshots']['exchange_rate']['rate'] === '7.20000000', 'Q-005 命中 2026-06-01 生效的最新汇率 7.2');
check($line['price_trace']['snapshots']['exchange_rate']['stored_rate'] === '7.20000000'
    && $line['price_trace']['snapshots']['exchange_rate']['id'] > 0, 'Q-005 汇率快照包含来源记录与原始汇率');
check($line['amounts']['total'] === '20000.0000' && $line['converted']['total'] === '144000.0000', 'Q-005 USD 20000 → CNY 144000（×7.2）');
check($line['classification'] === 'normal' && $result['approval_level'] === 'none', 'Q-005 20000 >= 指导价 20000 → 正常可批准');
check($result['totals']['total'] === '144000.0000', 'Q-005 整单合计按输出币种汇总');

$result = $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerIntlId, 'date' => '2026-05-31', 'currency' => 'CNY']);
check($result['lines'][0]['price_trace']['snapshots']['exchange_rate']['rate'] === '7.10000000', '试算日期 2026-05-31 命中 7.1 汇率');

$result = $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerStdId, 'date' => '2026-09-01', 'currency' => 'USD']);
$rateSnapshot = $result['lines'][0]['price_trace']['snapshots']['exchange_rate'];
check($rateSnapshot['direction'] === 'inverse' && $rateSnapshot['rate'] === '0.13888889', '倒数汇率 1/7.2=0.13888889（scale 8 四舍五入）');
check($result['lines'][0]['converted']['goods_discounted'] === '16666.6668', '120000×0.13888889=16666.6668（HALF_UP）');

expectPricingError(function () use ($service, $models, $customerStdId) {
    $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerStdId, 'date' => '2026-09-01', 'currency' => 'EUR']);
}, PricingException::RATE_MISSING, '缺少 CNY→EUR 汇率被阻断');

// ---------------------------------------------------------------------
// 9. Q-007：多行最严格审批等级
// ---------------------------------------------------------------------
echo "\n== 9. Q-007 多行最严格审批 ==\n";
$result = $service->calculate([
    'customer_id' => $customerStdId, 'date' => '2026-09-01',
    'lines' => [
        ['model_id' => $models['CPQ-TEST-MODEL-A']],
        ['model_id' => $models['CPQ-TEST-MODEL-A'], 'manual_discount' => '0.9', 'discount_reason' => '产线让利'],
        ['model_id' => $models['CPQ-TEST-MODEL-A'], 'manual_discount' => '0.85', 'discount_reason' => '公司让利'],
    ],
]);
check(count($result['lines']) === 3, 'Q-007 三行报价');
check($result['approval_level'] === 'company', 'Q-007 整单取最严格审批等级（company）');
check($result['submittable'] === true, 'Q-007 无 forbidden 行可提交');
check($result['totals']['total'] === '372900.0000', '整单合计 135600+122040+115260=372900');
check($result['totals']['goods_discounted'] === '330000.0000', '整单货款合计 330000');

$result = $service->calculate([
    'customer_id' => $customerStdId, 'date' => '2026-09-01',
    'lines' => [
        ['model_id' => $models['CPQ-TEST-MODEL-A']],
        ['model_id' => $models['CPQ-TEST-MODEL-A'], 'manual_discount' => '0.75', 'discount_reason' => '超低价'],
    ],
]);
check($result['submittable'] === false && count($result['block_reasons']) === 1, 'Q-007+Q-004 任一行低于公司控制价 → 整单阻断');
check($result['block_reasons'][0]['line'] === 2, '阻断原因定位到第 2 行');
check($result['approval_level'] === 'forbidden', '整单审批等级提升为最严格 forbidden');

// ---------------------------------------------------------------------
// 10. Q-008：发布后按日期重算不变 + 确定性
// ---------------------------------------------------------------------
echo "\n== 10. Q-008 确定性与版本 ==\n";
$inputA = ['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerStdId, 'date' => '2026-09-01'];
$first = $service->calculate($inputA);
$second = $service->calculate($inputA);
check(json_encode($first, JSON_UNESCAPED_UNICODE) === json_encode($second, JSON_UNESCAPED_UNICODE), '相同输入输出逐字节一致');
check($first['price_hash'] === $second['price_hash'], 'price_hash 稳定');
check(matchedPolicyOf($first)['guide_price'] === '120000.0000', '2026-09 命中旧版策略 guide=120000');

$november = $service->calculate(['model_id' => $models['CPQ-TEST-MODEL-A'], 'customer_id' => $customerStdId, 'date' => '2026-11-15']);
check(matchedPolicyOf($november)['guide_price'] === '130000.0000', '2026-11 起命中新版策略 guide=130000');
check($first['price_hash'] !== $november['price_hash'], '不同日期/版本结果哈希不同');

$again = $service->calculate($inputA);
check($again['price_hash'] === $first['price_hash'], 'Q-008 价格发布后按原日期重算历史结果不变');

// ---------------------------------------------------------------------
// 11. 越权解释：角色脱敏与审计
// ---------------------------------------------------------------------
echo "\n== 11. 越权解释脱敏 ==\n";
$fullResult = $service->calculate($inputA);
check(isset($fullResult['lines'][0]['margin']['cost']), '完整结果包含成本（供角色校验对照）');

$salesView = $service->maskForRoles($fullResult, ['sales']);
assertNoKeysAnywhere($salesView, ['cost', 'cost_total', 'company_floor', 'margin_amount', 'margin_rate']);
check(true, '销售角色解释：成本/公司控制价/毛利字段全部脱敏');
check(isset($salesView['lines'][0]['price_trace']['snapshots']['price_policy']['guide_price'])
    && isset($salesView['lines'][0]['price_trace']['snapshots']['price_policy']['line_floor']), '销售角色仍可见指导价与产线控制价');
check($salesView['lines'][0]['classification'] === 'normal', '销售角色仍可见分级结果');

$linePricerView = $service->maskForRoles($fullResult, ['line_pricer']);
assertNoKeysAnywhere($linePricerView, ['company_floor']);
check(isset($linePricerView['lines'][0]['margin']['cost']), '产线价格管理员可见成本');
check(true, '产线价格管理员不可见公司控制价');

$companyPricerView = $service->maskForRoles($fullResult, ['company_pricer']);
check(isset($companyPricerView['lines'][0]['margin']['cost'])
    && isset($companyPricerView['lines'][0]['price_trace']['snapshots']['price_policy']['company_floor']), '公司价格管理员全量可见');

$apiView = $service->maskForRoles($fullResult, []);
assertNoKeysAnywhere($apiView, ['cost', 'cost_total', 'company_floor', 'margin_amount', 'margin_rate']);
check(true, 'API 侧按最低权限脱敏（成本/公司控制价/毛利不可见）');

$auditBefore = (int)Db::name('cpq_audit_log')->where('action', 'view_sensitive')->where('object_type', 'cpq_price_trace')->count();
$maskedExplain = $service->explain($inputA, ['company_pricer']);
$auditAfter = (int)Db::name('cpq_audit_log')->where('action', 'view_sensitive')->where('object_type', 'cpq_price_trace')->count();
check($auditAfter === $auditBefore + 1, '敏感角色查看价格轨迹写入审计');
check(isset($maskedExplain['lines'][0]['margin']['cost']), '公司价格管理员 explain 保留成本');

$salesExplain = $service->explain($inputA, ['sales']);
$auditSales = (int)Db::name('cpq_audit_log')->where('action', 'view_sensitive')->where('object_type', 'cpq_price_trace')->count();
check($auditSales === $auditAfter, '非敏感角色 explain 不写审计');
assertNoKeysAnywhere($salesExplain, ['cost', 'company_floor', 'margin_amount', 'margin_rate']);
check(true, '销售 explain 结果脱敏一致');

$routes = include dirname(__DIR__, 2) . '/application/route.php';
check(isset($routes['api/cpq/v1/prices/calculate'], $routes['api/cpq/v1/prices/explain']), 'calculate/explain API 显式路由已注册');
check(method_exists(app\api\controller\cpq\Price::class, 'calculate')
    && method_exists(app\api\controller\cpq\Price::class, 'explain'), '价格 API 控制器入口存在');

// ---------------------------------------------------------------------
// 12. 性能：100 行报价 < 2 秒
// ---------------------------------------------------------------------
echo "\n== 12. 100 行性能 ==\n";
$lines = [];
for ($i = 0; $i < 100; $i++) {
    $lines[] = ['model_id' => $models['CPQ-TEST-MODEL-A'], 'quantity' => 2,
        'configuration' => ['CPQ-TEST-G-POWER' => 'high'],
        'accessories' => [['id' => $accessoryInstallId, 'quantity' => 1]]];
}
$start = microtime(true);
$perfResult = (new PricingService())->calculate(['customer_id' => $customerVipId, 'date' => '2026-09-01', 'lines' => $lines]);
$elapsed = microtime(true) - $start;
check(count($perfResult['lines']) === 100, '100 行报价计算完成');
$assertCount++;
printf("[ok] 100 行报价试算耗时 %.3f 秒（目标 < 2 秒）\n", $elapsed);
check($elapsed < 2.0, '100 行报价试算 < 2 秒');

echo "\nM2 pricing engine tests: PASS ({$assertCount} assertions)\n";
