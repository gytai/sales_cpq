<?php
/**
 * M4 收口性能验证测试（GYTAI-76）。
 *
 * 方案性能目标（逐项实测计时并断言，计时均先 warmup 排除一次性开销）：
 *  1. 配置校验 < 500ms：60 配置组 / 240 规则 schema 一次完整服务端 validate
 *     （构造方式参照 tests/cpq/rule_analysis.php 性能段与 tests/cpq/run.php 的
 *     $schema；ConfigurationService::validate 为纯数组校验，无需库表种子）；
 *  2. 100 行报价试算 < 2s：PricingService::calculate 100 行 input
 *     （参照 tests/cpq/pricing.php:784-799，种子自 pricing.php:154-330 裁剪）；
 *  3. PDF 生成 < 30s：已提交报价 + published 中文模板 → QuoteDocumentService
 *     createJob → process 同步生成（流程参照 tests/cpq/m3_approval.php:478-506；
 *     请求人用超管 rules='*'，QuoteDataScopeService::assertQuoteAccess 为
 *     unrestricted；中文依赖容器内 wqy 字体）；
 *  4. 报价列表查询 < 2s：1200 条报价种子，QuoteDataScopeService::forAdmin(sales)
 *     ->applyToQuoteQuery 叠加数据范围后分页 20 条 + count
 *     （组织/区域/成员种子参照 tests/cpq/m4_reporting.php）。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/performance.php
 *
 * 使用独立临时库 cpq_m4_performance_test（install.sql + 最小 fa_admin/fa_auth_group
 * 表），结束时自动 DROP；生成的 PDF 文件测完即删；种子编码带 CPQ-PERF- 前缀。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\service\cpq\ConfigurationService;
use app\common\service\cpq\PricePolicyService;
use app\common\service\cpq\PricingService;
use app\common\service\cpq\QuoteDataScopeService;
use app\common\service\cpq\QuoteDocumentService;
use app\common\service\cpq\QuoteRevisionService;
use app\common\service\cpq\QuoteTemplateService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m4_performance_test';
const PREFIX = 'fa_';
const LINE = 'CPQ-PERF-LINE';

const ADMIN_SUPER = 99;   // 超管（rules='*'），PDF 请求人/报价负责人
const ADMIN_SALES = 10;   // 普通销售（owner-only 数据范围）

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

/** warmup $warmups 次后正式跑 $runs 次，返回 [最大耗时ms, 最后一次结果] */
function timed(callable $fn, $warmups = 1, $runs = 3)
{
    $result = null;
    for ($i = 0; $i < $warmups; $i++) {
        $result = $fn();
    }
    $maxMs = 0.0;
    for ($i = 0; $i < $runs; $i++) {
        $startedAt = microtime(true);
        $result = $fn();
        $maxMs = max($maxMs, (microtime(true) - $startedAt) * 1000);
    }
    return [$maxMs, $result];
}

// ---------------------------------------------------------------------
// 临时库（install.sql + 最小后台账号表），结束时自动 DROP
// ---------------------------------------------------------------------
$dbConfig = Config::get('database');
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbConfig['hostname'], $dbConfig['hostport'] ?: 3306);
$pdo = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$pdo->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `' . TEST_DB . '`');
$pdo->exec(str_replace('__PREFIX__', PREFIX, file_get_contents(dirname(__DIR__, 2) . '/database/cpq/install.sql')));
// 提交联动审批与数据范围判定依赖的最小后台账号/权限表（仅含被引用的列）
$pdo->exec("CREATE TABLE `fa_admin` (`id` INT UNSIGNED NOT NULL PRIMARY KEY, `username` VARCHAR(50) NOT NULL DEFAULT '', `nickname` VARCHAR(50) NOT NULL DEFAULT '', `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL DEFAULT '', `rules` TEXT NULL, `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group_access` (`uid` INT UNSIGNED NOT NULL, `group_id` INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO `fa_admin` (`id`,`username`,`nickname`) VALUES (99,'cpq_perf_admin','性能测试超管'),(10,'cpq_perf_sales','性能测试销售')");
// 注意：Config::set 之前一律走 $pdo（TP5 Db 首个连接按空配置缓存，提前用 Db 会连到正式库）
$pdo->exec("INSERT INTO `fa_auth_group` (`name`,`rules`) VALUES ('administrators','*')");
$groupSuper = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `fa_auth_group` (`name`,`rules`) VALUES ('sales','cpq/report')");
$groupSales = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO `fa_auth_group_access` (`uid`,`group_id`) VALUES (" . ADMIN_SUPER . "," . $groupSuper . "),(" . ADMIN_SALES . "," . $groupSales . ")");

