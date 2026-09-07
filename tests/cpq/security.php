<?php
/**
 * M4 收口：安全与权限自动化测试（GYTAI-76）。
 *
 * 覆盖：
 *  A. 注入与输入安全
 *   - SQL 注入探测：恶意编号/关键字走参数绑定查询与列表 like 查询，不报错、不越界、表仍在；
 *   - XSS：载荷经 QuoteRevisionService 落库与读回结构完整（存储原文），后台模板/前端/PDF 渲染侧转义静态断言；
 *   - CSRF：Token 配置、Backend::token() 校验与登录表单 __token__ 校验静态断言（FastAdmin 无全局 CSRF 中间件，残余风险见输出注明）；
 *  B. 越权
 *   - 敏感字段按角色脱敏（maskRows）与 rolesOfAdmin fail-closed；
 *   - QuoteDataScopeService::assertQuoteAccess 单条兜底：区域/组织/负责人维度越权拒绝、
 *     销售经理子树放行、无角色 fail-closed、全公司角色与产线角色放行；
 *   - PDF 下载权限：QuoteDocumentService::createJob/prepareDownload 叠加数据范围校验，
 *     跨区销售被拒绝，超管全流程可生成/下载（篡改 hash_mismatch 场景已由 m3_approval.php 覆盖）；
 *   - 审批越权（候选人范围/职责分离/越权 act）已由 m3_approval.php 覆盖，此处不重复断言；
 *  C. 敏感信息与凭证
 *   - 审计 detail 递归脱敏（[REDACTED]）与 SENSITIVE_KEY_PATTERN 覆盖；
 *   - 集成凭证 AES-256-GCM 保险箱：密文落库、publicConfig 剥离、解密往返、错误密钥/损坏密文拒绝；
 *   - HMAC 签名：正确通过、时间戳偏差/非数字拒绝、sha512 可用、非法算法拒绝、往返一致；
 *   - 导出下载令牌：sha256 哈希落库、仅本人/特权角色领取、领取烧毁、hash_equals 校验、下载后烧毁；
 *   - 日志不泄密：CPQ 业务代码无 credential/password/secret/integration_key 落 Log::/trace() 静态断言；
 *  D. 文件上传面
 *   - 报价附件无写入入口（仅模型关联）静态断言；主数据导入 xlsx/xls/csv 扩展名白名单静态断言；
 *   - nginx uploads/assets 禁 PHP 与隐藏文件拒绝静态断言；
 *  E. API 鉴权基座
 *   - api/cpq 六个控制器均未覆写 noNeedLogin 且继承 app\common\controller\Api（基类默认登录校验）。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/security.php
 *
 * 使用独立临时库 cpq_m4_security_test（install.sql + 最小 fa_admin/fa_auth_group/fa_auth_group_access），
 * 结束时自动 DROP；所有种子数据编码带 CPQ-SEC- 前缀。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\service\cpq\AsyncJobService;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\ExportJobService;
use app\common\service\cpq\IntegrationAuthService;
use app\common\service\cpq\IntegrationCredentialService;
use app\common\service\cpq\QuoteDataScopeService;
use app\common\service\cpq\QuoteDocumentService;
use app\common\service\cpq\QuoteRevisionService;
use app\common\service\cpq\QuoteTemplateService;
use app\common\service\cpq\SensitiveFieldService;
use think\Config;
use think\Db;
use think\Env;

const TEST_DB = 'cpq_m4_security_test';
const PREFIX = 'fa_';
const LINE = 'CPQ-SEC-LINE';

// 角色账号
const ADMIN_SUPER = 1;    // 超管（rules='*' → system_admin）
const ADMIN_SALES_EAST = 10;   // 华东销售（ownerOnly）
const ADMIN_SALES_EAST2 = 11;  // 华东销售（同组织他人）
const ADMIN_MANAGER_EAST = 12; // 华东销售经理
const ADMIN_NOROLE = 13;       // 无任何 CPQ 角色
const ADMIN_COMPANY = 14;      // 公司价格管理员
const ADMIN_AUDITOR = 15;      // 审计员
const ADMIN_LINE = 16;         // 产线价格管理员
const ADMIN_SALES_SOUTH = 17;  // 华南销售（华南报价负责人）

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

function expectRuntime(callable $fn, $message)
{
    global $assertCount;
    $assertCount++;
    try {
        $fn();
    } catch (\RuntimeException $exception) {
        echo "[ok] {$message}（{$exception->getMessage()}）\n";
        return $exception;
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出 RuntimeException 但未抛出');
}

/** 断言 callable 不抛异常。 */
function noThrow(callable $fn)
{
    try {
        $fn();
        return true;
    } catch (\Throwable $exception) {
        echo "    [unexpected] " . get_class($exception) . ': ' . $exception->getMessage() . "\n";
        return false;
    }
}

/** 静态断言：文件存在且包含全部目标片段。 */
function checkFileContains($path, array $needles, $message)
{
    if (!is_file($path)) {
        check(false, $message . '（文件不存在：' . $path . '）');
        return;
    }
    $content = file_get_contents($path);
    foreach ($needles as $needle) {
        if (strpos($content, $needle) === false) {
            check(false, $message . '（缺少片段：' . $needle . '）');
            return;
        }
    }
    check(true, $message);
}

/** 收集目录下匹配正则的 PHP 行（用于"零出现"静态断言）。 */
function grepPhpLines(array $dirs, $linePattern, callable $fileFilter = null)
{
    $hits = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (substr($path, -4) !== '.php') {
                continue;
            }
            if ($fileFilter && !$fileFilter($path)) {
                continue;
            }
            foreach (file($file->getPathname()) as $lineNo => $line) {
                if (preg_match($linePattern, $line)) {
                    $hits[] = $path . ':' . ($lineNo + 1);
                }
            }
        }
    }
    return $hits;
}

