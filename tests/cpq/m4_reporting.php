<?php
/**
 * GYTAI-78 M4 报表统一筛选、报价数据范围与敏感信息验收。
 *
 * 当前文件是 TDD RED 契约：只描述预期行为，不包含业务实现或测试替身。
 * 约定的统一入口：
 *  - QuoteDataScopeService::forAdmin($adminId)
 *  - new ReportFilterService($scope)
 *  - ReportFilterService::normalize($filters)
 *  - ReportFilterService::applyToQuoteQuery($query, $filters, $quoteAlias, $customerAlias)
 */

$projectRoot = dirname(__DIR__, 2);
if (!is_file($projectRoot . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php')) {
    $sharedRoot = dirname($projectRoot, 3);
    if (is_file($sharedRoot . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php')) {
        $projectRoot = $sharedRoot;
    }
}
define('M4_PROJECT_ROOT', $projectRoot);
define('APP_PATH', M4_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require M4_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\service\cpq\AsyncJobService;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\ExportJobService;
use app\common\service\cpq\IntegrationCredentialService;
use app\common\service\cpq\QuoteDataScopeService;
use app\common\service\cpq\ReportFilterService;
use app\common\service\cpq\ReportService;
use app\common\service\cpq\SensitiveFieldService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\Config;
use think\Db;

const M4_REPORTING_DB = 'cpq_m4_reporting_test';
const M4_REPORTING_PREFIX = 'fa_';
const M4_SALES_ID = 4101;
const M4_MANAGER_ID = 4201;
const M4_PEER_ID = 4102;
const M4_OTHER_PEER_ID = 4103;
const M4_NO_SCOPE_ID = 4999;
const M4_AUDITOR_ID = 4301;
const M4_FINANCE_ID = 4401;

$m4Assertions = 0;
$m4GeneratedFiles = [];

function checkM4Reporting($condition, $message)
{
    global $m4Assertions;
    $m4Assertions++;
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo "[ok] {$message}\n";
}

function throwsM4Reporting(callable $callback, $message)
{
    global $m4Assertions;
    $m4Assertions++;
    try {
        $callback();
    } catch (\Throwable $exception) {
        echo "[ok] {$message}（{$exception->getMessage()}）\n";
        return $exception;
    }
    throw new RuntimeException('[FAIL] ' . $message);
}

function hasRecursiveKeyM4(array $value, callable $matches)
{
    foreach ($value as $key => $item) {
        if ($matches((string)$key)) {
            return true;
        }
        if (is_array($item) && hasRecursiveKeyM4($item, $matches)) {
            return true;
        }
    }
    return false;
}

function assertAmountStringsM4(array $payload, $path = 'root')
{
    foreach ($payload as $key => $value) {
        $currentPath = $path . '.' . $key;
        if (is_array($value)) {
            assertAmountStringsM4($value, $currentPath);
            continue;
        }
        if (preg_match('/(?:amount|price|cost|floor|margin|discount|rate)$/i', (string)$key)) {
            checkM4Reporting(is_string($value), '金额字段必须为字符串：' . $currentPath);
        }
    }
}

function assertAuditSecretsSanitizedM4(array $payload, array $secretValues)
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($secretValues as $secret) {
        checkM4Reporting(strpos($encoded, $secret) === false, '审计明细不得包含敏感值 ' . $secret);
    }

    $walk = function (array $value) use (&$walk) {
        foreach ($value as $key => $item) {
            $isSensitive = preg_match(
                '/(?:password|authorization|api[_-]?key|client_secret|secret|token|credential_)/i',
                (string)$key
            ) === 1;
            if ($isSensitive && $item !== '[REDACTED]') {
                throw new RuntimeException('[FAIL] 审计敏感键未递归移除或净化：' . $key);
            }
            if (is_array($item)) {
                $walk($item);
            }
        }
    };
    $walk($payload);
}

function insertTreeNodeM4($table, $code, $name, $parentId = 0, array $extra = [])
{
    $parent = $parentId > 0 ? Db::name($table)->where('id', (int)$parentId)->find() : null;
    $level = $parent ? (int)$parent['level'] + 1 : 1;
    $data = array_merge([
        'code' => $code,
        'name' => $name,
        'parent_id' => (int)$parentId,
        'path' => '/',
        'level' => $level,
        'status' => 'normal',
        'createtime' => time(),
        'updatetime' => time(),
    ], $extra);
    $id = (int)Db::name($table)->insertGetId($data);
    $parentPath = $parent ? (string)$parent['path'] : '/';
    Db::name($table)->where('id', $id)->update(['path' => rtrim($parentPath, '/') . '/' . $id . '/']);
    return $id;
}

function seedReportingQuoteM4(array $fixture, $modelId)
{
    $now = strtotime('2026-09-05 10:00:00');
    $quoteId = (int)Db::name('cpq_quote')->insertGetId([
        'code' => $fixture['code'],
        'name' => $fixture['code'],
        'customer_id' => (int)$fixture['customer_id'],
        'sales_org_id' => (int)$fixture['sales_org_id'],
        'owner_id' => (int)$fixture['owner_id'],
        'product_line' => (string)$fixture['product_line'],
        'currency' => (string)($fixture['currency'] ?? 'CNY'),
        'company' => (string)($fixture['company'] ?? 'CPQ-M4-COMPANY'),
        'status' => 'approved',
        'current_revision_no' => 1,
        'approved_at' => $now,
        'createtime' => $now,
        'updatetime' => $now,
    ]);
    $lineId = (int)Db::name('cpq_quote_line')->insertGetId([
        'quote_id' => $quoteId,
        'line_no' => 1,
        'model_id' => (int)$modelId,
        'quantity' => '2.0000',
        'createtime' => $now,
        'updatetime' => $now,
    ]);
    $revisionId = (int)Db::name('cpq_quote_revision')->insertGetId([
        'quote_id' => $quoteId,
        'revision_no' => 1,
        'status' => 'approved',
        'created_by' => (int)$fixture['owner_id'],
        'createtime' => $now,
        'updatetime' => $now,
    ]);
    Db::name('cpq_quote_price_snapshot')->insert([
        'revision_id' => $revisionId,
        'quote_line_id' => $lineId,
        'model_id' => (int)$modelId,
        'model_code' => 'CPQ-M4-MODEL',
        'quantity' => '2.0000',
        'pricing_currency' => 'CNY',
        'quote_currency' => 'CNY',
        'base_amount' => '60.0000',
        'unit_subtotal' => '60.0000',
        'goods_amount' => '120.0000',
        'goods_discounted' => '110.0000',
        'untaxed_amount' => '110.0000',
        'tax_amount' => '14.3000',
        'total_amount' => '124.3000',
        'control_unit_price' => '55.0000',
        'classification' => 'normal',
        'approval_level' => 'none',
        'createtime' => $now,
    ]);
    return $quoteId;
}

function reportFilterForM4($adminId)
{
    return new ReportFilterService(QuoteDataScopeService::forAdmin((int)$adminId));
}

/**
 * 用三种真实基础查询形状模拟 P01 dashboard、P90 与 P94。
 * 三种入口必须复用同一 ReportFilterService，且得到相同报价 ID 集。
 */
function reportQuoteIdsM4(ReportFilterService $filterService, array $rawFilters, $surface)
{
    $filters = $filterService->normalize($rawFilters);
    if ($surface === 'dashboard') {
        $query = Db::name('cpq_quote')->alias('q')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT');
    } elseif ($surface === 'p90') {
        $query = Db::name('cpq_quote_revision')->alias('r')
            ->join('__CPQ_QUOTE__ q', 'q.id=r.quote_id')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT');
    } elseif ($surface === 'p94') {
        $query = Db::name('cpq_quote_price_snapshot')->alias('p')
            ->join('__CPQ_QUOTE_REVISION__ r', 'r.id=p.revision_id')
            ->join('__CPQ_QUOTE__ q', 'q.id=r.quote_id')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT');
    } else {
        throw new InvalidArgumentException('未知报表入口：' . $surface);
    }

    $query = $filterService->applyToQuoteQuery($query, $filters, 'q', 'c');
    checkM4Reporting(is_object($query), '统一筛选返回可继续构建的查询：' . $surface);
    $rows = $query->field('q.id AS quote_id')->group('q.id')->order('q.id asc')->select();
    return array_map('intval', array_column($rows, 'quote_id'));
}

function amountPayloadM4(ReportFilterService $filterService, array $rawFilters)
{
    $filters = $filterService->normalize($rawFilters);
    $query = Db::name('cpq_quote_price_snapshot')->alias('p')
        ->join('__CPQ_QUOTE_REVISION__ r', 'r.id=p.revision_id')
        ->join('__CPQ_QUOTE__ q', 'q.id=r.quote_id')
        ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT');
    $query = $filterService->applyToQuoteQuery($query, $filters, 'q', 'c');
    $rows = $query->field(
        'q.id AS quote_id,p.base_amount,p.unit_subtotal,p.goods_amount,p.untaxed_amount,'
        . 'p.tax_amount,p.total_amount,p.control_unit_price AS line_floor,p.manual_discount'
    )->order('q.id asc')->select();
    $total = $query->sum('p.total_amount');
    return ['rows' => $rows, 'totals' => ['total_amount' => $total]];
}

$requiredServices = [ReportFilterService::class, QuoteDataScopeService::class];
$missingServices = array_values(array_filter($requiredServices, function ($class) {
    return !class_exists($class);
}));
if ($missingServices) {
    throw new RuntimeException('[RED] 缺少待实现服务：' . implode('、', $missingServices));
}

$db = Config::get('database');
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $db['hostname'], $db['hostport'] ?: 3306);
$pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `' . M4_REPORTING_DB . '`');
$pdo->exec('CREATE DATABASE `' . M4_REPORTING_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `' . M4_REPORTING_DB . '`');
$installSql = file_get_contents(M4_PROJECT_ROOT . '/database/cpq/install.sql');
$pdo->exec(str_replace('__PREFIX__', M4_REPORTING_PREFIX, $installSql));
$pdo->exec('CREATE TABLE `fa_auth_group` (`id` int unsigned primary key, `name` varchar(100), `rules` text, `status` varchar(30)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE `fa_auth_group_access` (`uid` int unsigned, `group_id` int unsigned) ENGINE=InnoDB');
Config::set('database.database', M4_REPORTING_DB);

register_shutdown_function(function () use ($dsn, $db, &$m4GeneratedFiles) {
    foreach ($m4GeneratedFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    try {
        (new PDO($dsn, $db['username'], $db['password']))
            ->exec('DROP DATABASE IF EXISTS `' . M4_REPORTING_DB . '`');
    } catch (\Throwable $exception) {
        // RED 失败仍尽力清理测试数据库。
    }
});

$now = time();
Db::name('auth_group')->insertAll([
    ['id' => 1, 'name' => 'sales', 'rules' => 'cpq/report', 'status' => 'normal'],
    ['id' => 2, 'name' => 'sales_manager', 'rules' => 'cpq/report', 'status' => 'normal'],
    ['id' => 3, 'name' => 'auditor', 'rules' => 'cpq/report', 'status' => 'normal'],
    ['id' => 4, 'name' => 'finance_reviewer', 'rules' => 'cpq/report', 'status' => 'normal'],
]);
Db::name('auth_group_access')->insertAll([
    ['uid' => M4_SALES_ID, 'group_id' => 1],
    ['uid' => M4_PEER_ID, 'group_id' => 1],
    ['uid' => M4_OTHER_PEER_ID, 'group_id' => 1],
    ['uid' => M4_MANAGER_ID, 'group_id' => 2],
    ['uid' => M4_AUDITOR_ID, 'group_id' => 3],
    ['uid' => M4_FINANCE_ID, 'group_id' => 4],
]);
Db::name('cpq_admin_product_line')->insert([
    'admin_id' => M4_FINANCE_ID,
    'product_line' => 'CPQ-M4-LINE-A',
    'createtime' => $now,
    'updatetime' => $now,
]);
foreach ([M4_SALES_ID, M4_PEER_ID, M4_OTHER_PEER_ID, M4_MANAGER_ID] as $adminId) {
    Db::name('cpq_admin_product_line')->insert([
        'admin_id' => $adminId,
        'product_line' => 'CPQ-M4-LINE-A',
        'createtime' => $now,
        'updatetime' => $now,
    ]);
}

$orgHq = insertTreeNodeM4('cpq_sales_org', 'CPQ-M4-ORG-HQ', '总部');
$orgEast = insertTreeNodeM4('cpq_sales_org', 'CPQ-M4-ORG-EAST', '华东', $orgHq, ['manager_id' => M4_MANAGER_ID]);
$orgEastTeam = insertTreeNodeM4('cpq_sales_org', 'CPQ-M4-ORG-EAST-TEAM', '华东一组', $orgEast);
$orgWest = insertTreeNodeM4('cpq_sales_org', 'CPQ-M4-ORG-WEST', '华西', $orgHq);
Db::name('cpq_sales_org_member')->insertAll([
    ['org_id' => $orgEast, 'admin_id' => M4_MANAGER_ID, 'role' => 'sales_manager', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now],
    ['org_id' => $orgEastTeam, 'admin_id' => M4_SALES_ID, 'role' => 'sales', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now],
    ['org_id' => $orgEastTeam, 'admin_id' => M4_PEER_ID, 'role' => 'sales', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now],
]);

$regionEast = insertTreeNodeM4('cpq_region', 'CPQ-M4-REGION-EAST', '华东区域');
$regionWest = insertTreeNodeM4('cpq_region', 'CPQ-M4-REGION-WEST', '华西区域');
$customerEast = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-M4-CUSTOMER-EAST', 'name' => '华东客户', 'region_id' => $regionEast,
    'sales_org_id' => $orgEastTeam, 'owner_id' => M4_SALES_ID,
    'createtime' => $now, 'updatetime' => $now,
]);
$customerWest = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-M4-CUSTOMER-WEST', 'name' => '华西客户', 'region_id' => $regionWest,
    'sales_org_id' => $orgWest, 'owner_id' => M4_OTHER_PEER_ID,
    'createtime' => $now, 'updatetime' => $now,
]);
$seriesId = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-M4-SERIES', 'name' => 'M4 报表系列', 'product_line' => 'CPQ-M4-LINE-A',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
$modelId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-M4-MODEL', 'name' => 'M4 报表型号',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);