$generatedFiles = []; // 生成的 PDF，测完清理
register_shutdown_function(function () use ($rootDsn, $dbConfig, &$generatedFiles) {
    foreach ($generatedFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
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
// 1. 配置校验 < 500ms（60 配置组 / 240 规则，纯数组校验，无需种子）
// ---------------------------------------------------------------------
echo "\n== 1. 配置校验性能 ==\n";
$bigGroups = [];
for ($i = 0; $i < 40; $i++) {
    $options = [];
    for ($j = 0; $j < 4; $j++) {
        $options[] = ['code' => 'opt' . $j, 'material_code' => 'MAT-' . $i . '-' . $j, 'default_qty' => '1'];
    }
    $bigGroups[] = ['code' => 'single_' . $i, 'name' => '单选组' . $i, 'input_type' => 'single', 'is_required' => 1, 'options' => $options];
}
for ($i = 0; $i < 10; $i++) {
    $options = [];
    for ($j = 0; $j < 6; $j++) {
        $options[] = ['code' => 'opt' . $j, 'material_code' => 'MAT-M' . $i . '-' . $j, 'default_qty' => '1'];
    }
    $bigGroups[] = ['code' => 'multi_' . $i, 'name' => '多选组' . $i, 'input_type' => 'multiple', 'is_required' => 0, 'max_select' => 4, 'options' => $options];
}
for ($i = 0; $i < 10; $i++) {
    $bigGroups[] = ['code' => 'num_' . $i, 'name' => '数值组' . $i, 'input_type' => 'number', 'is_required' => 0];
}

$bigRules = [];
for ($i = 0; $i < 60; $i++) {
    $bigRules[] = [
        'code' => 'R-REQ-' . $i, 'priority' => $i, 'severity' => 'blocking', 'message' => '依赖',
        'condition' => ['field' => 'configuration.single_' . ($i % 40), 'operator' => '=', 'value' => 'opt1'],
        'actions' => [['action' => 'require', 'target' => 'single_' . (($i + 1) % 40)]],
    ];
    $bigRules[] = [
        'code' => 'R-EXC-' . $i, 'priority' => $i, 'severity' => 'blocking', 'message' => '互斥',
        'condition' => ['field' => 'configuration.multi_' . ($i % 10), 'operator' => 'contains', 'value' => 'opt2'],
        'actions' => [['action' => 'exclude', 'target' => 'multi_' . ($i % 10), 'value' => 'opt3']],
    ];
    $bigRules[] = [
        'code' => 'R-RANGE-' . $i, 'priority' => $i, 'severity' => 'blocking', 'message' => '范围',
        'condition' => [],
        'actions' => [['action' => 'min_max', 'target' => 'num_' . ($i % 10), 'min' => 0, 'max' => 100]],
    ];
    $bigRules[] = [
        'code' => 'R-WARN-' . $i, 'priority' => $i, 'severity' => 'warning', 'message' => '提示',
        'condition' => ['all' => [
            ['field' => 'configuration.single_' . ($i % 40), 'operator' => 'in', 'value' => ['opt0', 'opt1']],
            ['not' => ['field' => 'configuration.num_' . ($i % 10), 'operator' => 'empty']],
        ]],
        'actions' => [['action' => 'warning', 'target' => 'single_' . ($i % 40)]],
    ];
}

$bigSchema = ['model' => ['code' => 'CPQ-PERF', 'version' => 1], 'groups' => $bigGroups, 'rules' => $bigRules];
$bigInput = [];
for ($i = 0; $i < 40; $i++) {
    $bigInput['single_' . $i] = 'opt' . ($i % 4);
}
for ($i = 0; $i < 10; $i++) {
    $bigInput['multi_' . $i] = ['opt0', 'opt1'];
    $bigInput['num_' . $i] = $i * 7;
}

$configService = new ConfigurationService();
list($validateMs, $bigResult) = timed(function () use ($configService, $bigSchema, $bigInput) {
    return $configService->validate($bigSchema, $bigInput);
}, 1, 3); // warmup 1 次 + 正式 3 次取最大值
check($bigResult['is_valid'] === true, '大规模配置（60 组 / 240 规则）校验通过');
check($validateMs < 500, sprintf('配置校验实测 %.1f ms（目标 < 500 ms）', $validateMs));

// ---------------------------------------------------------------------
// 种子：定价所需最小主数据（自 pricing.php:154-330 裁剪，型号A + 功率选项 + 安装服务）
// ---------------------------------------------------------------------
$seriesId = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-PERF-SERIES', 'name' => '性能测试系列', 'business_unit' => 'CPQ-PERF-BU',
    'product_line' => LINE, 'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$modelId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-PERF-MODEL-A', 'name' => '性能测试型号A', 'category_code' => 'CPQ-PERF-CAT',
    'base_item_code' => 'BASE-CPQ-PERF-MODEL-A', 'unit' => 'set', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
$powerGroupId = (int)Db::name('cpq_option_group')->insertGetId([
    'code' => 'CPQ-PERF-G-POWER', 'name' => '功率等级', 'input_type' => 'single', 'is_required' => 0,
    'affects_price' => 1, 'affects_bom' => 0, 'sort' => 10, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$optionHighId = (int)Db::name('cpq_option_value')->insertGetId([
    'group_id' => $powerGroupId, 'code' => 'high', 'name' => '高功率', 'material_code' => 'POWER-HIGH',
    'default_qty' => 1, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_model_option_group')->insert([
    'model_id' => $modelId, 'group_id' => $powerGroupId,
    'sort' => 10, 'is_visible' => 1, 'is_required' => 0, 'createtime' => $now, 'updatetime' => $now,
]);
$accessoryInstallId = (int)Db::name('cpq_accessory_service')->insertGetId([
    'code' => 'CPQ-PERF-ACC-INSTALL', 'name' => '安装调试服务', 'type' => 'service', 'unit' => 'service',
    'tax_category' => 'service', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$levelId = (int)Db::name('cpq_customer_level')->insertGetId([
    'code' => 'CPQ-PERF-LV-STD', 'name' => '标准', 'sort' => 20, 'default_discount' => 1.000000,
    'market_scope' => 'all', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$bookId = (int)Db::name('cpq_price_book')->insertGetId([
    'code' => 'CPQ-PERF-BOOK-CNY', 'name' => '性能测试价目', 'company' => '', 'business_unit' => '',
    'market_scope' => 'all', 'currency' => 'CNY', 'tax_mode' => 'tax_exclusive', 'priority' => 10,
    'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
$entry = function ($targetType, $targetId, $amount, $unit = 'set') use ($now, $bookId) {
    return (int)Db::name('cpq_price_entry')->insertGetId([
        'price_book_id' => $bookId, 'target_type' => $targetType, 'target_id' => $targetId,
        'amount' => $amount, 'unit' => $unit, 'min_qty' => 0, 'max_qty' => null,
        'createtime' => $now, 'updatetime' => $now,
    ]);
};
$entry('model', $modelId, 120000);
$entry('option', $optionHighId, 8000);
$entry('accessory_service', $accessoryInstallId, 5000, 'service');
$policyRow = [
    'code' => 'CPQ-PERF-POLICY-A', 'name' => '型号A默认策略', 'dimension_key' => '',
    'company' => '', 'business_unit' => '', 'market_scope' => 'all', 'region_code' => '', 'customer_level' => '',
    'agent_level' => '', 'customer_id' => null, 'agent_id' => null, 'product_line' => '',
    'target_type' => 'model', 'target_id' => $modelId, 'currency' => 'CNY', 'unit' => 'set',
    'guide_price' => 120000, 'line_floor' => 108000, 'company_floor' => 96000, 'cost' => 60000,
    'priority' => 10, 'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
];
$policyRow['dimension_key'] = PricePolicyService::dimensionKey($policyRow);
Db::name('cpq_price_policy')->insert($policyRow);
Db::name('cpq_tax_rule')->insert([
    'code' => 'CPQ-PERF-TAX-CN', 'country_code' => 'CN', 'region_code' => '', 'product_type' => '',
    'rate' => 0.130000, 'effective_date' => '2026-01-01', 'status' => 'normal',
    'createtime' => $now, 'updatetime' => $now,
]);

// ---------------------------------------------------------------------
// 种子：§3/§4 共用的销售组织 / 区域 / 客户（构造参照 m4_reporting.php）
// ---------------------------------------------------------------------
$insertTreeNode = function ($table, $code, $name, $parentId = 0, array $extra = []) use ($now) {
    $parent = $parentId > 0 ? Db::name($table)->where('id', (int)$parentId)->find() : null;
    $data = array_merge([
        'code' => $code, 'name' => $name, 'parent_id' => (int)$parentId, 'path' => '/',
        'level' => $parent ? (int)$parent['level'] + 1 : 1,
        'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
    ], $extra);
    $id = (int)Db::name($table)->insertGetId($data);
    $parentPath = $parent ? (string)$parent['path'] : '/';
    Db::name($table)->where('id', $id)->update(['path' => rtrim($parentPath, '/') . '/' . $id . '/']);
    return $id;
};
$orgHq = $insertTreeNode('cpq_sales_org', 'CPQ-PERF-ORG-HQ', '总部');
$orgEast = $insertTreeNode('cpq_sales_org', 'CPQ-PERF-ORG-EAST', '华东', $orgHq);
$orgEastTeam = $insertTreeNode('cpq_sales_org', 'CPQ-PERF-ORG-EAST-TEAM', '华东一组', $orgEast);
$orgWest = $insertTreeNode('cpq_sales_org', 'CPQ-PERF-ORG-WEST', '华西', $orgHq);
Db::name('cpq_sales_org_member')->insert([
    'org_id' => $orgEastTeam, 'admin_id' => ADMIN_SALES, 'role' => 'sales',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$regionEast = $insertTreeNode('cpq_region', 'CPQ-PERF-REGION-EAST', '华东区域');
$regionWest = $insertTreeNode('cpq_region', 'CPQ-PERF-REGION-WEST', '华西区域');
$customerEastId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-PERF-CUST-EAST', 'name' => '华东客户', 'type' => 'direct', 'country_code' => 'CN',
    'region_id' => $regionEast, 'customer_level_id' => $levelId, 'default_currency' => 'CNY',
    'sales_org_id' => $orgEastTeam, 'owner_id' => ADMIN_SALES,
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$customerWestId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-PERF-CUST-WEST', 'name' => '华西客户', 'type' => 'direct', 'country_code' => 'CN',
    'region_id' => $regionWest, 'customer_level_id' => $levelId, 'default_currency' => 'CNY',
    'sales_org_id' => $orgWest, 'owner_id' => 0,
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
// 普通销售的产品线授权（无授权行则 fail-closed）
Db::name('cpq_admin_product_line')->insert([
    'admin_id' => ADMIN_SALES, 'product_line' => LINE, 'createtime' => $now, 'updatetime' => $now,
]);

// ---------------------------------------------------------------------
// 2. 100 行报价试算 < 2s（参照 pricing.php:784-799）
// ---------------------------------------------------------------------
echo "\n== 2. 100 行报价试算性能 ==\n";
$pricingService = new PricingService();
$perfLines = [];
for ($i = 0; $i < 100; $i++) {
    $perfLines[] = ['model_id' => $modelId, 'quantity' => 2,
        'configuration' => ['CPQ-PERF-G-POWER' => 'high'],
        'accessories' => [['id' => $accessoryInstallId, 'quantity' => 1]]];
}
list($pricingMs, $pricingResult) = timed(function () use ($pricingService, $perfLines, $customerEastId) {
    return $pricingService->calculate(['customer_id' => $customerEastId, 'date' => '2026-09-01', 'lines' => $perfLines]);
}, 1, 3); // warmup 1 次 + 正式 3 次取最大值
check(count($pricingResult['lines']) === 100, '100 行报价计算完成');
check($pricingMs < 2000, sprintf('100 行报价试算实测 %.1f ms（目标 < 2000 ms）', $pricingMs));

// ---------------------------------------------------------------------
// 3. PDF 生成 < 30s（流程参照 m3_approval.php:478-506；warmup 用另一份报价，
//    因 createJob 对同报价+模板+语言幂等复用，正式计时必须用新报价）
// ---------------------------------------------------------------------
echo "\n== 3. PDF 生成性能 ==\n";
$templateService = new QuoteTemplateService();
$templateId = (int)Db::name('cpq_quote_template')->insertGetId([
    'code' => 'CPQ-PERF-QT-ZH', 'name' => '性能测试模板', 'name_en' => 'Performance Test Template',
    'language' => 'zh', 'market_scope' => 'all', 'paper_size' => 'A4', 'is_default' => 1,
    'content_json' => json_encode($templateService->defaultContent('zh'), JSON_UNESCAPED_UNICODE),
    'allowed_variables' => json_encode(array_keys(QuoteTemplateService::VARIABLE_WHITELIST)),
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);

$quoteService = new QuoteRevisionService();
$documentService = new QuoteDocumentService();

/** 建一份 1 行报价并提交（status=submitted、current_revision_no=1），返回报价 ID */
$submitQuote = function ($key) use ($quoteService, $modelId, $customerEastId, $orgEastTeam) {
    $draft = $quoteService->createDraft([
        'name' => '性能测试报价-' . $key,
        'customer_id' => $customerEastId,
        'product_line' => LINE,
        'currency' => 'CNY',
        'sales_org_id' => $orgEastTeam,
        'lines' => [['model_id' => $modelId, 'quantity' => 2, 'unit' => 'set']],
        'terms' => [['term_type' => 'payment', 'content' => '30% 预付，货到付清']],
    ], ADMIN_SUPER);
    $quoteService->submit((int)$draft['id'], ADMIN_SUPER, $key);
    return (int)$draft['id'];
};

/** createJob + process 同步生成 PDF，返回 [process 结果, 文件绝对路径] */
$generatePdf = function ($quoteId) use ($documentService, &$generatedFiles) {
    $job = $documentService->createJob($quoteId, 'zh', 0, ADMIN_SUPER);
    $processed = $documentService->process((int)$job['document']['id']);
    $file = ROOT_PATH . $processed['file_path'];
    $generatedFiles[] = $file;
    return [$processed, $file];
};

// warmup：完整跑一遍（含 mpdf 中文字体加载），不计时
list($warmProcessed, $warmFile) = $generatePdf($submitQuote('CPQ-PERF-PDF-WARM'));
check($warmProcessed['status'] === 'succeeded' && is_file($warmFile), 'PDF warmup 生成成功（不计时）');

$pdfStartedAt = microtime(true);
list($processed, $pdfFile) = $generatePdf($submitQuote('CPQ-PERF-PDF-1'));
$pdfMs = (microtime(true) - $pdfStartedAt) * 1000;
check($processed['status'] === 'succeeded', 'PDF 任务处理完成（succeeded）');
check(strlen((string)$processed['file_hash']) === 64, '文件记录 64 位 SHA-256');
check(is_file($pdfFile), 'PDF 文件存在：' . basename($pdfFile));
check($pdfMs < 30000, sprintf('PDF 生成实测 %.1f ms（目标 < 30000 ms）', $pdfMs));

// ---------------------------------------------------------------------
// 4. 报价列表查询 < 2s（1200 条报价 + sales 数据范围收窄后分页 20 + count）
// ---------------------------------------------------------------------
echo "\n== 4. 报价列表查询性能 ==\n";
// 可见 600：owner=销售本人 + 组织=华东一组 + 客户=华东区域
// 不可见 600：区域越权 200 / 组织越权 200 / 他人负责 200
$quoteRows = [];
$statuses = ['draft', 'submitted', 'approved', 'sent'];
for ($i = 1; $i <= 600; $i++) {
    $quoteRows[] = ['code' => sprintf('CPQ-PERF-Q-V-%04d', $i), 'customer_id' => $customerEastId,
        'sales_org_id' => $orgEastTeam, 'owner_id' => ADMIN_SALES];
}
for ($i = 1; $i <= 200; $i++) {
    $quoteRows[] = ['code' => sprintf('CPQ-PERF-Q-H-REGION-%04d', $i), 'customer_id' => $customerWestId,
        'sales_org_id' => $orgEastTeam, 'owner_id' => ADMIN_SALES];
    $quoteRows[] = ['code' => sprintf('CPQ-PERF-Q-H-ORG-%04d', $i), 'customer_id' => $customerEastId,
        'sales_org_id' => $orgWest, 'owner_id' => ADMIN_SALES];
    $quoteRows[] = ['code' => sprintf('CPQ-PERF-Q-H-OWNER-%04d', $i), 'customer_id' => $customerEastId,
        'sales_org_id' => $orgEastTeam, 'owner_id' => ADMIN_SUPER];
}
$insertRows = [];
foreach ($quoteRows as $index => $row) {
    $insertRows[] = array_merge([
        'name' => $row['code'], 'agent_id' => null, 'product_line' => LINE, 'currency' => 'CNY',
        'company' => '', 'market_scope' => 'domestic',
        'status' => $statuses[$index % 4], 'current_revision_no' => 1,
        'createtime' => $now, 'updatetime' => $now,
    ], $row);
}
foreach (array_chunk($insertRows, 200) as $chunk) {
    Db::name('cpq_quote')->insertAll($chunk);
}
$seededCount = (int)Db::name('cpq_quote')->where('code', 'like', 'CPQ-PERF-Q-%')->count();
check($seededCount === 1200, '报价种子 1200 行就绪（1000+，不含 §3 的两份 PDF 报价）');

$scope = QuoteDataScopeService::forAdmin(ADMIN_SALES);
check(!$scope->isUnrestricted() && $scope->isOwnerOnly(), '普通销售为 owner-only 受限范围');

$listQuery = function () use ($scope) {
    $base = function () {
        return Db::name('cpq_quote')->alias('q')
            ->join('__CPQ_CUSTOMER__ c', 'c.id = q.customer_id');
    };
    $pageQuery = $scope->applyToQuoteQuery($base(), 'q', 'c');
    $rows = $pageQuery->field('q.id,q.code,q.status,q.owner_id,q.sales_org_id,c.name AS customer_name,c.region_id')
        ->order('q.id desc')->limit(0, 20)->select();
    $countQuery = $scope->applyToQuoteQuery($base(), 'q', 'c');
    $total = (int)$countQuery->count();
    return ['rows' => $rows, 'total' => $total];
};

list($listMs, $listResult) = timed($listQuery, 1, 3); // warmup 1 次 + 正式 3 次取最大值
check(count($listResult['rows']) === 20, '分页返回 20 条');
check($listResult['total'] === 600, '数据范围收窄后总数 600（1200 中剔除区域/组织/负责人越权）');
$allVisibleIds = $scope->applyToQuoteQuery(
    Db::name('cpq_quote')->alias('q')->join('__CPQ_CUSTOMER__ c', 'c.id = q.customer_id'),
    'q', 'c'
)->column('q.id');
$hiddenCount = (int)Db::name('cpq_quote')->where('code', 'like', 'CPQ-PERF-Q-H-%')->count();
$hiddenIds = Db::name('cpq_quote')->where('code', 'like', 'CPQ-PERF-Q-H-%')->column('id');
check($hiddenCount === 600 && array_intersect(array_map('intval', $allVisibleIds), array_map('intval', $hiddenIds)) === [],
    '返回行不含未授权区域/组织/他人的报价');
check($listMs < 2000, sprintf('报价列表查询实测 %.1f ms（目标 < 2000 ms）', $listMs));

echo "\nM4 performance tests: PASS ({$assertCount} assertions)\n";
