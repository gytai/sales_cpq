<?php
/**
 * 增量升级机制测试（GYTAI-67 / GYTAI-69）：SchemaUpgradeService + 升级脚本。
 *
 * 场景 A：空库安装语义 —— install.sql 建最终结构后 markAllApplied，pending 为空；
 * 场景 B：已有库升级 —— 按升级脚本头注释的反向 SQL 回退到升级前结构（含 DROP
 *         M2 客户渠道/发布版本表），applyAll 按文件名序应用
 *         2026090301_m1_master_data_governance.sql、
 *         2026090302_m1_rule_engine_bom_no_material.sql 与
 *         2026090303_m2_customer_channel_price.sql、
 *         2026090401_m2_pricing_agent_dimension.sql、
 *         2026090402_m2_quote_draft_snapshot_revision.sql、
 *         2026090403_m2_quote_context_dims.sql 后断言新唯一键、新表、
 *         no_material、代理商策略维度、报价表与定价上下文维度列就位，
 *         复跑 applyAll 幂等（返回空数组）；
 * 场景 C：已是最新结构的库 applyAll 为空操作。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/upgrade.php
 *
 * 使用独立临时库 cpq_m1_upgrade_test，结束时自动 DROP。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\service\cpq\SchemaUpgradeService;
use think\Config;

const TEST_DB = 'cpq_m1_upgrade_test';
const PREFIX = 'fa_';
const UPGRADE_SCRIPT = '2026090301_m1_master_data_governance.sql';
const UPGRADE_SCRIPT_2 = '2026090302_m1_rule_engine_bom_no_material.sql';
const UPGRADE_SCRIPT_3 = '2026090303_m2_customer_channel_price.sql';
const UPGRADE_SCRIPT_4 = '2026090401_m2_pricing_agent_dimension.sql';
const UPGRADE_SCRIPT_5 = '2026090402_m2_quote_draft_snapshot_revision.sql';
const UPGRADE_SCRIPT_6 = '2026090403_m2_quote_context_dims.sql';
const UPGRADE_SCRIPT_7 = '2026090404_m3_approval_print.sql';
const UPGRADE_SCRIPT_8 = '2026090501_m3_async_integration_platform.sql';

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

$dbConfig = Config::get('database');
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbConfig['hostname'], $dbConfig['hostport'] ?: 3306);
$pdo = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

register_shutdown_function(function () use ($rootDsn, $dbConfig) {
    try {
        $cleanup = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cleanup->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
        echo "[cleanup] 临时库 " . TEST_DB . " 已删除\n";
    } catch (\Throwable $exception) {
        echo "[cleanup] 临时库清理失败：" . $exception->getMessage() . "\n";
    }
});

$resetDatabase = function () use ($pdo) {
    $pdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
    $pdo->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $pdo->exec('USE `' . TEST_DB . '`');
};
$runInstall = function () use ($pdo) {
    $sql = file_get_contents(dirname(__DIR__, 2) . '/database/cpq/install.sql');
    $pdo->exec(str_replace('__PREFIX__', PREFIX, $sql));
};
/** 按升级脚本头注释的反向 SQL 回退到升级前（M0 基线）结构 */
$revertToPreUpgrade = function () use ($pdo) {
    $reverse = <<<SQL
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `fa_cpq_quote_attachment`;
DROP TABLE IF EXISTS `fa_cpq_quote_price_snapshot`;
DROP TABLE IF EXISTS `fa_cpq_quote_config_snapshot`;
DROP TABLE IF EXISTS `fa_cpq_quote_term`;
DROP TABLE IF EXISTS `fa_cpq_quote_revision`;
DROP TABLE IF EXISTS `fa_cpq_quote_line`;
DROP TABLE IF EXISTS `fa_cpq_quote`;
SET FOREIGN_KEY_CHECKS = 1;
ALTER TABLE `fa_cpq_product_series`
  DROP INDEX `uk_cpq_product_series_code_version`,
  ADD UNIQUE KEY `uk_cpq_product_series_code` (`code`);
ALTER TABLE `fa_cpq_product_model`
  DROP INDEX `uk_cpq_product_model_code_version`,
  ADD UNIQUE KEY `uk_cpq_product_model_code` (`code`);
ALTER TABLE `fa_cpq_option_value` DROP COLUMN `no_material`;
ALTER TABLE `fa_cpq_price_policy`
  DROP INDEX `idx_cpq_price_policy_agent`,
  DROP COLUMN `agent_id`;
DROP TABLE IF EXISTS `fa_cpq_admin_product_line`;
DROP TABLE IF EXISTS `fa_cpq_audit_log`;
DROP TABLE IF EXISTS `fa_cpq_migration`;
-- M2 客户渠道与价格发布版本表（2026090303 反向）
DROP TABLE IF EXISTS `fa_cpq_release_version`;
DROP TABLE IF EXISTS `fa_cpq_agent`;
DROP TABLE IF EXISTS `fa_cpq_customer`;
DROP TABLE IF EXISTS `fa_cpq_sales_org_member`;
DROP TABLE IF EXISTS `fa_cpq_sales_org`;
DROP TABLE IF EXISTS `fa_cpq_region`;
DROP TABLE IF EXISTS `fa_cpq_agent_level`;
DROP TABLE IF EXISTS `fa_cpq_customer_level`;
-- M3 审批/模板/打印（2026090404 反向）
DROP TABLE IF EXISTS `fa_cpq_quote_document`;
DROP TABLE IF EXISTS `fa_cpq_quote_template`;
DROP TABLE IF EXISTS `fa_cpq_approval_delegation`;
DROP TABLE IF EXISTS `fa_cpq_approval_rule`;
DROP TABLE IF EXISTS `fa_cpq_approval_action`;
DROP TABLE IF EXISTS `fa_cpq_approval_task`;
DROP TABLE IF EXISTS `fa_cpq_approval_instance`;
-- M3 异步/集成平台（2026090501 反向）
DROP TABLE IF EXISTS `fa_cpq_download_log`;
DROP TABLE IF EXISTS `fa_cpq_integration_event`;
DROP TABLE IF EXISTS `fa_cpq_integration_config`;
DROP TABLE IF EXISTS `fa_cpq_import_batch`;
DROP TABLE IF EXISTS `fa_cpq_job`;
DROP TABLE IF EXISTS `fa_cpq_number_counter`;
DROP TABLE IF EXISTS `fa_cpq_number_rule`;
DROP TABLE IF EXISTS `fa_cpq_dictionary_reference`;
DROP TABLE IF EXISTS `fa_cpq_dictionary_value`;
SQL;
    foreach (array_filter(array_map('trim', explode(';', $reverse))) as $statement) {
        $pdo->exec($statement);
    }
};
$indexExists = function ($table, $index) use ($pdo) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = ? AND table_name = ? AND index_name = ?'
    );
    $statement->execute([TEST_DB, PREFIX . $table, $index]);
    return (int)$statement->fetchColumn() > 0;
};
$tableExists = function ($table) use ($pdo) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
    );
    $statement->execute([TEST_DB, PREFIX . $table]);
    return (int)$statement->fetchColumn() > 0;
};
$columnExists = function ($table, $column) use ($pdo) {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?'
    );
    $statement->execute([TEST_DB, PREFIX . $table, $column]);
    return (int)$statement->fetchColumn() > 0;
};