$quoteEast = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-EAST', 'customer_id' => $customerEast, 'sales_org_id' => $orgEast,
    'owner_id' => M4_PEER_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$quoteChild = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-CHILD', 'customer_id' => $customerEast, 'sales_org_id' => $orgEastTeam,
    'owner_id' => M4_PEER_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$quoteSales = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-SALES', 'customer_id' => $customerEast, 'sales_org_id' => $orgEastTeam,
    'owner_id' => M4_SALES_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$quoteWrongOwner = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-WRONG-OWNER', 'customer_id' => $customerEast, 'sales_org_id' => $orgEastTeam,
    'owner_id' => M4_OTHER_PEER_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$quoteWrongRegion = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-WRONG-REGION', 'customer_id' => $customerWest, 'sales_org_id' => $orgEastTeam,
    'owner_id' => M4_PEER_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$quoteWrongOrg = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-WRONG-ORG', 'customer_id' => $customerEast, 'sales_org_id' => $orgWest,
    'owner_id' => M4_PEER_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$quoteWrongLine = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-WRONG-LINE', 'customer_id' => $customerEast, 'sales_org_id' => $orgEastTeam,
    'owner_id' => M4_PEER_ID, 'product_line' => 'CPQ-M4-LINE-B',
], $modelId);

echo "\n== 统一筛选交集与角色数据范围 ==\n";
$managerFilter = reportFilterForM4(M4_MANAGER_ID);
$intersectionFilters = [
    'company' => 'CPQ-M4-COMPANY',
    'sales_org_id' => $orgEast,
    'product_line' => 'CPQ-M4-LINE-A',
    'region_id' => $regionEast,
    'owner_id' => M4_PEER_ID,
    'currency' => 'CNY',
    'created_from' => '2026-09-05',
    'created_to' => '2026-09-05',
];
$intersectionIds = reportQuoteIdsM4($managerFilter, $intersectionFilters, 'dashboard');
checkM4Reporting(
    $intersectionIds === [$quoteEast, $quoteChild],
    '产品线 ∩ 销售组织自身及子树 ∩ 客户区域 ∩ owner 使用 AND 交集'
);