// ---------------------------------------------------------------------
// 临时库（install.sql + 最小后台账号表）
// ---------------------------------------------------------------------
$dbConfig = Config::get('database');
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbConfig['hostname'], $dbConfig['hostport'] ?: 3306);
$pdo = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$pdo->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `' . TEST_DB . '`');
$pdo->exec(str_replace('__PREFIX__', PREFIX, file_get_contents(dirname(__DIR__, 2) . '/database/cpq/install.sql')));
// 角色解析依赖的最小后台账号/权限表（仅含被引用的列）
$pdo->exec("CREATE TABLE `fa_admin` (`id` INT UNSIGNED NOT NULL PRIMARY KEY, `username` VARCHAR(50) NOT NULL DEFAULT '', `nickname` VARCHAR(50) NOT NULL DEFAULT '', `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL DEFAULT '', `rules` TEXT NULL, `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group_access` (`uid` INT UNSIGNED NOT NULL, `group_id` INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
// 种子：账号/角色/产品线授权
// ---------------------------------------------------------------------
$pdo->exec("INSERT INTO `fa_admin` (`id`,`username`,`nickname`) VALUES
    (1,'cpq_sec_super','安全测试超管'),(10,'cpq_sec_sales_e','华东销售'),(11,'cpq_sec_sales_e2','华东销售乙'),
    (12,'cpq_sec_mgr_e','华东销售经理'),(13,'cpq_sec_norole','无角色'),(14,'cpq_sec_company','公司价格管理员'),
    (15,'cpq_sec_auditor','审计员'),(16,'cpq_sec_line','产线价格管理员'),(17,'cpq_sec_sales_s','华南销售')");
$groupSuper = (int)Db::name('auth_group')->insertGetId(['name' => 'administrators', 'rules' => '*']);
$groupSales = (int)Db::name('auth_group')->insertGetId(['name' => 'sales', 'rules' => '1']);
$groupManager = (int)Db::name('auth_group')->insertGetId(['name' => 'sales_manager', 'rules' => '1']);
$groupGuest = (int)Db::name('auth_group')->insertGetId(['name' => 'guest', 'rules' => '1']);
$groupCompany = (int)Db::name('auth_group')->insertGetId(['name' => 'company_pricer', 'rules' => '1']);
$groupAuditor = (int)Db::name('auth_group')->insertGetId(['name' => 'auditor', 'rules' => '1']);
$groupLine = (int)Db::name('auth_group')->insertGetId(['name' => 'line_pricer', 'rules' => '1']);
Db::name('auth_group_access')->insertAll([
    ['uid' => ADMIN_SUPER, 'group_id' => $groupSuper],
    ['uid' => ADMIN_SALES_EAST, 'group_id' => $groupSales],
    ['uid' => ADMIN_SALES_EAST2, 'group_id' => $groupSales],
    ['uid' => ADMIN_MANAGER_EAST, 'group_id' => $groupManager],
    ['uid' => ADMIN_NOROLE, 'group_id' => $groupGuest],
    ['uid' => ADMIN_COMPANY, 'group_id' => $groupCompany],
    ['uid' => ADMIN_AUDITOR, 'group_id' => $groupAuditor],
    ['uid' => ADMIN_LINE, 'group_id' => $groupLine],
    ['uid' => ADMIN_SALES_SOUTH, 'group_id' => $groupSales],
]);
foreach ([ADMIN_SALES_EAST, ADMIN_SALES_EAST2, ADMIN_MANAGER_EAST, ADMIN_LINE, ADMIN_SALES_SOUTH] as $adminId) {
    Db::name('cpq_admin_product_line')->insert([
        'admin_id' => $adminId, 'product_line' => LINE, 'createtime' => $now, 'updatetime' => $now,
    ]);
}

// ---------------------------------------------------------------------
// 种子：销售组织（华东/华东下级/华南）、区域、客户
// ---------------------------------------------------------------------
$orgEast = (int)Db::name('cpq_sales_org')->insertGetId([
    'code' => 'CPQ-SEC-ORG-EAST', 'name' => '华东销售组织', 'parent_id' => 0, 'path' => '/',
    'level' => 1, 'manager_id' => ADMIN_MANAGER_EAST, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_sales_org')->where('id', $orgEast)->update(['path' => '/' . $orgEast . '/']);
$orgEastChild = (int)Db::name('cpq_sales_org')->insertGetId([
    'code' => 'CPQ-SEC-ORG-EAST-CHILD', 'name' => '华东下级组织', 'parent_id' => $orgEast, 'path' => '/',
    'level' => 2, 'manager_id' => 0, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_sales_org')->where('id', $orgEastChild)->update(['path' => '/' . $orgEast . '/' . $orgEastChild . '/']);
$orgSouth = (int)Db::name('cpq_sales_org')->insertGetId([
    'code' => 'CPQ-SEC-ORG-SOUTH', 'name' => '华南销售组织', 'parent_id' => 0, 'path' => '/',
    'level' => 1, 'manager_id' => 0, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_sales_org')->where('id', $orgSouth)->update(['path' => '/' . $orgSouth . '/']);

Db::name('cpq_sales_org_member')->insertAll([
    ['org_id' => $orgEast, 'admin_id' => ADMIN_SALES_EAST, 'role' => 'sales', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now],
    ['org_id' => $orgEast, 'admin_id' => ADMIN_SALES_EAST2, 'role' => 'sales', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now],
    ['org_id' => $orgEast, 'admin_id' => ADMIN_MANAGER_EAST, 'role' => 'sales_manager', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now],
    ['org_id' => $orgSouth, 'admin_id' => ADMIN_SALES_SOUTH, 'role' => 'sales', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now],
]);

$regionEast = (int)Db::name('cpq_region')->insertGetId([
    'code' => 'CPQ-SEC-REGION-EAST', 'name' => '华东区域', 'parent_id' => 0, 'path' => '/', 'level' => 1,
    'default_currency' => 'CNY', 'sales_org_id' => $orgEast, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_region')->where('id', $regionEast)->update(['path' => '/' . $regionEast . '/']);
$regionSouth = (int)Db::name('cpq_region')->insertGetId([
    'code' => 'CPQ-SEC-REGION-SOUTH', 'name' => '华南区域', 'parent_id' => 0, 'path' => '/', 'level' => 1,
    'default_currency' => 'CNY', 'sales_org_id' => $orgSouth, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_region')->where('id', $regionSouth)->update(['path' => '/' . $regionSouth . '/']);

$insertCustomer = function ($code, $name, $regionId, $orgId, $ownerId) use ($now) {
    return (int)Db::name('cpq_customer')->insertGetId([
        'code' => $code, 'name' => $name, 'type' => 'direct', 'country_code' => 'CN',
        'region_id' => $regionId, 'customer_level_id' => null, 'default_currency' => 'CNY',
        'sales_org_id' => $orgId, 'owner_id' => $ownerId, 'status' => 'normal',
        'createtime' => $now, 'updatetime' => $now,
    ]);
};
$customerEast = $insertCustomer('CPQ-SEC-CUST-EAST', '华东客户', $regionEast, $orgEast, ADMIN_SALES_EAST);
$customerEastChild = $insertCustomer('CPQ-SEC-CUST-EAST-C', '华东下级客户', $regionEast, $orgEastChild, ADMIN_SALES_EAST);
$customerSouth = $insertCustomer('CPQ-SEC-CUST-SOUTH', '华南客户', $regionSouth, $orgSouth, ADMIN_SALES_SOUTH);

// ---------------------------------------------------------------------
// 种子：产品系列/型号 + 报价（华东本人/华东他人/华东下级/华南已批准）
// ---------------------------------------------------------------------
$seriesId = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-SEC-SERIES', 'name' => '安全测试系列', 'business_unit' => 'CPQ-SEC-BU',
    'product_line' => LINE, 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
$modelId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-SEC-MODEL-A', 'name' => '安全测试型号A', 'category_code' => 'CPQ-SEC-CAT',
    'base_item_code' => 'BASE-CPQ-SEC-MODEL-A', 'unit' => 'set', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);

$insertQuote = function ($code, $name, $customerId, $orgId, $ownerId, $status, $revisionNo) use ($now) {
    return (int)Db::name('cpq_quote')->insertGetId([
        'code' => $code, 'name' => $name, 'customer_id' => $customerId, 'sales_org_id' => $orgId,
        'owner_id' => $ownerId, 'product_line' => LINE, 'currency' => 'CNY', 'status' => $status,
        'current_revision_no' => $revisionNo, 'optimistic_lock_version' => 1,
        'submitted_at' => $revisionNo > 0 ? $now : null,
        'approved_at' => $status === 'approved' ? $now : null,
        'createtime' => $now, 'updatetime' => $now,
    ]);
};
$quoteEast = $insertQuote('CPQ-SEC-Q-EAST', '华东本人报价', $customerEast, $orgEast, ADMIN_SALES_EAST, 'draft', 0);
$quoteEastOther = $insertQuote('CPQ-SEC-Q-EAST-OTHER', '华东他人报价', $customerEast, $orgEast, ADMIN_SALES_EAST2, 'draft', 0);
$quoteEastChild = $insertQuote('CPQ-SEC-Q-EAST-CHILD', '华东下级组织报价', $customerEastChild, $orgEastChild, ADMIN_SALES_EAST, 'draft', 0);
$quoteSouth = $insertQuote('CPQ-SEC-Q-SOUTH', '华南报价', $customerSouth, $orgSouth, ADMIN_SALES_SOUTH, 'approved', 1);

// 华南报价的冻结版本/行/价格快照/条款（供 PDF 生成与明细导出）
$lineSouth = (int)Db::name('cpq_quote_line')->insertGetId([
    'quote_id' => $quoteSouth, 'line_no' => 1, 'model_id' => $modelId, 'quantity' => '1.0000', 'unit' => 'set',
    'createtime' => $now, 'updatetime' => $now,
]);
$revisionSouth = (int)Db::name('cpq_quote_revision')->insertGetId([
    'quote_id' => $quoteSouth, 'revision_no' => 1, 'status' => 'approved',
    'pricing_result_json' => '{"totals":{"untaxed":"100.0000","tax":"13.0000","total":"113.0000"}}',
    'price_hash' => hash('sha256', 'CPQ-SEC-PRICE'), 'snapshot_hash' => hash('sha256', 'CPQ-SEC-SNAPSHOT'),
    'created_by' => ADMIN_SALES_SOUTH, 'frozen_at' => $now, 'submitted_at' => $now,
    'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_quote_price_snapshot')->insert([
    'revision_id' => $revisionSouth, 'quote_line_id' => $lineSouth, 'model_id' => $modelId,
    'model_code' => 'CPQ-SEC-MODEL-A', 'quantity' => '1.0000', 'pricing_currency' => 'CNY', 'quote_currency' => 'CNY',
    'unit_subtotal' => '100.0000', 'untaxed_amount' => '100.0000', 'tax_amount' => '13.0000',
    'total_amount' => '113.0000', 'control_unit_price' => '100.0000',
    'policy_snapshot_json' => '{"guide_price":"120.0000","line_floor":"100.0000","company_floor":"90.0000","cost":"60.0000"}',
    'createtime' => $now,
]);
Db::name('cpq_quote_term')->insert([
    'quote_id' => $quoteSouth, 'revision_id' => $revisionSouth, 'term_type' => 'payment',
    'content' => '30% 预付，货到付清', 'sort' => 1, 'createtime' => $now, 'updatetime' => $now,
]);

$templateService = new QuoteTemplateService();
$templateId = (int)Db::name('cpq_quote_template')->insertGetId([
    'code' => 'CPQ-SEC-QT-ZH', 'name' => '安全测试模板', 'name_en' => 'Security Test Template',
    'language' => 'zh', 'market_scope' => 'all', 'paper_size' => 'A4', 'is_default' => 1,
    'content_json' => json_encode($templateService->defaultContent('zh'), JSON_UNESCAPED_UNICODE),
    'allowed_variables' => json_encode(array_keys(QuoteTemplateService::VARIABLE_WHITELIST)),
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);

$quoteService = new QuoteRevisionService();
$documentService = new QuoteDocumentService();
$maskService = new SensitiveFieldService();

// =====================================================================
// A. 注入与输入安全
// =====================================================================
echo "\n== A1. SQL 注入探测 ==\n";
$evilStrings = ["' OR '1'='1", '1; DROP TABLE fa_cpq_quote;--', '%_" UNION SELECT id,code FROM fa_admin--'];
$quoteCountBefore = Db::name('cpq_quote')->count();
foreach ($evilStrings as $evil) {
    $exact = Db::name('cpq_quote')->where('code', $evil)->select();
    check(is_array($exact) && count($exact) === 0, '恶意编号精确查询参数绑定安全：' . substr($evil, 0, 24));
    // 参照 application/api/controller/cpq/Quote.php:64 的列表 like 写法
    $like = Db::name('cpq_quote')->alias('q')
        ->join('__CPQ_CUSTOMER__ c', 'c.id = q.customer_id', 'LEFT')
        ->where('q.name|q.code', 'like', '%' . $evil . '%')
        ->select();
    check(is_array($like) && count($like) === 0, '恶意关键字 like 查询不返回越界数据：' . substr($evil, 0, 24));
}
check(Db::name('cpq_quote')->count() === $quoteCountBefore, '注入探测后 fa_cpq_quote 表数据完好');

echo "\n== A2. XSS 载荷入库与回显 ==\n";
$xssReason = '<img src=x onerror=alert(1)>';
$xssTerm = '<script>alert(1)</script>';
$xssTerm2 = '<svg onload=alert(2)>';
$draft = $quoteService->createDraft([
    'name' => 'CPQ-SEC-XSS 报价',
    'customer_id' => $customerEast,
    'product_line' => LINE,
    'currency' => 'CNY',
    'sales_org_id' => $orgEast,
    'owner_id' => ADMIN_SALES_EAST,
    'lines' => [['model_id' => $modelId, 'quantity' => 1, 'unit' => 'set', 'discount_reason' => $xssReason]],
    'terms' => [['term_type' => 'other', 'content' => $xssTerm]],
], ADMIN_SALES_EAST);
$quoteXss = (int)$draft['id'];
check($draft['lines'][0]['discount_reason'] === $xssReason, '行备注载荷经 createDraft 落库并原样读回（存储不破坏结构）');
check($draft['terms'][0]['content'] === $xssTerm, '条款载荷经 createDraft 落库并原样读回');
check(Db::name('cpq_quote_term')->where('quote_id', $quoteXss)->value('content') === $xssTerm, '数据库原文与载荷一致（存储层不执行）');
$updated = $quoteService->updateDraft($quoteXss, (int)$draft['optimistic_lock_version'], [
    'terms' => [['term_type' => 'other', 'content' => $xssTerm2]],
], ADMIN_SALES_EAST);
check($updated['terms'][0]['content'] === $xssTerm2 && is_array($updated['lines']) && count($updated['lines']) === 1,
    'updateDraft 更新条款载荷后读取侧结构完整');

// 渲染侧转义：控制器 JSON_HEX 编码、前端 escapeHtml、模板显式 |htmlentities、PDF 条款 htmlspecialchars
checkFileContains(ROOT_PATH .  'application/admin/controller/cpq/Quote.php',
    ['JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT'],
    '后台报价详情 quoteJson 以 JSON_HEX_* 编码注入模板（防 </script> 逃逸）');
checkFileContains(ROOT_PATH .  'public/assets/js/backend/cpq/quote.js',
    ['function escapeHtml', "$('<span>').text("],
    '前端报价页渲染统一走 escapeHtml');
checkFileContains(ROOT_PATH .  'application/common/service/cpq/QuoteDocumentService.php',
    ["htmlspecialchars((string)\$term['content']"],
    'PDF 渲染对条款内容做 htmlspecialchars');
$rawTemplateHits = [];
$viewIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH .  'application/admin/view/cpq', FilesystemIterator::SKIP_DOTS));
foreach ($viewIterator as $viewFile) {
    if (substr($viewFile->getPathname(), -5) !== '.html') {
        continue;
    }
    if (preg_match_all('/\{\$([A-Za-z0-9_.]+)\}/', file_get_contents($viewFile->getPathname()), $m)) {
        foreach ($m[1] as $hit) {
            $rawTemplateHits[] = str_replace('\\', '/', $viewFile->getPathname()) . ' {$' . $hit . '}';
        }
    }
}
$sensitiveRawHits = array_values(array_filter($rawTemplateHits, function ($hit) {
    return preg_match('/name|remark|content|description|customer|reason|term/i', $hit) === 1;
}));
check($sensitiveRawHits === [],
    '后台 CPQ 模板不存在备注/条款/客户名等敏感字段的未转义 {$var} 原始输出（均带 |htmlentities 或经 JS 转义）');
echo "[note] 存在 " . count($rawTemplateHits) . " 处枚举/主数据派生变量（如 \$company/\$line/\$nodeText）的未加管道 "
    . "{\$var} 输出；default_filter 为空时不转义，主数据若含 HTML 有存储型 XSS 残余风险（见报告）。\n";

echo "\n== A3. CSRF 防护面（静态）==\n";
checkFileContains(ROOT_PATH .  'application/config.php', ["'token'"], 'Token 配置段存在（Mysql 驱动会话令牌）');
checkFileContains(ROOT_PATH .  'application/common/controller/Backend.php',
    ['__token__', "Validate::make()->check(['__token__' => \$token], ['__token__' => 'require|token'])"],
    'Backend 基类提供 __token__ 表单令牌校验方法');
checkFileContains(ROOT_PATH .  'application/admin/controller/Index.php', ['__token__', "'require|token'"], '后台登录表单强制 __token__ 校验');
echo "[note] FastAdmin 该版本无全局 CSRF 中间件：CPQ 后台写操作未显式调用 \$this->token()，"
    . "依赖登录态 + 随机后台入口 + 会话 cookie，属残余风险（见报告）。\n";

// =====================================================================
// B. 越权
// =====================================================================
echo "\n== B4. 敏感字段脱敏 ==\n";
$sensitiveRows = [[
    'id' => 1, 'code' => 'CPQ-SEC-P1', 'guide_price' => '120.0000', 'line_floor' => '100.0000',
    'company_floor' => '90.0000', 'cost' => '60.0000', 'margin_rate' => '0.4000',
    'trace' => ['cost_total' => '60.0000', 'gross_margin' => '40.0000'],
]];
$salesView = $maskService->maskRows($sensitiveRows, ['sales']);
check(isset($salesView[0]['guide_price']) && isset($salesView[0]['line_floor'])
    && !isset($salesView[0]['company_floor']) && !isset($salesView[0]['cost']) && !isset($salesView[0]['margin_rate'])
    && !isset($salesView[0]['trace']['cost_total']) && !isset($salesView[0]['trace']['gross_margin']),
    '销售角色：仅见指导价/产线控制价，成本/公司控制价/毛利（含嵌套）被移除');
$lineView = $maskService->maskRows($sensitiveRows, ['line_pricer']);
check(isset($lineView[0]['cost']) && !isset($lineView[0]['company_floor']) && isset($lineView[0]['line_floor']),
    '产线价格管理员：可见成本与产线控制价，公司控制价被移除');
$companyView = $maskService->maskRows($sensitiveRows, ['company_pricer']);
check(isset($companyView[0]['cost']) && isset($companyView[0]['company_floor']) && isset($companyView[0]['margin_rate']),
    '公司价格管理员：全字段可见');
$noRoleView = $maskService->maskRows($sensitiveRows, []);
check(!isset($noRoleView[0]['cost']) && !isset($noRoleView[0]['company_floor']) && !isset($noRoleView[0]['margin_rate']),
    '空角色集合按最低权限脱敏');
check(SensitiveFieldService::rolesOfAdmin(999999) === [], '未知 admin 角色解析返回 []（fail-closed）');
check(SensitiveFieldService::rolesOfAdmin(ADMIN_NOROLE) === [], '非 CPQ 角色组（guest）解析返回 []');
check(SensitiveFieldService::rolesOfAdmin(ADMIN_SUPER) === ['system_admin'], '超管组（rules=*）解析为 system_admin');

echo "\n== B5. 区域/组织/负责人维度单条读取兜底（assertQuoteAccess）==\n";
$quoteEastRow = Db::name('cpq_quote')->where('id', $quoteEast)->find();
$quoteEastOtherRow = Db::name('cpq_quote')->where('id', $quoteEastOther)->find();
$quoteEastChildRow = Db::name('cpq_quote')->where('id', $quoteEastChild)->find();
$quoteSouthRow = Db::name('cpq_quote')->where('id', $quoteSouth)->find();

$scopeSalesEast = QuoteDataScopeService::forAdmin(ADMIN_SALES_EAST);
check(noThrow(function () use ($scopeSalesEast, $quoteEastRow) {
    $scopeSalesEast->assertQuoteAccess($quoteEastRow);
}), '华东销售对自己华东报价 assertQuoteAccess 通过');
expectInvalid(function () use ($scopeSalesEast, $quoteSouthRow) {
    $scopeSalesEast->assertQuoteAccess($quoteSouthRow);
}, '华东销售对华南报价被拒绝（组织/区域越权）');
expectInvalid(function () use ($scopeSalesEast, $quoteEastOtherRow) {
    $scopeSalesEast->assertQuoteAccess($quoteEastOtherRow);
}, 'ownerOnly 销售对同组织他人报价被拒绝（负责人维度）');
check(noThrow(function () use ($quoteEastOtherRow) {
    QuoteDataScopeService::forAdmin(ADMIN_SALES_EAST2)->assertQuoteAccess($quoteEastOtherRow);
}), '同组织报价负责人本人访问通过');

$scopeManager = QuoteDataScopeService::forAdmin(ADMIN_MANAGER_EAST);
check(noThrow(function () use ($scopeManager, $quoteEastRow) {
    $scopeManager->assertQuoteAccess($quoteEastRow);
}), '销售经理对下辖组织报价通过');
check(noThrow(function () use ($scopeManager, $quoteEastChildRow) {
    $scopeManager->assertQuoteAccess($quoteEastChildRow);
}), '销售经理对下级组织（子树）报价通过');
expectInvalid(function () use ($scopeManager, $quoteSouthRow) {
    $scopeManager->assertQuoteAccess($quoteSouthRow);
}, '销售经理对平级其他组织（华南）报价被拒绝');

expectInvalid(function () use ($quoteEastRow) {
    QuoteDataScopeService::forAdmin(ADMIN_NOROLE)->assertQuoteAccess($quoteEastRow);
}, '无角色 admin 对任何报价被拒绝（fail-closed）');
expectInvalid(function () use ($quoteSouthRow) {
    QuoteDataScopeService::forAdmin(999999)->assertQuoteAccess($quoteSouthRow);
}, '不存在的 admin 对任何报价被拒绝（fail-closed）');

foreach ([ADMIN_COMPANY => '公司价格管理员', ADMIN_AUDITOR => '审计员', ADMIN_SUPER => '超管'] as $adminId => $label) {
    check(noThrow(function () use ($adminId, $quoteSouthRow) {
        QuoteDataScopeService::forAdmin($adminId)->assertQuoteAccess($quoteSouthRow);
    }), $label . '（全公司范围）对华南报价通过');
}
check(noThrow(function () use ($quoteSouthRow) {
    QuoteDataScopeService::forAdmin(ADMIN_LINE)->assertQuoteAccess($quoteSouthRow);
}), '产线价格管理员对华南报价通过（仅产品线维度，组织/区域不叠加）');

echo "\n== B6. PDF 下载权限（createJob/prepareDownload 数据范围兜底）==\n";
expectInvalid(function () use ($documentService, $quoteSouth) {
    $documentService->createJob($quoteSouth, 'zh', 0, ADMIN_SALES_EAST);
}, '华东销售对华南报价发起 PDF 生成被拒绝（区域越权）');

$job = $documentService->createJob($quoteSouth, 'zh', 0, ADMIN_SUPER);
$documentId = (int)$job['document']['id'];
check($documentId > 0 && in_array($job['document']['status'], ['pending', 'processing', 'succeeded'], true),
    '超管对华南报价发起 PDF 生成成功');
$processed = $documentService->process($documentId);
check($processed['status'] === 'succeeded' && is_file(ROOT_PATH .  $processed['file_path']), 'PDF 任务处理完成并落盘');

expectInvalid(function () use ($documentService, $documentId) {
    $documentService->prepareDownload($documentId, ADMIN_SALES_EAST);
}, '华东销售下载华南报价 PDF 被拒绝（区域越权）');
$download = $documentService->prepareDownload($documentId, ADMIN_SUPER);
check(is_file($download['absolute_path']), '超管下载华南报价 PDF 成功');
@unlink(ROOT_PATH .  $processed['file_path']); // 不污染演示目录
echo "[note] 篡改文件后 prepareDownload 抛 RuntimeException 并写 hash_mismatch 审计：已由 m3_approval.php 覆盖，此处不重复。\n";
echo "[note] 审批越权（候选人范围/职责分离/越权 act/负责人特批被拒）：已由 m3_approval.php 覆盖，此处不重复。\n";

// =====================================================================
// C. 敏感信息与凭证
// =====================================================================
echo "\n== C8. 审计脱敏 ==\n";
$auditSecrets = ['CPQ-SEC-pw', 'CPQ-SEC-ak', 'CPQ-SEC-tok', 'CPQ-SEC-cs', 'CPQ-SEC-cr'];
$auditId = (new AuditLogService())->record('security_probe', 'cpq_quote', $quoteEast, [
    'note' => 'CPQ-SEC-keep',
    'password' => 'CPQ-SEC-pw',
    'nested' => [
        'api_key' => 'CPQ-SEC-ak',
        'download_token' => 'CPQ-SEC-tok',
        'deep' => ['client_secret' => 'CPQ-SEC-cs', 'credential_stuff' => 'CPQ-SEC-cr', 'safe' => 'CPQ-SEC-safe'],
    ],
]);
$auditRow = Db::name('cpq_audit_log')->where('id', $auditId)->find();
$auditDetail = json_decode((string)$auditRow['detail_json'], true);
check(is_array($auditDetail), '审计明细保存为有效 JSON');
$auditPlain = (string)$auditRow['detail_json'];
$leaked = array_filter($auditSecrets, function ($secret) use ($auditPlain) {
    return strpos($auditPlain, $secret) !== false;
});
check($leaked === [], '审计 detail_json 落库不含 password/api_key/token/secret/credential_ 明文');
check(($auditDetail['password'] ?? null) === '[REDACTED]'
    && ($auditDetail['nested']['api_key'] ?? null) === '[REDACTED]'
    && ($auditDetail['nested']['download_token'] ?? null) === '[REDACTED]'
    && ($auditDetail['nested']['deep']['client_secret'] ?? null) === '[REDACTED]'
    && ($auditDetail['nested']['deep']['credential_stuff'] ?? null) === '[REDACTED]',
    '敏感键（含嵌套）被递归替换为 [REDACTED]');
check(($auditDetail['note'] ?? null) === 'CPQ-SEC-keep'
    && ($auditDetail['nested']['deep']['safe'] ?? null) === 'CPQ-SEC-safe',
    '非敏感业务字段保持原样');
foreach (['password', 'my_secret', 'download_token', 'credential_x', 'apikey', 'api_key', 'Authorization'] as $sensitiveKey) {
    check(preg_match(AuditLogService::SENSITIVE_KEY_PATTERN, $sensitiveKey) === 1,
        'SENSITIVE_KEY_PATTERN 覆盖键名：' . $sensitiveKey);
}
check(preg_match(AuditLogService::SENSITIVE_KEY_PATTERN, 'remark') === 0, 'SENSITIVE_KEY_PATTERN 不误伤普通键名');

echo "\n== C9. 集成凭证保险箱 ==\n";
$envKey = (string)Env::get('cpq.integration_key', '');
if ($envKey === '') {
    echo "[note] 未读取到 cpq.integration_key（CLI 下 Env 未加载 .env），C9 凭证保险箱用例跳过。\n";
} else {
    check(true, 'cpq.integration_key 已从 .env 加载（长度 ' . strlen($envKey) . '）');
    $vault = new IntegrationCredentialService();
    $credentialPlain = ['hmac_secret' => 'CPQ-SEC-never-plaintext'];
    $config = $vault->save([
        'code' => 'CPQ-SEC-ERP', 'name' => '安全测试ERP', 'system_type' => 'erp',
        'base_url' => 'https://erp.example.invalid', 'auth_type' => 'hmac', 'hmac_algorithm' => 'sha256',
        'timeout_ms' => 1200, 'max_retries' => 1, 'status' => 'enabled', 'credentials' => $credentialPlain,
    ]);
    $storedRow = Db::name('cpq_integration_config')->where('id', (int)$config['id'])->find();
    check(!empty($storedRow['credential_ciphertext']) && !empty($storedRow['credential_nonce']) && !empty($storedRow['credential_tag']),
        '凭证以 AES-256-GCM 密文/nonce/tag 落库');
    check(strpos(json_encode($storedRow), 'CPQ-SEC-never-plaintext') === false, '数据库行不含凭证明文');
    check(strpos(json_encode($config), 'CPQ-SEC-never-plaintext') === false
        && !isset($config['credential_ciphertext']) && $config['credentials_configured'] === true,
        'save 返回值不含密文/明文，仅 credentials_configured 布尔');
    $public = $vault->publicConfig($storedRow + ['extra' => ['credential_inner' => 'x', 'keep' => 'y']]);
    $publicJson = json_encode($public);
    check(strpos($publicJson, 'credential_') === false && $public['credentials_configured'] === true
        && ($public['extra']['keep'] ?? null) === 'y',
        'publicConfig 递归剥除所有 credential_* 键');
    check($vault->credentials((int)$config['id']) === $credentialPlain, 'credentials() 解密往返一致');
    $wrongVault = new IntegrationCredentialService('CPQ-SEC-WRONG-KEY');
    expectRuntime(function () use ($wrongVault, $config) {
        $wrongVault->credentials((int)$config['id']);
    }, '错误密钥解密被拒绝');
    Db::name('cpq_integration_config')->where('id', (int)$config['id'])
        ->update(['credential_ciphertext' => base64_encode('CPQ-SEC-corrupted'), 'updatetime' => time()]);
    expectRuntime(function () use ($vault, $config) {
        $vault->credentials((int)$config['id']);
    }, '损坏密文解密被拒绝');
}

echo "\n== C10. HMAC 签名 ==\n";
$auth = new IntegrationAuthService();
$hmacSecret = 'CPQ-SEC-HMAC-SECRET';
$hmacBody = '{"quote_id":1}';
$hmacTs = 1700000000;
$hmacSig = $auth->signHmac($hmacBody, $hmacTs, $hmacSecret);
check($auth->verifyHmac($hmacBody, $hmacTs, $hmacSig, $hmacSecret, 'sha256', 300, $hmacTs), '正确 HMAC 签名通过');
check($auth->verifyHmac($hmacBody, $hmacTs, $hmacSig, $hmacSecret, 'sha256', 300, $hmacTs + 300)
    && !$auth->verifyHmac($hmacBody, $hmacTs, $hmacSig, $hmacSecret, 'sha256', 300, $hmacTs + 301)
    && !$auth->verifyHmac($hmacBody, $hmacTs, $hmacSig, $hmacSecret, 'sha256', 300, $hmacTs - 301),
    '时间戳偏差 >300s 拒绝（边界 300s 放行）');
check(!$auth->verifyHmac($hmacBody, 'CPQ-SEC-not-a-ts', $hmacSig, $hmacSecret, 'sha256', 300, $hmacTs), '非数字时间戳拒绝');
check($auth->verifyHmac($hmacBody, $hmacTs, strtoupper($hmacSig), $hmacSecret, 'sha256', 300, $hmacTs),
    '签名大小写归一化：大写 hex 被 strtolower 后接受（与"入参需小写"预设不一致，记录为偏差，见报告）');
check(!$auth->verifyHmac($hmacBody . '-tampered', $hmacTs, $hmacSig, $hmacSecret, 'sha256', 300, $hmacTs), '篡改载荷签名失败');
$sig512 = $auth->signHmac($hmacBody, $hmacTs, $hmacSecret, 'sha512');
check(strlen($sig512) === 128 && $auth->verifyHmac($hmacBody, $hmacTs, $sig512, $hmacSecret, 'sha512', 300, $hmacTs),
    'sha512 算法签名/验签可用');
expectInvalid(function () use ($auth, $hmacBody, $hmacTs, $hmacSecret) {
    $auth->signHmac($hmacBody, $hmacTs, $hmacSecret, 'md5');
}, '非法 HMAC 算法（md5）签名被拒绝');
expectInvalid(function () use ($auth, $hmacBody, $hmacTs, $hmacSig, $hmacSecret) {
    $auth->verifyHmac($hmacBody, $hmacTs, $hmacSig, $hmacSecret, 'md5', 300, $hmacTs);
}, '非法 HMAC 算法（md5）验签被拒绝');
$roundTrip = $auth->signHmac($hmacBody, $hmacTs, $hmacSecret);
check($auth->verifyHmac($hmacBody, $hmacTs, $roundTrip, $hmacSecret, 'sha256', 300, $hmacTs), 'signHmac/verifyHmac 往返一致');

echo "\n== C11. 一次性导出下载令牌 ==\n";
$exportService = new ExportJobService();
$exportJob = $exportService->createQuoteExport(['product_line' => LINE, 'idempotency_key' => 'CPQ-SEC-EXPORT-1'], ADMIN_SUPER, false);
$exportRaw = (new AsyncJobService())->claim($exportJob['job_key']);
$exportDone = $exportService->process($exportRaw);
check($exportDone['status'] === 'succeeded', '报价明细导出任务处理成功');
$exportRow = Db::name('cpq_job')->where('job_key', $exportJob['job_key'])->find();
$payloadBeforeClaim = json_decode((string)$exportRow['payload_json'], true) ?: [];
$plainToken = (string)($payloadBeforeClaim['download_token'] ?? '');
check($plainToken !== '' && $exportRow['download_token_hash'] === hash('sha256', $plainToken)
    && $exportRow['download_token_hash'] !== $plainToken,
    '下载令牌以 sha256 哈希落库（download_token_hash），非明文');
expectInvalid(function () use ($exportService, $exportJob) {
    $exportService->claimDownloadToken($exportJob['job_key'], ADMIN_SALES_EAST);
}, '非创建人（且无特权角色）领取下载令牌被拒绝');
$claimedToken = $exportService->claimDownloadToken($exportJob['job_key'], ADMIN_SUPER);
check($claimedToken === $plainToken, '创建人领取到创建时暂存的同一份令牌');
expectInvalid(function () use ($exportService, $exportJob) {
    $exportService->claimDownloadToken($exportJob['job_key'], ADMIN_SUPER);
}, '令牌领取一次后即从 payload 烧毁，重复领取被拒绝');
$payloadAfterClaim = json_decode((string)Db::name('cpq_job')->where('job_key', $exportJob['job_key'])->value('payload_json'), true) ?: [];
check(!array_key_exists('download_token', $payloadAfterClaim), '领取后 payload_json 不再含明文令牌');
expectInvalid(function () use ($exportService, $exportJob) {
    $exportService->prepareDownload($exportJob['job_key'], 'CPQ-SEC-WRONG-TOKEN', ADMIN_SUPER);
}, '错误令牌下载被拒绝（hash_equals 校验）');
$exportDownload = $exportService->prepareDownload($exportJob['job_key'], $claimedToken, ADMIN_SUPER);
check(is_file($exportDownload['absolute_path']), '正确令牌首次下载成功');
check(Db::name('cpq_job')->where('job_key', $exportJob['job_key'])->value('download_token_hash') === '',
    '首次成功下载后令牌哈希烧毁（download_token_hash 置空）');
expectInvalid(function () use ($exportService, $exportJob, $claimedToken) {
    $exportService->prepareDownload($exportJob['job_key'], $claimedToken, ADMIN_SUPER);
}, '同一令牌再次下载被拒绝');
@unlink($exportDownload['absolute_path']);

echo "\n== C12. 日志不泄密（静态）==\n";
$logLeakHits = grepPhpLines([
    ROOT_PATH .  'application/common/service/cpq',
    ROOT_PATH .  'application/api/controller/cpq',
    ROOT_PATH .  'application/admin/controller/cpq',
    ROOT_PATH .  'application/common/library/cpq',
], '/(?:Log::|trace\().*(?:credential|password|secret|integration_key)/i');
check($logLeakHits === [], 'CPQ 业务代码不存在将 credential/password/secret/integration_key 写入 Log::/trace() 的调用');

// =====================================================================
// D. 文件上传面
// =====================================================================
echo "\n== D13. 报价附件与导入白名单（静态）==\n";
$attachmentHits = grepPhpLines(
    [ROOT_PATH .  'application/admin', ROOT_PATH .  'application/api', ROOT_PATH .  'application/common'],
    '/cpq_quote_attachment|QuoteAttachment/i',
    function ($path) {
        return strpos($path, '/model/') === false; // 模型文件的关联定义允许存在
    }
);
check($attachmentHits === [], '报价附件 fa_cpq_quote_attachment 无控制器/服务写入入口（仅模型关联）');
$importControllers = glob(ROOT_PATH .  'application/admin/controller/cpq/*.php');
$importWithUpload = [];
foreach ($importControllers as $controllerFile) {
    $source = file_get_contents($controllerFile);
    if (strpos($source, 'cpqImportRows') !== false) {
        $importWithUpload[] = [$controllerFile, strpos($source, "['xlsx', 'xls', 'csv']") !== false];
    }
}
check(count($importWithUpload) >= 1, '存在 Excel 导入入口（cpqImportRows）');
foreach ($importWithUpload as $item) {
    check($item[1], '导入入口 ' . basename($item[0]) . ' 强制 xlsx/xls/csv 扩展名白名单');
}
echo "[note] 以 .php/.phar/.html 构造真实上传请求需 HTTP 层，CLI 不可行；此处以扩展名校验代码静态断言代替（见报告）。\n";

echo "\n== D14. nginx uploads 禁 PHP（静态）==\n";
$nginxConf = file_get_contents(ROOT_PATH .  'docker/nginx/fastadmin.conf');
check(strpos($nginxConf, '(uploads|assets)') !== false && strpos($nginxConf, '(php|php5|phtml)') !== false
    && strpos($nginxConf, 'deny all') !== false,
    'nginx 对 uploads/assets 目录下 php/php5/phtml 拒绝执行');
check(strpos($nginxConf, 'location ~ /\.') !== false, 'nginx 拒绝访问隐藏文件（/.）');

// =====================================================================
// E. API 鉴权基座
// =====================================================================
echo "\n== E15. API 鉴权基座（静态）==\n";
$apiControllers = glob(ROOT_PATH .  'application/api/controller/cpq/*.php');
check(count($apiControllers) === 6, 'api/cpq 共六个控制器');
foreach ($apiControllers as $apiFile) {
    $source = file_get_contents($apiFile);
    check(strpos($source, 'extends Api') !== false && strpos($source, 'noNeedLogin') === false,
        basename($apiFile) . ' 继承 Api 基类且未覆写 $noNeedLogin（默认强制登录）');
}
checkFileContains(ROOT_PATH .  'application/common/controller/Api.php',
    ['$this->auth->isLogin()', "filter('trim,strip_tags,htmlspecialchars')"],
    'Api 基类默认登录校验 + 输入过滤逻辑存在');
echo "[note] api/cpq/v1/quotes* 未在 application/route.php 注册、由默认调度可达，"
    . "与 Quote.php 头部注释路由清单不一致，属已知文档偏差（见报告）。\n";

echo "\nM4 security tests: PASS ({$assertCount} assertions)\n";