$service = new SchemaUpgradeService(null, PREFIX);
check(in_array(UPGRADE_SCRIPT, $service->scriptFiles(), true), '升级脚本目录可枚举到 ' . UPGRADE_SCRIPT);
check(in_array(UPGRADE_SCRIPT_2, $service->scriptFiles(), true), '升级脚本目录可枚举到 ' . UPGRADE_SCRIPT_2);
check(in_array(UPGRADE_SCRIPT_4, $service->scriptFiles(), true), '升级脚本目录可枚举到 ' . UPGRADE_SCRIPT_4);
check(in_array(UPGRADE_SCRIPT_5, $service->scriptFiles(), true), '升级脚本目录可枚举到 ' . UPGRADE_SCRIPT_5);
check(in_array(UPGRADE_SCRIPT_6, $service->scriptFiles(), true), '升级脚本目录可枚举到 ' . UPGRADE_SCRIPT_6);
check(in_array(UPGRADE_SCRIPT_7, $service->scriptFiles(), true), '升级脚本目录可枚举到 ' . UPGRADE_SCRIPT_7);
check(in_array(UPGRADE_SCRIPT_8, $service->scriptFiles(), true), '升级脚本目录可枚举到 ' . UPGRADE_SCRIPT_8);

// ---------------------------------------------------------------------
// 场景 A：空库安装语义
// ---------------------------------------------------------------------
echo "\n== 场景 A：空库安装语义 ==\n";
$resetDatabase();
$runInstall();
check($tableExists('admin'), '合并基线包含 FastAdmin 基础表 fa_admin');
check($tableExists('config'), '合并基线包含 FastAdmin 配置表 fa_config');
check((int)$pdo->query('SELECT COUNT(*) FROM `' . PREFIX . 'admin`')->fetchColumn() >= 1, '合并基线携带基础初始数据（管理员初始行）');
$marked = $service->markAllApplied($pdo);
check(in_array(UPGRADE_SCRIPT, $marked, true), 'markAllApplied 标记了 ' . UPGRADE_SCRIPT);
check($service->pendingScripts($pdo) === [], '空库安装后 pendingScripts 为空');
check($service->appliedScripts($pdo) === $service->scriptFiles(), 'appliedScripts 与脚本清单一致');