$salesIds = reportQuoteIdsM4(reportFilterForM4(M4_SALES_ID), [], 'dashboard');
checkM4Reporting($salesIds === [$quoteSales], '普通销售默认严格 owner-only，不读取同组织他人报价');

$managerIds = reportQuoteIdsM4($managerFilter, [], 'dashboard');
checkM4Reporting(
    in_array($quoteEast, $managerIds, true)
        && in_array($quoteChild, $managerIds, true)
        && in_array($quoteWrongOwner, $managerIds, true)
        && !in_array($quoteWrongOrg, $managerIds, true)
        && !in_array($quoteWrongLine, $managerIds, true),
    '销售经理可见所属组织及下级的团队报价，不再附加 owner-only，仍受产品线限制'
);

$noScopeIds = reportQuoteIdsM4(reportFilterForM4(M4_NO_SCOPE_ID), [], 'dashboard');
checkM4Reporting($noScopeIds === [], '无角色、无产品线、无销售组织权限时 fail-closed');

$allQuoteIds = [$quoteEast, $quoteChild, $quoteSales, $quoteWrongOwner, $quoteWrongRegion, $quoteWrongOrg, $quoteWrongLine];
sort($allQuoteIds, SORT_NUMERIC);
$auditorScope = QuoteDataScopeService::forAdmin(M4_AUDITOR_ID);
checkM4Reporting($auditorScope->isUnrestricted(), '审计员按方案角色表为全公司只读范围（unrestricted）');
$auditorIds = reportQuoteIdsM4(reportFilterForM4(M4_AUDITOR_ID), [], 'dashboard');
checkM4Reporting($auditorIds === $allQuoteIds, '审计员无任何组织/产品线授权仍可见全部报价（全公司，只读）');

