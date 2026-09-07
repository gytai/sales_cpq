<?php
/**
 * M2 客户渠道与价格主数据测试（GYTAI-69）。
 *
 * 覆盖：编码唯一性、区域/组织树（路径维护/移动/判环/删除保护）、
 * 三层价格关系（指导价 >= 产线控制价 >= 公司控制价 >= 0，bccomp 字符串比较
 * 禁止 float）、价格表同范围同优先级重叠发布拦截、价格策略维度重叠拦截、
 * 价格规则互斥组冲突发布拦截、发布版本不可变（生效不可改/未生效可撤回/
 * 回滚=重发旧内容）、成本与控制价角色脱敏 + 敏感查看审计、产品线数据范围
 * 越权、Excel 导入预览错误行契约（不写库）。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/price_channel.php
 *
 * 使用独立临时库 cpq_m2_price_test（执行 install.sql 建最终结构），
 * 结束时自动 DROP；所有种子数据编码带 CPQ-TEST- 前缀。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\admin\model\cpq\PriceBook;
use app\admin\model\cpq\PricePolicy;
use app\admin\model\cpq\PriceRule;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\ChannelTreeService;
use app\common\service\cpq\ImportPreviewService;
use app\common\service\cpq\MasterDataLifecycleService;
use app\common\service\cpq\PricePolicyService;
use app\common\service\cpq\PriceReleaseService;
use app\common\service\cpq\PriceRuleService;
use app\common\service\cpq\ProductLineScopeService;
use app\common\service\cpq\SensitiveFieldService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m2_price_test';
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

function expectThrow(callable $fn, $message)
{
    global $assertCount;
    $assertCount++;
    try {
        $fn();
    } catch (\Throwable $exception) {
        echo "[ok] {$message}（异常：{$exception->getMessage()}）\n";
        return $exception->getMessage();
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出异常但未抛出');
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
check(Db::name('cpq_customer')->count() === 0, '临时库已就位（fa_cpq_customer 为空）');

$now = time();
$lifecycle = new MasterDataLifecycleService();
$tree = new ChannelTreeService();
$policyService = new PricePolicyService();
$ruleService = new PriceRuleService();
$releaseService = new PriceReleaseService();
$maskService = new SensitiveFieldService();
$importService = new ImportPreviewService();

// ---------------------------------------------------------------------
// 种子：产品线/型号（覆盖率用）
// ---------------------------------------------------------------------
$lineA = 'CPQ-TEST-LINE-A';
$lineB = 'CPQ-TEST-LINE-B';
$seriesA = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-TEST-SERIES-A', 'name' => '测试系列A', 'product_line' => $lineA,
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$modelA = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesA, 'code' => 'CPQ-TEST-MODEL-A', 'name' => '测试型号A',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$modelB = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesA, 'code' => 'CPQ-TEST-MODEL-B', 'name' => '测试型号B（无价格条目）',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);

// ---------------------------------------------------------------------
// 1. 区域/组织树（ChannelTreeService）
// ---------------------------------------------------------------------
echo "\n== 区域/组织树 ==\n";
$regionCn = $tree->saveNode('cpq_region', ['code' => 'CPQ-TEST-REGION-CN', 'name' => '中国', 'parent_id' => 0]);
$rootRow = Db::name('cpq_region')->where('id', $regionCn)->find();
check($rootRow['path'] === '/' . $regionCn . '/' && (int)$rootRow['level'] === 1, '根区域路径 /{id}/ 且层级为 1');

$regionEast = $tree->saveNode('cpq_region', ['code' => 'CPQ-TEST-REGION-EAST', 'name' => '华东', 'parent_id' => $regionCn]);
$eastRow = Db::name('cpq_region')->where('id', $regionEast)->find();
check($eastRow['path'] === '/' . $regionCn . '/' . $regionEast . '/' && (int)$eastRow['level'] === 2, '子区域路径继承父节点且层级为 2');

$regionHz = $tree->saveNode('cpq_region', ['code' => 'CPQ-TEST-REGION-HZ', 'name' => '杭州', 'parent_id' => $regionEast]);
check(Db::name('cpq_region')->where('id', $regionHz)->value('path') === '/' . $regionCn . '/' . $regionEast . '/' . $regionHz . '/', '三级区域路径正确');

// 移动：华东 移到根下另一分支前，先建第二个根
$regionNorth = $tree->saveNode('cpq_region', ['code' => 'CPQ-TEST-REGION-NORTH', 'name' => '华北', 'parent_id' => $regionCn]);
$tree->moveNode('cpq_region', $regionEast, $regionNorth);
check(Db::name('cpq_region')->where('id', $regionEast)->value('path') === '/' . $regionCn . '/' . $regionNorth . '/' . $regionEast . '/', '移动后父路径更新');
check(Db::name('cpq_region')->where('id', $regionHz)->value('path') === '/' . $regionCn . '/' . $regionNorth . '/' . $regionEast . '/' . $regionHz . '/', '移动后子树路径级联重建');

// 判环：中国 不能移动到 杭州 之下
expectThrow(function () use ($tree, $regionCn, $regionHz) {
    $tree->moveNode('cpq_region', $regionCn, $regionHz);
}, '父节点设为自身后代被判环拒绝');

// 删除保护：有子节点的区域不可删
expectThrow(function () use ($tree, $regionEast) {
    $tree->assertNodeDeletable('cpq_region', [$regionEast]);
}, '存在子节点的区域不能删除');

// 组织树
$orgHq = $tree->saveNode('cpq_sales_org', ['code' => 'CPQ-TEST-ORG-HQ', 'name' => '销售总部', 'parent_id' => 0]);
$orgEast = $tree->saveNode('cpq_sales_org', ['code' => 'CPQ-TEST-ORG-EAST', 'name' => '华东销售部', 'parent_id' => $orgHq]);
check(Db::name('cpq_sales_org')->where('id', $orgEast)->value('path') === '/' . $orgHq . '/' . $orgEast . '/', '销售组织树路径维护正确');

// ---------------------------------------------------------------------
// 2. 客户/渠道主数据 + 引用保护 + 唯一性
// ---------------------------------------------------------------------
echo "\n== 客户/渠道主数据 ==\n";
$levelStandard = (int)Db::name('cpq_customer_level')->insertGetId([
    'code' => 'CPQ-TEST-LV-STANDARD', 'name' => '标准客户', 'sort' => 20,
    'createtime' => $now, 'updatetime' => $now,
]);
$levelVip = (int)Db::name('cpq_customer_level')->insertGetId([
    'code' => 'CPQ-TEST-LV-VIP', 'name' => '大客户', 'sort' => 10, 'default_discount' => '0.900000',
    'createtime' => $now, 'updatetime' => $now,
]);
$agentLevel = (int)Db::name('cpq_agent_level')->insertGetId([
    'code' => 'CPQ-TEST-ALV-CORE', 'name' => '核心代理', 'createtime' => $now, 'updatetime' => $now,
]);
$customerA = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-TEST-CUSTOMER-A', 'name' => '测试客户A', 'type' => 'direct',
    'region_id' => $regionHz, 'customer_level_id' => $levelStandard, 'sales_org_id' => $orgEast,
    'createtime' => $now, 'updatetime' => $now,
]);
$agentA = (int)Db::name('cpq_agent')->insertGetId([
    'code' => 'CPQ-TEST-AGENT-A', 'customer_id' => $customerA, 'agent_level_id' => $agentLevel,
    'authorized_regions' => '[' . $regionHz . ']', 'authorized_lines' => '["' . $lineA . '"]',
    'credit_limit' => '1000000.0000', 'createtime' => $now, 'updatetime' => $now,
]);

expectThrow(function () use ($now) {
    Db::name('cpq_customer')->insert([
        'code' => 'CPQ-TEST-CUSTOMER-A', 'name' => '重复编码客户', 'createtime' => $now, 'updatetime' => $now,
    ]);
}, '客户编码重复被唯一键拒绝');

expectThrow(function () use ($now, $levelStandard) {
    Db::name('cpq_customer')->insert([
        'code' => 'CPQ-TEST-CUSTOMER-BAD-LEVEL', 'name' => '等级外键', 'customer_level_id' => 999999,
        'createtime' => $now, 'updatetime' => $now,
    ]);
}, '客户引用不存在的客户等级被外键拒绝');

// 引用保护
expectThrow(function () use ($tree, $regionHz) {
    // 杭州区域被客户引用
    $tree->assertNodeDeletable('cpq_region', [$regionHz]);
}, '被客户引用的区域节点不能删除');
expectThrow(function () use ($lifecycle, $levelStandard) {
    $lifecycle->assertDeletable('cpq_customer_level', [['id' => $levelStandard, 'code' => 'CPQ-TEST-LV-STANDARD']]);
}, '被客户引用的客户等级不能删除');
expectThrow(function () use ($lifecycle, $customerA) {
    $lifecycle->assertDeletable('cpq_customer', [['id' => $customerA, 'code' => 'CPQ-TEST-CUSTOMER-A']]);
}, '被代理商引用的客户不能删除');
$lifecycle->assertDeletable('cpq_customer_level', [['id' => $levelVip, 'code' => 'CPQ-TEST-LV-VIP']]);
check(true, '无引用的客户等级通过删除检查');

// ---------------------------------------------------------------------
// 3. 三层价格关系（禁止 float，bccomp 字符串比较）
// ---------------------------------------------------------------------
echo "\n== 三层价格关系 ==\n";
PricePolicyService::assertPriceHierarchy('120000.0000', '108000.0000', '96000.0000');
check(true, '指导价 >= 产线控制价 >= 公司控制价 合法组合通过');
PricePolicyService::assertPriceHierarchy('0.3', '0.2', '0.1');
check(true, '小数精度组合（0.3/0.2/0.1）通过 bccomp 比较（float 会失真）');
expectThrow(function () {
    PricePolicyService::assertPriceHierarchy('100000.0000', '108000.0000', '96000.0000');
}, '指导价低于产线控制价被拒绝');
expectThrow(function () {
    PricePolicyService::assertPriceHierarchy('120000.0000', '90000.0000', '96000.0000');
}, '产线控制价低于公司控制价被拒绝');
expectThrow(function () {
    PricePolicyService::assertPriceHierarchy('120000.0000', '108000.0000', '-1.0000');
}, '公司控制价为负被拒绝');

check(PricePolicyService::normalizeAmount('1.005') === '1.0050', '金额规范化为 4 位小数字符串');
expectThrow(function () {
    PricePolicyService::normalizeAmount('-0.01');
}, '负金额被拒绝');
expectThrow(function () {
    PricePolicyService::normalizeAmount('abc');
}, '非数值金额被拒绝');

// 价格分级（P53 三级控制规则表）
$policy = ['guide_price' => '120000.0000', 'line_floor' => '108000.0000', 'company_floor' => '96000.0000'];
check(PricePolicyService::classifyAgainstFloors('120000.0000', $policy) === 'normal', '报价 >= 指导价 → normal');
check(PricePolicyService::classifyAgainstFloors('110000.0000', $policy) === 'line_approval', '指导价 > 报价 >= 产线控制价 → line_approval');
check(PricePolicyService::classifyAgainstFloors('100000.0000', $policy) === 'company_approval', '产线控制价 > 报价 >= 公司控制价 → company_approval');
check(PricePolicyService::classifyAgainstFloors('90000.0000', $policy) === 'forbidden', '报价 < 公司控制价 → forbidden');

// ---------------------------------------------------------------------
// 4. 价格策略：维度键 + 维度重叠拦截 + 产品线范围
// ---------------------------------------------------------------------
echo "\n== 价格策略维度与重叠 ==\n";
$dimsA = [
    'company' => 'DEMO公司', 'business_unit' => '装备制造', 'market_scope' => 'domestic',
    'region_code' => '', 'customer_level' => '', 'agent_level' => '', 'customer_id' => 0,
    'product_line' => $lineA, 'target_type' => 'model', 'target_id' => $modelA,
    'currency' => 'CNY', 'unit' => 'set',
];
$keyA = PricePolicyService::dimensionKey($dimsA);
check(strlen($keyA) === 64, 'dimension_key 为 SHA-256（64 位十六进制）');
check($keyA === PricePolicyService::dimensionKey(array_merge(['unit' => 'set'], $dimsA)), '维度键与键顺序无关（规范化）');
$dimsB = $dimsA;
$dimsB['currency'] = 'USD';
check($keyA !== PricePolicyService::dimensionKey($dimsB), '不同维度生成不同 dimension_key');
$dimsAgent = $dimsA;
$dimsAgent['agent_id'] = $agentA;
check($keyA !== PricePolicyService::dimensionKey($dimsAgent), '指定代理商参与 dimension_key，避免代理策略串用');

$derivedPolicy = new PricePolicy();
$derivedPolicy->save(array_merge($dimsAgent, [
    'code' => 'CPQ-TEST-POLICY-DERIVED-KEY', 'name' => '派生维度键测试',
    'guide_price' => '120000.0000', 'line_floor' => '108000.0000',
    'company_floor' => '96000.0000', 'cost' => '60000.0000', 'priority' => 10,
    'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    'version' => 1, 'status' => 'draft',
]));
check((string)$derivedPolicy['dimension_key'] === PricePolicyService::dimensionKey($derivedPolicy->getData()), 'PricePolicy 保存时服务端自动派生 dimension_key');
$derivedPolicy->delete();
$policyService->assertReferencedDimensionsExist(['customer_id' => $customerA, 'agent_id' => $agentA]);
check(true, '价格策略指定客户/代理商维度完整性校验通过');
expectThrow(function () use ($policyService, $customerA) {
    $policyService->assertReferencedDimensionsExist(['customer_id' => $customerA, 'agent_id' => 999999]);
}, '价格策略引用不存在代理商时发布前拒绝');

$insertPolicy = function ($code, $status, $effective, $expiry, $key, $line = null) use ($now, $modelA) {
    return (int)Db::name('cpq_price_policy')->insertGetId([
        'code' => $code, 'name' => $code, 'dimension_key' => $key,
        'company' => 'DEMO公司', 'business_unit' => '装备制造', 'market_scope' => 'domestic',
        'product_line' => $line === null ? 'CPQ-TEST-LINE-A' : $line,
        'target_type' => 'model', 'target_id' => $modelA, 'currency' => 'CNY', 'unit' => 'set',
        'guide_price' => '120000.0000', 'line_floor' => '108000.0000', 'company_floor' => '96000.0000',
        'cost' => '60000.0000', 'priority' => 10, 'effective_date' => $effective, 'expiry_date' => $expiry,
        'version' => 1, 'status' => $status, 'createtime' => $now, 'updatetime' => $now,
    ]);
};
$policyPublished = $insertPolicy('CPQ-TEST-POLICY-A', 'published', '2026-01-01', '2026-06-30', $keyA);

expectThrow(function () use ($policyService, $keyA, $lineA, $modelA, $now) {
    $policyService->assertNoDimensionOverlap([
        'id' => 0, 'dimension_key' => $keyA, 'status' => 'published',
        'effective_date' => '2026-03-01', 'expiry_date' => '2026-09-30',
    ], 0);
}, '同维度已发布策略时间区间重叠被拒绝');

// 时间不重叠 → 允许
$policyService->assertNoDimensionOverlap([
    'id' => 0, 'dimension_key' => $keyA, 'status' => 'published',
    'effective_date' => '2026-07-01', 'expiry_date' => '2026-12-31',
], 0);
check(true, '同维度但时间不重叠的策略允许并存');

// 库中仅存在同维度重叠的草稿策略时，不阻止他人发布（草稿不参与重叠）
$keyDraftOnly = PricePolicyService::dimensionKey(array_merge($dimsA, ['customer_level' => 'CPQ-TEST-LV-VIP']));
$insertPolicy('CPQ-TEST-POLICY-DRAFT', 'draft', '2026-01-01', '2026-12-31', $keyDraftOnly);
$policyService->assertNoDimensionOverlap([
    'id' => 0, 'dimension_key' => $keyDraftOnly, 'status' => 'published',
    'effective_date' => '2026-03-01', 'expiry_date' => '2026-09-30',
], 0);
check(true, '库中草稿策略不参与维度重叠校验');

// excludeId：同一条记录自身（改版本重发场景）不算冲突
$policyService->assertNoDimensionOverlap([
    'id' => $policyPublished, 'dimension_key' => $keyA, 'status' => 'published',
    'effective_date' => '2026-01-01', 'expiry_date' => '2026-06-30',
], $policyPublished);
check(true, '同一记录自身被 excludeId 排除，不算重叠');

// ---------------------------------------------------------------------
// 5. 价格表发布：同范围同优先级重叠拦截 + 版本不可变 + 发布版本
// ---------------------------------------------------------------------
echo "\n== 价格表发布与不可变版本 ==\n";
$insertBook = function ($code, $status, $priority, $effective, $expiry) use ($now) {
    return (int)Db::name('cpq_price_book')->insertGetId([
        'code' => $code, 'name' => $code, 'company' => 'DEMO公司', 'business_unit' => '装备制造',
        'market_scope' => 'domestic', 'currency' => 'CNY', 'tax_mode' => 'tax_exclusive',
        'priority' => $priority, 'effective_date' => $effective, 'expiry_date' => $expiry,
        'version' => 1, 'status' => $status, 'createtime' => $now, 'updatetime' => $now,
    ]);
};
$bookA = $insertBook('CPQ-TEST-BOOK-A', 'published', 10, '2026-01-01', '2026-12-31');

expectThrow(function () use ($policyService) {
    $policyService->assertBookScopeNoOverlap([
        'id' => 0, 'company' => 'DEMO公司', 'business_unit' => '装备制造', 'market_scope' => 'domestic',
        'currency' => 'CNY', 'priority' => 10, 'effective_date' => '2026-06-01', 'expiry_date' => '2027-05-31',
    ], 0);
}, '同范围同优先级时间重叠的已发布价格表被拒绝');

// 不同优先级允许
$policyService->assertBookScopeNoOverlap([
    'id' => 0, 'company' => 'DEMO公司', 'business_unit' => '装备制造', 'market_scope' => 'domestic',
    'currency' => 'CNY', 'priority' => 20, 'effective_date' => '2026-06-01', 'expiry_date' => '2027-05-31',
], 0);
check(true, '同范围不同优先级允许并存');

// 生命周期发布校验（publish 路径走 validateForPublish）
$bookPendingOverlap = $insertBook('CPQ-TEST-BOOK-B', 'pending', 10, '2026-06-01', '2027-05-31');
expectThrow(function () use ($lifecycle, $bookPendingOverlap) {
    $lifecycle->publish((new PriceBook())->get($bookPendingOverlap));
}, '生命周期发布价格表时执行同范围重叠校验');

$bookPendingOk = $insertBook('CPQ-TEST-BOOK-C', 'pending', 30, '2026-06-01', '2027-05-31');
$lifecycle->publish((new PriceBook())->get($bookPendingOk));
check(Db::name('cpq_price_book')->where('id', $bookPendingOk)->value('status') === 'published', '合法价格表经生命周期发布成功');

// 版本不可变
expectThrow(function () use ($lifecycle, $bookA) {
    $lifecycle->assertEditable('cpq_price_book', ['status' => 'published']);
}, '已发布价格表不可直接修改');

// 价格条目父表守卫
expectThrow(function () use ($lifecycle, $bookA) {
    $lifecycle->assertWritableParent('cpq_price_entry', ['price_book_id' => $bookA]);
}, '已发布价格表不可再写价格条目');

// 覆盖率：BOOK-A 只有型号A条目，型号B缺失
Db::name('cpq_price_entry')->insert([
    'price_book_id' => $bookA, 'target_type' => 'model', 'target_id' => $modelA,
    'amount' => '120000.0000', 'unit' => 'set', 'createtime' => $now, 'updatetime' => $now,
]);
$coverage = $policyService->coverageReport($bookA);
check($coverage['total'] === 2 && $coverage['covered'] === 1, '在售型号价格覆盖率统计正确（2 款在售，1 款已覆盖）');
$missingCodes = array_column($coverage['missing'], 'code');
check(in_array('CPQ-TEST-MODEL-B', $missingCodes, true), '覆盖缺口列出具缺失型号');

// 发布版本：不可变 / 撤回 / 回滚
$bookRow = Db::name('cpq_price_book')->where('id', $bookPendingOk)->find();
$release1 = $releaseService->recordRelease('cpq_price_book', $bookRow, '首次发布');
check((int)$release1['version'] === 1 && $release1['status'] === 'published' && strlen($release1['content_hash']) === 64, '发布生成版本1（已生效，含内容哈希）');
$release2 = $releaseService->recordRelease('cpq_price_book', $bookRow, '二次发布');
check((int)$release2['version'] === 2, '再次发布生成版本2');

expectThrow(function () use ($releaseService, $release1) {
    $releaseService->assertImmutable($release1);
}, '已生效发布版本不可修改');
expectThrow(function () use ($releaseService, $release1) {
    $releaseService->withdraw($release1['id']);
}, '已生效发布版本不可撤回');

$releasePending = $releaseService->recordRelease('cpq_price_book', $bookRow, '定时发布', $now + 86400);
check($releasePending['status'] === 'pending', '计划生效时间在未来 → 未生效版本');
$withdrawn = $releaseService->withdraw($releasePending['id']);
check(Db::name('cpq_release_version')->where('id', $releasePending['id'])->value('status') === 'withdrawn', '未生效版本可撤回');
expectThrow(function () use ($releaseService, $releasePending) {
    $releaseService->withdraw($releasePending['id']);
}, '已撤回版本不可重复撤回');

$rollback = $releaseService->createRollback($release2['id'], '');
check($rollback['content_hash'] === $release2['content_hash'] && $rollback['status'] === 'pending' && (int)$rollback['version'] > (int)$release2['version'], '回滚 = 重新发布旧内容的新版本（哈希相同、版本递增、未生效）');
expectThrow(function () use ($releaseService, $releasePending) {
    $releaseService->createRollback($releasePending['id'], '');
}, '未生效/已撤回版本不可作为回滚来源');

// ---------------------------------------------------------------------
// 6. 价格规则发布：条件白名单 + 互斥组冲突
// ---------------------------------------------------------------------
echo "\n== 价格规则发布校验 ==\n";
$goodCondition = '{"all":[{"field":"customer_level","operator":"=","value":"CPQ-TEST-LV-VIP"}]}';
$ruleService->assertConditionJson($goodCondition);
check(true, '白名单字段的条件 JSON 通过校验');
$ruleService->assertConditionJson('{"all":[{"field":"agent_id","operator":"=","value":1},{"field":"quantity","operator":">=","value":10}]}');
check(true, '运行期支持的指定代理商与数量字段可通过发布白名单');
expectThrow(function () use ($ruleService) {
    $ruleService->assertConditionJson('{"all":[{"field":"user.password","operator":"=","value":"x"}]}');
}, '白名单外字段被拒绝');
expectThrow(function () use ($ruleService) {
    $ruleService->assertConditionJson('{"all":[{"field":"customer_level","operator":"eval","value":"x"}]}');
}, '白名单外比较符被拒绝');

$insertRule = function ($code, $status, $condition, $exclusiveGroup, $priority, $effective, $expiry) use ($now) {
    return (int)Db::name('cpq_price_rule')->insertGetId([
        'code' => $code, 'name' => $code, 'condition_json' => $condition,
        'adjustment_type' => 'discount', 'adjustment_target' => 'subtotal', 'adjustment_value' => '0.90000000',
        'can_stack' => 0, 'exclusive_group' => $exclusiveGroup, 'priority' => $priority,
        'effective_date' => $effective, 'expiry_date' => $expiry,
        'version' => 1, 'status' => $status, 'createtime' => $now, 'updatetime' => $now,
    ]);
};
$insertRule('CPQ-TEST-PRULE-VIP', 'published', $goodCondition, 'CPQ-TEST-GRP', 10, '2026-01-01', '2026-12-31');

// 同互斥组 + 同优先级 + 条件可能同时命中 → 拒绝
expectThrow(function () use ($ruleService) {
    $ruleService->assertPublishable([
        'id' => 0, 'code' => 'CPQ-TEST-PRULE-NEW', 'condition_json' => '{"all":[{"field":"customer_level","operator":"=","value":"CPQ-TEST-LV-VIP"},{"field":"region_code","operator":"=","value":"EAST"}]}',
        'exclusive_group' => 'CPQ-TEST-GRP', 'priority' => 10,
        'effective_date' => '2026-06-01', 'expiry_date' => '2027-05-31', 'status' => 'published',
    ], 0);
}, '同互斥组同优先级且条件可能同时命中的规则禁止发布');

// 同互斥组但条件取值冲突（不可能同时命中） → 允许
$ruleService->assertPublishable([
    'id' => 0, 'code' => 'CPQ-TEST-PRULE-OTHER', 'condition_json' => '{"all":[{"field":"customer_level","operator":"=","value":"CPQ-TEST-LV-STANDARD"}]}',
    'exclusive_group' => 'CPQ-TEST-GRP', 'priority' => 10,
    'effective_date' => '2026-06-01', 'expiry_date' => '2027-05-31', 'status' => 'published',
], 0);
check(true, '同互斥组但条件互斥（不同客户等级）的规则允许发布');

// 不同互斥组 → 允许
$ruleService->assertPublishable([
    'id' => 0, 'code' => 'CPQ-TEST-PRULE-OTHER2', 'condition_json' => $goodCondition,
    'exclusive_group' => 'CPQ-TEST-GRP-2', 'priority' => 10,
    'effective_date' => '2026-06-01', 'expiry_date' => '2027-05-31', 'status' => 'published',
], 0);
check(true, '不同互斥组的规则允许发布');

// 生命周期 publish 路径
$rulePending = $insertRule('CPQ-TEST-PRULE-PENDING', 'pending', '{"all":[{"field":"user.password","operator":"=","value":"x"}]}', '', 10, '2026-01-01', null);
expectThrow(function () use ($lifecycle, $rulePending) {
    $lifecycle->publish((new PriceRule())->get($rulePending));
}, '生命周期发布价格规则时执行条件白名单校验');

// ---------------------------------------------------------------------
// 7. 价格策略发布：三层关系 + 维度重叠 + 产品线越权
// ---------------------------------------------------------------------
echo "\n== 价格策略发布与数据范围 ==\n";
$policyBadHierarchy = $insertPolicy('CPQ-TEST-POLICY-BAD', 'pending', '2026-07-01', '2026-12-31', 'deadbeef' . str_repeat('0', 56));
Db::name('cpq_price_policy')->where('id', $policyBadHierarchy)->update(['guide_price' => '100.0000', 'line_floor' => '200.0000', 'company_floor' => '300.0000']);
expectThrow(function () use ($lifecycle, $policyBadHierarchy) {
    $lifecycle->publish((new PricePolicy())->get($policyBadHierarchy));
}, '三层价格关系非法的策略禁止发布');

$policyOverlap = $insertPolicy('CPQ-TEST-POLICY-OVERLAP', 'pending', '2026-03-01', '2026-09-30', $keyA);
expectThrow(function () use ($lifecycle, $policyOverlap) {
    $lifecycle->publish((new PricePolicy())->get($policyOverlap));
}, '维度重叠的策略禁止经生命周期发布');

// 产品线数据范围：授权 LINE-B 的管理员发布 LINE-A 策略 → 拒绝
$adminRestricted = 900001;
Db::name('cpq_admin_product_line')->insert(['admin_id' => $adminRestricted, 'product_line' => $lineB, 'createtime' => $now, 'updatetime' => $now]);
$scopeB = ProductLineScopeService::forAdmin($adminRestricted, false);
$policyLineOk = $insertPolicy('CPQ-TEST-POLICY-SCOPE', 'pending', '2026-07-01', '2026-12-31', 'cafe' . str_repeat('1', 60));
expectThrow(function () use ($lifecycle, $policyLineOk, $scopeB) {
    $lifecycle->publish((new PricePolicy())->get($policyLineOk), $scopeB);
}, '无产品线权限的管理员发布该线策略被拒绝');
$scopeAll = new ProductLineScopeService(0, true);
$lifecycle->publish((new PricePolicy())->get($policyLineOk), $scopeAll);
check(Db::name('cpq_price_policy')->where('id', $policyLineOk)->value('status') === 'published', '不受限管理员发布成功');

// ---------------------------------------------------------------------
// 8. 成本/控制价角色脱敏 + 敏感查看审计
// ---------------------------------------------------------------------
echo "\n== 字段级脱敏与审计 ==\n";
$rows = [
    ['id' => 1, 'code' => 'P1', 'guide_price' => '120000.0000', 'line_floor' => '108000.0000', 'company_floor' => '96000.0000', 'cost' => '60000.0000'],
];
$salesView = $maskService->maskRows($rows, ['sales']);
check(isset($salesView[0]['guide_price']) && isset($salesView[0]['line_floor']) && !isset($salesView[0]['company_floor']) && !isset($salesView[0]['cost']), '销售角色：可见指导价与产线控制价，公司控制价与成本被移除');
$lineView = $maskService->maskRows($rows, ['line_pricer']);
check(isset($lineView[0]['cost']) && !isset($lineView[0]['company_floor']), '产线价格管理员：可见成本，不可见公司控制价');
$companyView = $maskService->maskRows($rows, ['company_pricer']);
check(isset($companyView[0]['cost']) && isset($companyView[0]['company_floor']), '公司价格管理员：全字段可见');
$noRoleView = $maskService->maskRows($rows, []);
check(!isset($noRoleView[0]['cost']) && !isset($noRoleView[0]['company_floor']), '无角色（未匹配）按最低权限脱敏');

check($maskService->requiresAudit(['company_pricer']) === true, '可见敏感字段的角色需要审计');
check($maskService->requiresAudit(['sales']) === false, '销售角色查看公开字段不记敏感审计');

$auditBefore = Db::name('cpq_audit_log')->where('action', 'view_sensitive')->count();
$maskService->recordAccess('view_sensitive', 'cpq_price_policy', [1, 2], ['company_pricer']);
check(Db::name('cpq_audit_log')->where('action', 'view_sensitive')->count() === $auditBefore + 1, '敏感价格查看写入审计日志');
$maskService->recordAccess('export', 'cpq_price_policy', [1], ['line_pricer']);
check(Db::name('cpq_audit_log')->where('action', 'export')->count() >= 1, '敏感价格导出写入审计日志');

// ---------------------------------------------------------------------
// 9. Excel 导入预览契约（不写库）
// ---------------------------------------------------------------------
echo "\n== 导入预览契约 ==\n";
$customerCountBefore = Db::name('cpq_customer')->count();
$preview = $importService->preview('customer', [
    ['code' => 'CPQ-TEST-IMP-1', 'name' => '导入客户1', 'default_currency' => 'CNY'],
    ['name' => '缺编码客户'],
    ['code' => 'CPQ-TEST-IMP-1', 'name' => '重复编码'],
    ['code' => 'CPQ-TEST-IMP-2', 'name' => '导入客户2', 'default_currency' => 'cny'],
]);
check($preview['total'] === 4 && $preview['valid_count'] === 1, '客户导入预览统计正确');
check($preview['error_count'] === 3, '缺编码/重复编码/币种小写三行报错');
$firstError = $preview['errors'][0];
check(isset($firstError['row'], $firstError['field'], $firstError['code'], $firstError['message']), '错误行契约包含 row/field/code/message');
check($firstError['row'] === 2 && $firstError['code'] === 'CPQ_IMPORT_REQUIRED', '行号从 1 起（数据行），必填缺失错误码正确');
check(Db::name('cpq_customer')->count() === $customerCountBefore, '导入预览不写库');

$policyPreview = $importService->preview('price_policy', [
    ['code' => 'CPQ-TEST-IMP-P1', 'target_type' => 'model', 'target_id' => (string)$modelA,
        'guide_price' => '120000', 'line_floor' => '108000', 'company_floor' => '96000'],
    ['code' => 'CPQ-TEST-IMP-P2', 'target_type' => 'model', 'target_id' => (string)$modelA,
        'guide_price' => '100', 'line_floor' => '200', 'company_floor' => '300'],
]);
check($policyPreview['valid_count'] === 1 && $policyPreview['error_count'] === 1, '价格策略导入预览：三层关系非法行被拒绝');
$hierarchyError = $policyPreview['errors'][0];
check($hierarchyError['code'] === 'CPQ_PRICE_HIERARCHY' && $hierarchyError['row'] === 2, '三层关系错误码与行号正确');
check($policyPreview['items'][0]['guide_price'] === '120000.0000', '合法行金额规范化为 4 位小数字符串（非 float）');

$ratePreview = $importService->preview('exchange_rate', [
    ['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '7.1', 'effective_date' => '2026-01-01'],
    ['source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => '0', 'effective_date' => '2026-01-01'],
]);
check($ratePreview['valid_count'] === 1 && $ratePreview['error_count'] === 1, '汇率导入预览：rate<=0 行被拒绝');

expectThrow(function () use ($importService) {
    $importService->preview('unknown_type', []);
}, '未知导入类型被拒绝');

echo "\nM2 price & channel master data tests: PASS ({$assertCount} assertions)\n";