// ---------------------------------------------------------------------
// 场景 C：已是最新结构的库 applyAll 为空操作
// ---------------------------------------------------------------------
echo "\n== 场景 C：最新结构库 applyAll 空操作 ==\n";
check($service->applyAll($pdo) === [], '已标记全部脚本的库 applyAll 返回空数组');

// ---------------------------------------------------------------------
// 场景 B：已有库升级（先回退到升级前结构，再前滚）
// ---------------------------------------------------------------------
echo "\n== 场景 B：已有库升级 ==\n";
$resetDatabase();
$runInstall();
$revertToPreUpgrade();
check($indexExists('cpq_product_series', 'uk_cpq_product_series_code'), '回退后旧唯一键 uk_cpq_product_series_code 存在');
check(!$tableExists('cpq_audit_log') && !$tableExists('cpq_admin_product_line') && !$tableExists('cpq_migration'), '回退后审计/范围/迁移表不存在');
check(!$columnExists('cpq_option_value', 'no_material'), '回退后 no_material 列不存在');
check(!$tableExists('cpq_quote') && !$tableExists('cpq_quote_revision'), '回退后报价与版本表不存在');
check($service->pendingScripts($pdo) === [UPGRADE_SCRIPT, UPGRADE_SCRIPT_2, UPGRADE_SCRIPT_3, UPGRADE_SCRIPT_4, UPGRADE_SCRIPT_5, UPGRADE_SCRIPT_6, UPGRADE_SCRIPT_7, UPGRADE_SCRIPT_8], '回退后 pendingScripts 包含全部 M1/M2/M3 脚本（cpq_migration 由服务自动补建）');
check(!$tableExists('cpq_customer') && !$tableExists('cpq_release_version'), '回退后 M2 客户/发布版本表不存在');
check(!$tableExists('cpq_approval_instance') && !$tableExists('cpq_quote_template') && !$tableExists('cpq_quote_document'), '回退后 M3 审批/模板/打印表不存在');