$financeScope = QuoteDataScopeService::forAdmin(M4_FINANCE_ID);
checkM4Reporting(!$financeScope->isUnrestricted() && !$financeScope->isOwnerOnly(), '财务审核非全公司范围且不附加 owner-only');
$lineAQuoteIds = array_values(array_diff($allQuoteIds, [$quoteWrongLine]));
$financeIds = reportQuoteIdsM4(reportFilterForM4(M4_FINANCE_ID), [], 'dashboard');
checkM4Reporting($financeIds === $lineAQuoteIds, '财务审核仅按授权产品线收窄，不叠加组织/区域/负责人维度');
$financeOwnerIds = reportQuoteIdsM4(reportFilterForM4(M4_FINANCE_ID), ['owner_id' => M4_PEER_ID], 'dashboard');
checkM4Reporting(
    in_array($quoteEast, $financeOwnerIds, true) && !in_array($quoteSales, $financeOwnerIds, true),
    '仅产品线收窄的角色可按 owner/组织/区域做合法分析筛选'
);

throwsM4Reporting(function () use ($managerFilter) {
    reportQuoteIdsM4($managerFilter, ['product_line' => 'CPQ-M4-LINE-B'], 'dashboard');
}, '显式筛选未授权产品线必须抛异常而不是返回空集');
throwsM4Reporting(function () use ($managerFilter, $orgWest) {
    reportQuoteIdsM4($managerFilter, ['sales_org_id' => $orgWest], 'dashboard');
}, '显式筛选授权组织子树外组织必须抛异常');
throwsM4Reporting(function () {
    reportQuoteIdsM4(reportFilterForM4(M4_SALES_ID), ['owner_id' => M4_PEER_ID], 'dashboard');
}, '普通销售显式筛选其他 owner 必须抛异常');
throwsM4Reporting(function () {
    reportQuoteIdsM4(reportFilterForM4(M4_NO_SCOPE_ID), ['product_line' => 'CPQ-M4-LINE-A'], 'dashboard');
}, '无权限账号显式请求数据范围必须抛异常');
throwsM4Reporting(function () {
    reportQuoteIdsM4(reportFilterForM4(M4_FINANCE_ID), ['product_line' => 'CPQ-M4-LINE-B'], 'dashboard');
}, '财务审核显式筛选未授权产品线必须抛异常');
throwsM4Reporting(function () use ($managerFilter) {
    $managerFilter->normalize(['product_line' => 'CPQ-M4-LINE-B']);
}, '越权筛选在 normalize 规范化阶段即抛异常（不延迟到查询组装）');

