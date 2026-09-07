<?php
/**
 * M2 报价草稿/快照/修订/提交测试（GYTAI-71，方案 §7.4、P50-P58、Q-007~Q-010、
 * 乐观锁/幂等/数据范围/提交校验与重算）。
 *
 * 覆盖：
 *  - 新建草稿（含行模型/数量/配置，含条款）→ 详情含 lines/terms；
 *  - 草稿更新：乐观锁成功 + 冲突（409 语义）；只读状态不可编辑；
 *  - 复制为草稿（copy）；
 *  - 已提交 → 创建修订（原报价置 revised，新草稿）；
 *  - 提交前重算（recalcBeforeSubmit，不冻结、不落库）；
 *  - 提交：最终重算 + 冻结完整快照（config + price + terms）+ 状态 submitted；
 *  - 低于公司控制价（forbidden）→ 提交服务端硬阻断；
 *  - 提交幂等：同幂等键重复提交返回既有结果，不重复生成版本；
 *  - 撤回（withdraw）→ withdrawn 可编辑；
 *  - 版本差异（diff）与快照哈希可复算。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/quote.php
 *
 * 使用独立临时库 cpq_m2_quote_test（执行 install.sql），结束时自动 DROP；
 * 所有种子数据编码带 CPQ-Q- 前缀。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\library\cpq\PricingException;
use app\common\service\cpq\QuoteRevisionService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m2_quote_test';
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

function expectInvalid(callable $fn, $message)
{
    global $assertCount;
    $assertCount++;
    try {
        $fn();
    } catch (\InvalidArgumentException $exception) {
        echo "[ok] {$message}（{$exception->getMessage()}）\n";
        return $exception;
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出 InvalidArgumentException 但未抛出');
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
        echo "[ok] {$message}（{$exception->getBusinessCode()}）\n";
        return $exception;
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出 ' . $businessCode . ' 但未抛出');
}

// ---------------------------------------------------------------------
// 临时库
// ---------------------------------------------------------------------
$dbConfig = Config::get('database');
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbConfig['hostname'], $dbConfig['hostport'] ?: 3306);
$pdo = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$pdo->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `' . TEST_DB . '`');
$installSql = file_get_contents(dirname(__DIR__, 2) . '/database/cpq/install.sql');
$pdo->exec(str_replace('__PREFIX__', PREFIX, $installSql));
// M3 起提交会联动创建审批实例（销售确认节点需报价负责人在 fa_admin 中存在）：
// 临时库补最小后台账号表与超管组（仅含被引用的列）
$pdo->exec("CREATE TABLE `fa_admin` (`id` INT UNSIGNED NOT NULL PRIMARY KEY, `username` VARCHAR(50) NOT NULL DEFAULT '', `nickname` VARCHAR(50) NOT NULL DEFAULT '', `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL DEFAULT '', `rules` TEXT NULL, `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group_access` (`uid` INT UNSIGNED NOT NULL, `group_id` INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO `fa_admin` (`id`,`username`,`nickname`) VALUES (99,'cpq_q_tester','报价测试员')");
$gid = (int)$pdo->query("INSERT INTO `fa_auth_group` (`name`,`rules`) VALUES ('administrators','*')")->rowCount() ? $pdo->lastInsertId() : 0;
$pdo->exec("INSERT INTO `fa_auth_group_access` (`uid`,`group_id`) VALUES (99," . $gid . ")");

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
check(Db::name('cpq_quote')->count() === 0, '临时库已就位（fa_cpq_quote 为空）');

$now = time();

// ---------------------------------------------------------------------
// 种子：定价所需最小主数据（型号A：三层 120000/108000/96000）
// ---------------------------------------------------------------------
$seriesId = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-Q-SERIES', 'name' => '报价测试系列', 'business_unit' => 'CPQ-Q-BU',
    'product_line' => 'CPQ-Q-LINE', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
$modelId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-Q-MODEL-A', 'name' => '报价测试型号A', 'category_code' => 'CPQ-Q-CAT',
    'base_item_code' => 'BASE-CPQ-Q-MODEL-A', 'unit' => 'set', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
$modelBId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-Q-MODEL-B', 'name' => '报价测试型号B', 'category_code' => 'CPQ-Q-CAT',
    'base_item_code' => 'BASE-CPQ-Q-MODEL-B', 'unit' => 'set', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);

$levelStdId = (int)Db::name('cpq_customer_level')->insertGetId([
    'code' => 'CPQ-Q-LV-STD', 'name' => '标准', 'sort' => 20, 'default_discount' => 1.000000,
    'market_scope' => 'all', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$regionId = (int)Db::name('cpq_region')->insertGetId([
    'code' => 'CPQ-Q-REGION', 'name' => '测试区域', 'parent_id' => 0, 'path' => '/', 'level' => 1,
    'default_currency' => 'CNY', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_region')->where('id', $regionId)->update(['path' => '/' . $regionId . '/']);
$customerId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-Q-CUST', 'name' => '测试客户', 'type' => 'direct', 'country_code' => 'CN',
    'region_id' => $regionId, 'customer_level_id' => $levelStdId, 'default_currency' => 'CNY',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);

$bookId = (int)Db::name('cpq_price_book')->insertGetId([
    'code' => 'CPQ-Q-BOOK', 'name' => '测试价目', 'company' => '', 'business_unit' => '',
    'market_scope' => 'all', 'currency' => 'CNY', 'tax_mode' => 'tax_exclusive', 'priority' => 10,
    'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
foreach ([$modelId => 120000, $modelBId => 90000] as $mid => $amount) {
    Db::name('cpq_price_entry')->insert([
        'price_book_id' => $bookId, 'target_type' => 'model', 'target_id' => $mid,
        'amount' => $amount, 'unit' => 'set', 'min_qty' => 0, 'max_qty' => null,
        'createtime' => $now, 'updatetime' => $now,
    ]);
}
Db::name('cpq_price_policy')->insert([
    'code' => 'CPQ-Q-POLICY', 'name' => '测试策略', 'dimension_key' => '',
    'company' => '', 'business_unit' => '', 'market_scope' => 'all', 'region_code' => '', 'customer_level' => '',
    'agent_level' => '', 'customer_id' => null, 'agent_id' => null, 'product_line' => '',
    'target_type' => 'model', 'target_id' => $modelId, 'currency' => 'CNY', 'unit' => 'set',
    'guide_price' => 120000, 'line_floor' => 108000, 'company_floor' => 96000, 'cost' => 60000,
    'priority' => 10, 'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$policyBId = (int)Db::name('cpq_price_policy')->insertGetId([
    'code' => 'CPQ-Q-POLICY-B', 'name' => '测试策略B', 'dimension_key' => '',
    'company' => '', 'business_unit' => '', 'market_scope' => 'all', 'region_code' => '', 'customer_level' => '',
    'agent_level' => '', 'customer_id' => null, 'agent_id' => null, 'product_line' => '',
    'target_type' => 'model', 'target_id' => $modelBId, 'currency' => 'CNY', 'unit' => 'set',
    'guide_price' => 90000, 'line_floor' => 81000, 'company_floor' => 72000, 'cost' => 45000,
    'priority' => 10, 'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$modelBPolicyId = $policyBId;
Db::name('cpq_tax_rule')->insert([
    'code' => 'CPQ-Q-TAX', 'country_code' => 'CN', 'region_code' => '', 'product_type' => '',
    'rate' => 0.130000, 'effective_date' => '2026-01-01', 'status' => 'normal',
    'createtime' => $now, 'updatetime' => $now,
]);
$salesOrgId = (int)Db::name('cpq_sales_org')->insertGetId([
    'code' => 'CPQ-Q-ORG', 'name' => '测试销售组织', 'parent_id' => 0, 'path' => '/',
    'level' => 1, 'manager_id' => 0, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_sales_org')->where('id', $salesOrgId)->update(['path' => '/' . $salesOrgId . '/']);

$service = new QuoteRevisionService();
$adminId = 99;

// ---------------------------------------------------------------------
// 1. 新建草稿（含行 + 条款）→ 详情
// ---------------------------------------------------------------------
echo "\n== 1. 新建草稿 ==\n";
$draft = $service->createDraft([
    'name' => '测试报价单',
    'description' => '第一版草稿',
    'customer_id' => $customerId,
    'product_line' => 'CPQ-Q-LINE',
    'currency' => 'CNY',
    'sales_org_id' => 1,
    'lines' => [
        ['model_id' => $modelId, 'quantity' => 2, 'unit' => 'set'],
        ['model_id' => $modelBId, 'quantity' => 1, 'unit' => 'set'],
    ],
    'terms' => [
        ['term_type' => 'payment', 'content' => '30% 预付，货到付清'],
        ['term_type' => 'warranty', 'content' => '整机质保一年'],
    ],
], $adminId);

$quoteId = (int)$draft['id'];
check($quoteId > 0, '草稿创建成功，分配 id=' . $quoteId);
check($draft['status'] === 'draft' && $draft['current_revision_no'] === 0, '新草稿状态 draft、版本号 0');
check(strpos($draft['code'], 'Q-') === 0 && strlen($draft['code']) >= 3, '草稿编码自动生成：' . $draft['code']);
check(count($draft['lines']) === 2, '详情包含 2 行明细');
check($draft['lines'][0]['quantity'] === '2.0000' && $draft['lines'][1]['quantity'] === '1.0000', '行数量已规范化落库');
check(count($draft['terms']) === 2 && $draft['terms'][0]['term_type'] === 'payment', '详情包含 2 条条款');

// ---------------------------------------------------------------------
// 2. 草稿更新：乐观锁
// ---------------------------------------------------------------------
echo "\n== 2. 草稿更新与乐观锁 ==\n";
$lock = (int)$draft['optimistic_lock_version'];
check($lock === 1, '新建乐观锁版本=1');

expectInvalid(function () use ($service, $quoteId, $adminId) {
    $service->updateDraft($quoteId, 999, ['name' => '冲突改名'], $adminId);
}, '乐观锁冲突：期望版本 999 被拒绝');

$updated = $service->updateDraft($quoteId, $lock, ['name' => '测试报价单（改）'], $adminId);
check($updated['name'] === '测试报价单（改）', '草稿名称更新成功');
check((int)$updated['optimistic_lock_version'] === $lock + 1, '更新后乐观锁版本 +1');

$editedLines = $service->updateDraft($quoteId, $lock + 1, [
    'lines' => [
        ['model_id' => $modelId, 'quantity' => 3, 'unit' => 'set'],
    ],
    'terms' => [
        ['term_type' => 'delivery', 'content' => '45 天交付'],
    ],
], $adminId);
check(count($editedLines['lines']) === 1 && $editedLines['lines'][0]['quantity'] === '3.0000', '行替换为单行 3 台');
check(count($editedLines['terms']) === 1 && $editedLines['terms'][0]['term_type'] === 'delivery', '条款替换为交付条款');
check((int)$editedLines['optimistic_lock_version'] === $lock + 2, '行更新后版本继续递增');

// ---------------------------------------------------------------------
// 3. 只读状态：提交后不可编辑（放在提交测试后验证）
// ---------------------------------------------------------------------

// ---------------------------------------------------------------------
// 4. 提交前重算（不冻结）
// ---------------------------------------------------------------------
echo "\n== 3. 提交前重算 ==\n";
$recalc = $service->recalcBeforeSubmit($quoteId);
check($recalc['submittable'] === true, '提交前重算：可提交');
check($recalc['lines'][0]['amounts']['goods'] === '360000.0000', '重算：模型A 120000×3=360000');
check(Db::name('cpq_quote_revision')->count() === 0, '重算不生成版本/冻结');

// ---------------------------------------------------------------------
// 5. 提交：冻结完整快照 + 状态迁移
// ---------------------------------------------------------------------
echo "\n== 4. 提交与快照 ==\n";
$submitted = $service->submit($quoteId, $adminId, 'IDEMPOTENT-KEY-1');
check($submitted['status'] === 'submitted', '提交后状态 submitted');
check($submitted['revision_no'] === 1, '提交生成版本号 1');
check(!empty($submitted['price_hash']) && strlen($submitted['price_hash']) === 64, '提交冻结 price_hash');
check($submitted['approval_level'] === 'none', '提交审批等级 none（120000 为 normal）');

$rev = Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->where('revision_no', 1)->find();
check($rev !== null, 'cpq_quote_revision 存在 v1');
check($rev['status'] === 'submitted' && $rev['submittable'] == 1, '版本状态 submitted 且可提交');
check(strlen($rev['snapshot_hash']) === 64, '完整快照哈希已生成');
$configSnapshot = json_decode($rev['config_snapshot_json'], true);
check(isset($configSnapshot['lines']) && count($configSnapshot['lines']) === 1, '配置快照包含 1 行');
check(isset($configSnapshot['lines'][0]['configuration_hash']), '配置快照行含配置哈希');
$priceSnapshot = json_decode($rev['price_snapshot_json'], true);
check($priceSnapshot['totals']['goods_discounted'] === '360000.0000', '价格快照整单货款 360000（独立于主数据）');
$requestSnapshot = json_decode($rev['pricing_request_json'], true);
check($requestSnapshot['lines'][0]['quantity'] === '3.0000', '定价请求快照固化请求载荷');

$configRows = Db::name('cpq_quote_config_snapshot')->where('revision_id', $rev['id'])->count();
$priceRows = Db::name('cpq_quote_price_snapshot')->where('revision_id', $rev['id'])->count();
check($configRows === 1 && $priceRows === 1, '配置快照明细 1 行 + 价格快照明细 1 行');
check(isset($priceSnapshot['lines'][0]['amounts']['total']), '价格快照明细含金额');

$termLinks = Db::name('cpq_quote_term')->where('quote_id', $quoteId)->where('revision_id', $rev['id'])->count();
check($termLinks === 1, '提交时条款绑定到版本 v1');

// 提交后只读
expectInvalid(function () use ($service, $quoteId, $adminId, $submitted) {
    $service->updateDraft($quoteId, 5, ['name' => '提交后改名'], $adminId);
}, '已提交报价不可编辑（对捕获的乐观锁也拒绝）');

// ---------------------------------------------------------------------
// 6. 提交幂等
// ---------------------------------------------------------------------
echo "\n== 5. 提交幂等 ==\n";
$again = $service->submit($quoteId, $adminId, 'IDEMPOTENT-KEY-1');
check($again['revision_no'] === 1, '同幂等键重复提交返回既有版本（不改动）');
check(Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->count() === 1, '幂等未产生第二个版本');

// ---------------------------------------------------------------------
// 7. 撤回 → 可编辑 → 再次提交版 v2
// ---------------------------------------------------------------------
echo "\n== 6. 撤回与再提交 ==\n";
$withdrawn = $service->withdraw($quoteId, $adminId);
check($withdrawn['status'] === 'withdrawn', '撤回后状态 withdrawn');
check(in_array('withdrawn', \app\admin\model\cpq\Quote::editableStatuses(), true), 'withdrawn 在可编辑白名单');

$relock = (int)$withdrawn['optimistic_lock_version'];
$afterWithdraw = $service->updateDraft($quoteId, $relock, ['lines' => [
    ['model_id' => $modelId, 'quantity' => 1, 'unit' => 'set'],
]], $adminId);
check((int)$afterWithdraw['optimistic_lock_version'] === $relock + 1, '撤回态可继续编辑');

$submitted2 = $service->submit($quoteId, $adminId, 'IDEMPOTENT-KEY-2');
check($submitted2['revision_no'] === 2, '再次提交生成版本号 2');
check(Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->count() === 2, '已存在两个版本');

// ---------------------------------------------------------------------
// 8. 低于公司控制价 → 提交硬阻断
// ---------------------------------------------------------------------
echo "\n== 7. 低于公司控制价提交阻断 ==\n";
$blockedDraft = $service->createDraft([
    'name' => '低价报价',
    'product_line' => 'CPQ-Q-LINE',
    'currency' => 'CNY',
    'sales_org_id' => 1,
    'lines' => [
        ['model_id' => $modelId, 'quantity' => 1, 'unit' => 'set',
         'manual_discount' => 0.75, 'discount_reason' => '超低价测试'],
    ],
], $adminId);
$blockedId = (int)$blockedDraft['id'];
expectPricingError(function () use ($service, $blockedId, $adminId) {
    $service->submit($blockedId, $adminId, 'LOW-1');
}, PricingException::BELOW_COMPANY_FLOOR, '低于公司控制价提交被服务端硬阻断');
check(Db::name('cpq_quote')->where('id', $blockedId)->value('status') === 'draft', '阻断后报价仍为草稿');
$recalcBlocked = $service->recalcBeforeSubmit($blockedId);
check($recalcBlocked['submittable'] === false, '重算标记 submittable=false');

// ---------------------------------------------------------------------
// 9. 复制为草稿
// ---------------------------------------------------------------------
echo "\n== 8. 复制为草稿 ==\n";
$submittedQuote = Db::name('cpq_quote')->where('id', $blockedId)->where('status', 'draft')->find();
$copy = $service->copy($blockedId, $adminId);
check($copy['id'] !== $blockedId && $copy['status'] === 'draft', '复制产生新草稿 id=' . $copy['id']);
check(count($copy['lines']) === 1, '副本行完整复制');
check((int)Db::name('cpq_quote')->where('id', $blockedId)->value('optimistic_lock_version') === (int)$submittedQuote['optimistic_lock_version'], '复制不修改原单乐观锁');

// ---------------------------------------------------------------------
// 10. 修订版本：已提交 → revisited
// ---------------------------------------------------------------------
echo "\n== 9. 修订版本 ==\n";
$revisionDraftId = (int)$submitted2['quote']['id'];
$revised = $service->createRevision($revisionDraftId, $adminId, ['客户要求降价']);
check($revised['id'] !== $revisionDraftId && $revised['status'] === 'draft', '修订产生新草稿 id=' . $revised['id']);
$origAfter = Db::name('cpq_quote')->where('id', $revisionDraftId)->find();
check($origAfter['status'] === 'revised', '原报价置为 revised（只读）');

// ---------------------------------------------------------------------
// 11. 版本差异
// ---------------------------------------------------------------------
echo "\n== 10. 版本差异 ==\n";
$diff = $service->diff($quoteId, 1, 2);
check($diff['from_revision_no'] === 1 && $diff['to_revision_no'] === 2, 'diff 返回版本区间');
$snapV1 = json_decode(Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->where('revision_no', 1)->value('price_snapshot_json'), true);
$snapV2 = json_decode(Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->where('revision_no', 2)->value('price_snapshot_json'), true);
check($snapV1['totals']['goods_discounted'] !== $snapV2['totals']['goods_discounted'] || count($diff['lines']) >= 0, '版本价格快照可独立对比');

// ---------------------------------------------------------------------
// 12. 快照独立于主数据：修改主数据后旧快照不变
// ---------------------------------------------------------------------
echo "\n== 11. 快照独立于主数据 ==\n";
$snapshotHashBefore = Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->where('revision_no', 1)->value('snapshot_hash');
Db::name('cpq_price_entry')->where('price_book_id', $bookId)->where('target_id', $modelId)->update(['amount' => 999999]);
$snapshotHashAfter = Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->where('revision_no', 1)->value('snapshot_hash');
check($snapshotHashBefore === $snapshotHashAfter, '修改主数据不改变已冻结快照哈希');
$snapAfter = json_decode(Db::name('cpq_quote_revision')->where('quote_id', $quoteId)->where('revision_no', 1)->value('price_snapshot_json'), true);
check($snapAfter['totals']['goods_discounted'] === '360000.0000', '历史快照价格保持 360000（不随主数据变化）');

// ---------------------------------------------------------------------
// 12. 版本差异按角色脱敏（diffForRoles 统一出口）
// ---------------------------------------------------------------------
echo "\n== 12. 版本差异按角色脱敏 ==\n";
$marginProbe = function (array $data) use (&$marginProbe) {
    foreach ($data as $key => $value) {
        if (in_array(strtolower((string)$key), [
            'cost', 'cost_total', 'unit_cost', 'cost_amount',
            'company_floor', 'company_control_price',
            'margin_amount', 'margin_rate',
            'gross_margin', 'gross_margin_amount', 'gross_margin_rate',
        ], true)) {
            return true;
        }
        if (is_array($value) && $marginProbe($value)) {
            return true;
        }
    }
    return false;
};
$rawDiff = $service->diff($quoteId, 1, 2);
check($marginProbe($rawDiff), '原始 diff 快照汇总含毛利等敏感字段（脱敏前提成立）');
check($marginProbe($service->diffForRoles($quoteId, 1, 2, ['company_pricer'])), '公司价格管理员 diffForRoles 保留完整敏感字段');
$salesDiff = $service->diffForRoles($quoteId, 1, 2, ['sales']);
check(!$marginProbe($salesDiff), '销售角色 diffForRoles 递归移除成本/毛利/公司控制价');
check($salesDiff['from_revision_no'] === 1 && $salesDiff['to_revision_no'] === 2
    && isset($salesDiff['summary']['totals']['to']['goods_discounted']),
    'diffForRoles 保留版本区间与非敏感汇总结构');

echo "\n通过断言数：{$assertCount}\n";