$applied = $service->applyAll($pdo);
check($applied === [UPGRADE_SCRIPT, UPGRADE_SCRIPT_2, UPGRADE_SCRIPT_3, UPGRADE_SCRIPT_4, UPGRADE_SCRIPT_5, UPGRADE_SCRIPT_6, UPGRADE_SCRIPT_7, UPGRADE_SCRIPT_8], 'applyAll 按文件名序应用 M1/M2/M3 脚本');
check($indexExists('cpq_product_series', 'uk_cpq_product_series_code_version'), '升级后新唯一键 uk_cpq_product_series_code_version 存在');
check(!$indexExists('cpq_product_series', 'uk_cpq_product_series_code'), '升级后旧唯一键 uk_cpq_product_series_code 已删除');
check($indexExists('cpq_product_model', 'uk_cpq_product_model_code_version'), '升级后新唯一键 uk_cpq_product_model_code_version 存在');
check(!$indexExists('cpq_product_model', 'uk_cpq_product_model_code'), '升级后旧唯一键 uk_cpq_product_model_code 已删除');
check($tableExists('cpq_audit_log'), '升级后 fa_cpq_audit_log 表存在');
check($tableExists('cpq_admin_product_line'), '升级后 fa_cpq_admin_product_line 表存在');
check($tableExists('cpq_migration'), '升级后 fa_cpq_migration 表存在');
check($columnExists('cpq_option_value', 'no_material'), '升级后 cpq_option_value.no_material 列存在');
check($columnExists('cpq_price_policy', 'agent_id'), '升级后 cpq_price_policy.agent_id 指定代理商维度存在');
check($indexExists('cpq_price_policy', 'idx_cpq_price_policy_agent'), '升级后指定代理商维度索引存在');
foreach (['cpq_customer_level', 'cpq_agent_level', 'cpq_region', 'cpq_sales_org', 'cpq_sales_org_member', 'cpq_customer', 'cpq_agent', 'cpq_release_version'] as $m2Table) {
    check($tableExists($m2Table), '升级后 ' . PREFIX . $m2Table . ' 表存在');
}
foreach (['cpq_quote', 'cpq_quote_revision', 'cpq_quote_line', 'cpq_quote_config_snapshot', 'cpq_quote_price_snapshot', 'cpq_quote_term', 'cpq_quote_attachment'] as $quoteTable) {
    check($tableExists($quoteTable), '升级后 ' . PREFIX . $quoteTable . ' 表存在');
}
check($columnExists('cpq_quote', 'company'), '升级后 cpq_quote.company 我方公司维度列存在');
check($columnExists('cpq_quote', 'market_scope'), '升级后 cpq_quote.market_scope 市场范围列存在');
foreach (['cpq_approval_instance', 'cpq_approval_task', 'cpq_approval_action', 'cpq_approval_rule', 'cpq_approval_delegation', 'cpq_quote_template', 'cpq_quote_document'] as $m3Table) {
    check($tableExists($m3Table), '升级后 ' . PREFIX . $m3Table . ' 表存在');
}
foreach (['cpq_dictionary_value','cpq_dictionary_reference','cpq_number_rule','cpq_number_counter','cpq_job','cpq_import_batch','cpq_integration_config','cpq_integration_event','cpq_download_log'] as $platformTable) {
    check($tableExists($platformTable), '升级后 ' . PREFIX . $platformTable . ' 表存在');
}
$enumStmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
$enumStmt->execute([TEST_DB, PREFIX . 'cpq_quote', 'status']);
check(strpos((string)$enumStmt->fetchColumn(), "'returned'") !== false, '升级后 cpq_quote.status 枚举含 returned（审批退回）');

check($service->applyAll($pdo) === [], '复跑 applyAll 幂等（无重复执行，返回空数组）');
check($service->pendingScripts($pdo) === [], '升级完成后 pendingScripts 为空');

echo "\nM1+M2+M3 upgrade tests: PASS ({$assertCount} assertions)\n";