echo "\n== dashboard / P90 / P94 统一集合与金额类型 ==\n";
$dashboardIds = reportQuoteIdsM4($managerFilter, $intersectionFilters, 'dashboard');
$p90Ids = reportQuoteIdsM4($managerFilter, $intersectionFilters, 'p90');
$p94Ids = reportQuoteIdsM4($managerFilter, $intersectionFilters, 'p94');
checkM4Reporting(
    $dashboardIds === $p90Ids && $p90Ids === $p94Ids,
    'dashboard、P90、P94 在完全相同规范化过滤下报价 ID 集一致'
);
assertAmountStringsM4(amountPayloadM4($managerFilter, $intersectionFilters));

echo "\n== P91 毛利率分母仅统计有成本行 ==\n";
$marginQuoteWithCost = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-MARGIN-COST', 'customer_id' => $customerEast, 'sales_org_id' => $orgEastTeam,
    'owner_id' => M4_PEER_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$marginQuoteNoCost = seedReportingQuoteM4([
    'code' => 'CPQ-M4-Q-MARGIN-NOCOST', 'customer_id' => $customerEast, 'sales_org_id' => $orgEastTeam,
    'owner_id' => M4_PEER_ID, 'product_line' => 'CPQ-M4-LINE-A',
], $modelId);
$marginRevisionCost = (int)Db::name('cpq_quote_revision')->where('quote_id', $marginQuoteWithCost)->value('id');
$marginRevisionNoCost = (int)Db::name('cpq_quote_revision')->where('quote_id', $marginQuoteNoCost)->value('id');
Db::name('cpq_quote_price_snapshot')->where('revision_id', $marginRevisionCost)->update([
    'model_code' => 'CPQ-M4-MARGIN',
    'policy_snapshot_json' => json_encode(['cost' => '40.0000'], JSON_UNESCAPED_UNICODE),
]);
Db::name('cpq_quote_price_snapshot')->where('revision_id', $marginRevisionNoCost)->update(['model_code' => 'CPQ-M4-MARGIN']);
$marginFilters = reportFilterForM4(M4_AUDITOR_ID)->normalize(['product_line' => 'CPQ-M4-LINE-A']);
$marginRows = (new ReportService(M4_AUDITOR_ID))->discountMargin($marginFilters, ['auditor'])['rows'];
$marginRow = null;
foreach ($marginRows as $marginItem) {
    if ($marginItem['model_code'] === 'CPQ-M4-MARGIN') {
        $marginRow = $marginItem;
    }
}
// 有成本行：110 - 40*2 = 30；无成本行不进入分母 → 30/110 = 0.2727（旧口径 30/220 = 0.1364 属稀释错误）
checkM4Reporting($marginRow !== null, 'P91 返回毛利测试分组行');
checkM4Reporting($marginRow['gross_margin_amount'] === '30.0000', 'P91 毛利额仅累加有成本行');
checkM4Reporting($marginRow['gross_margin_rate'] === '0.2727', 'P91 毛利率分母只计有成本行的未税金额');

echo "\n== 递归字段脱敏与 P94 表头 ==\n";
$sensitiveRows = [[
    'quote_id' => $quoteSales,
    'total_amount' => '124.3000',
    'pricing' => [
        'line_floor' => '55.0000',
        'company_floor' => '45.0000',
        'cost' => '40.0000',
        'breakdown' => [
            ['cost' => '20.0000', 'company_floor' => '22.0000', 'tax_amount' => '2.6000'],
        ],
    ],
]];
$maskedRows = (new SensitiveFieldService())->maskRows($sensitiveRows, ['sales']);
checkM4Reporting(
    !hasRecursiveKeyM4($maskedRows, function ($key) {
        return in_array($key, ['cost', 'company_floor'], true);
    }),
    '无权角色响应中的嵌套 cost/company_floor 必须递归删除'
);
checkM4Reporting(
    isset($maskedRows[0]['pricing']['line_floor']),
    '递归脱敏保留销售可见的产线控制价'
);
assertAmountStringsM4($maskedRows);

$jobs = new AsyncJobService();
$exportService = new ExportJobService($jobs);
$export = $exportService->createQuoteExport([
    'product_line' => 'CPQ-M4-LINE-A',
    'sales_org_id' => $orgEastTeam,
    'region_id' => $regionEast,
    'owner_id' => M4_SALES_ID,
    'created_from' => '2026-09-05',
    'created_to' => '2026-09-05',
    'idempotency_key' => 'cpq-m4-sales-export',
], M4_SALES_ID, false);
$rawExport = $jobs->claim($export['job_key']);
$exportService->process($rawExport);
$storedExport = $jobs->raw($export['job_key']);
$m4GeneratedFiles[] = $storedExport['file_path'];
$workbook = IOFactory::load($storedExport['file_path']);
$headers = $workbook->getActiveSheet()->rangeToArray('A1:Z1', null, true, false)[0];
$workbook->disconnectWorksheets();
$headers = array_values(array_filter($headers, function ($header) {
    return $header !== null && $header !== '';
}));
checkM4Reporting(
    !array_intersect($headers, ['成本', '公司控制价', '毛利额', '毛利率']),
    'P94 无敏感字段权限角色的 XLSX 必须从表结构中移除敏感表头'
);

$tokenJob = $jobs->create('email', 'report', 'm4', [], M4_SALES_ID, 0, 'cpq-m4-public-token');
$jobs->claim($tokenJob['job_key']);
$jobs->succeed($tokenJob['job_key'], [
    'download_token' => 'm4-public-secret',
    'nested' => ['download_token' => 'm4-nested-secret'],
]);
$publicJob = $jobs->status($tokenJob['job_key'], M4_SALES_ID);
checkM4Reporting(
    !hasRecursiveKeyM4($publicJob, function ($key) {
        return $key === 'download_token' || $key === 'download_token_hash';
    }),
    '公开任务状态不得在顶层或 result 嵌套结构泄露 download_token'
);

echo "\n== 集成凭证与审计日志递归净化 ==\n";
$credentialService = new IntegrationCredentialService('cpq-m4-unit-test-key');
$publicConfig = $credentialService->publicConfig([
    'id' => 1,
    'code' => 'CPQ-M4-ERP',
    'credential_ciphertext' => 'ciphertext-secret',
    'credential_nonce' => 'nonce-secret',
    'credential_tag' => 'tag-secret',
    'credential_key_version' => 'v1',
    'credential_rotated_at' => 123456,
]);
checkM4Reporting(
    !hasRecursiveKeyM4($publicConfig, function ($key) {
        return strpos($key, 'credential_') === 0;
    }),
    'IntegrationCredentialService::publicConfig 不得返回任何 credential_* 字段'
);
checkM4Reporting(
    $publicConfig['credentials_configured'] === true,
    '公开集成配置仅返回 credentials_configured 布尔状态'
);

$auditSecrets = [
    'plain-password', 'bearer-token', 'nested-api-key', 'download-secret', 'cipher-secret',
];
$auditId = (new AuditLogService())->record('report_filter', 'cpq_quote', $quoteSales, [
    'safe' => 'kept',
    'password' => 'plain-password',
    'request' => [
        'authorization' => 'Bearer bearer-token',
        'headers' => ['api_key' => 'nested-api-key'],
        'result' => [
            'download_token' => 'download-secret',
            'credential_ciphertext' => 'cipher-secret',
            'safe_nested' => 'also-kept',
        ],
    ],
]);
$auditRow = Db::name('cpq_audit_log')->where('id', $auditId)->find();
$auditDetail = json_decode((string)$auditRow['detail_json'], true);
checkM4Reporting(is_array($auditDetail), '审计明细保存为有效 JSON');
checkM4Reporting(
    ($auditDetail['safe'] ?? null) === 'kept'
        && ($auditDetail['request']['result']['safe_nested'] ?? null) === 'also-kept',
    '审计递归净化不得删除非敏感业务字段'
);
assertAuditSecretsSanitizedM4($auditDetail, $auditSecrets);

echo "\nM4 reporting/data-scope tests: PASS ({$m4Assertions} assertions)\n";
