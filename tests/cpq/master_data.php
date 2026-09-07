<?php
/**
 * M1 产品与配置主数据治理测试（GYTAI-67）。
 *
 * 覆盖：唯一键 (code,version)、版本不可变、状态机（草稿→待审批→发布→失效）、
 * 版本接替（supersede）、复制新版本、引用保护、产品线数据范围、审计日志、
 * 型号子表写入守卫。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/master_data.php
 *
 * 使用独立临时库 cpq_m1_master_test（执行 install.sql 建最终结构），
 * 结束时自动 DROP；所有种子数据编码带 CPQ-TEST- 前缀。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\admin\model\cpq\BomMapping;
use app\admin\model\cpq\ProductModel;
use app\admin\model\cpq\ProductSeries;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\MasterDataLifecycleService;
use app\common\service\cpq\ProductLineScopeService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m1_master_test';
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

// 在首次 Db 连接前切换到临时库
Config::set('database.database', TEST_DB);
check(Db::name('cpq_product_series')->count() === 0, '临时库已就位（fa_cpq_product_series 为空）');

$service = new MasterDataLifecycleService();
$now = time();

// ---------------------------------------------------------------------
// 种子数据
// ---------------------------------------------------------------------
$lineA = 'CPQ-TEST-LINE-A';
$lineB = 'CPQ-TEST-LINE-B';

$insertSeries = function ($code, $version, $line, $status = 'draft') use ($now) {
    return (int)Db::name('cpq_product_series')->insertGetId([
        'code' => $code, 'name' => $code . ' 系列', 'product_line' => $line,
        'version' => $version, 'status' => $status,
        'createtime' => $now, 'updatetime' => $now,
    ]);
};
$insertModel = function ($seriesId, $code, $version, $status = 'draft') use ($now) {
    return (int)Db::name('cpq_product_model')->insertGetId([
        'series_id' => $seriesId, 'code' => $code, 'name' => $code . ' 型号',
        'version' => $version, 'status' => $status,
        'createtime' => $now, 'updatetime' => $now,
    ]);
};

$seriesA1 = $insertSeries('CPQ-TEST-SERIES-A', 1, $lineA);
$modelA1 = $insertModel($seriesA1, 'CPQ-TEST-MODEL-A', 1);

$groupId = (int)Db::name('cpq_option_group')->insertGetId([
    'code' => 'CPQ-TEST-GROUP-A', 'name' => '测试配置组', 'input_type' => 'single',
    'createtime' => $now, 'updatetime' => $now,
]);
$optionA = (int)Db::name('cpq_option_value')->insertGetId([
    'group_id' => $groupId, 'code' => 'CPQ-TEST-OPT-A', 'name' => '测试选项A',
    'createtime' => $now, 'updatetime' => $now,
]);
$optionB = (int)Db::name('cpq_option_value')->insertGetId([
    'group_id' => $groupId, 'code' => 'CPQ-TEST-OPT-B', 'name' => '测试选项B',
    'createtime' => $now, 'updatetime' => $now,
]);
$paramId = (int)Db::name('cpq_parameter_definition')->insertGetId([
    'code' => 'CPQ-TEST-PARAM-A', 'name' => '测试参数', 'value_type' => 'text',
    'createtime' => $now, 'updatetime' => $now,
]);
$paramFree = (int)Db::name('cpq_parameter_definition')->insertGetId([
    'code' => 'CPQ-TEST-PARAM-FREE', 'name' => '无引用参数', 'value_type' => 'text',
    'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_model_option_group')->insert([
    'model_id' => $modelA1, 'group_id' => $groupId, 'is_visible' => 1,
    'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_model_parameter')->insert([
    'model_id' => $modelA1, 'parameter_id' => $paramId, 'value' => 'v1',
    'createtime' => $now, 'updatetime' => $now,
]);

// ---------------------------------------------------------------------
// 1. 唯一性：同 (code,version) 拒绝；同 code 不同 version 允许
// ---------------------------------------------------------------------
echo "\n== 唯一性 ==\n";
$seriesU1 = $insertSeries('CPQ-TEST-SERIES-UNIQ', 1, $lineA);
expectThrow(function () use ($insertSeries, $lineA) {
    $insertSeries('CPQ-TEST-SERIES-UNIQ', 1, $lineA);
}, '系列：同 (code,version) 重复插入被唯一键拒绝');
$seriesU2 = $insertSeries('CPQ-TEST-SERIES-UNIQ', 2, $lineA);
check($seriesU2 > 0, '系列：同 code 不同 version 允许并存');

$modelU1 = $insertModel($seriesA1, 'CPQ-TEST-MODEL-UNIQ', 1);
expectThrow(function () use ($insertModel, $seriesA1) {
    $insertModel($seriesA1, 'CPQ-TEST-MODEL-UNIQ', 1);
}, '型号：同 (code,version) 重复插入被唯一键拒绝');
check($insertModel($seriesA1, 'CPQ-TEST-MODEL-UNIQ', 2) > 0, '型号：同 code 不同 version 允许并存');

// ---------------------------------------------------------------------
// 2. 版本不可变 / 仅草稿可删
// ---------------------------------------------------------------------
echo "\n== 版本不可变与删除守卫 ==\n";
expectThrow(function () use ($service) {
    $service->assertEditable('cpq_product_series', ['status' => 'published']);
}, 'published 版本 assertEditable 抛异常');
expectThrow(function () use ($service) {
    $service->assertEditable('cpq_product_model', ['status' => 'expired']);
}, 'expired 版本 assertEditable 抛异常');
$service->assertEditable('cpq_product_series', ['status' => 'draft']);
check(true, 'draft 版本 assertEditable 不抛异常');
$service->assertEditable('cpq_product_series', ['status' => 'pending']);
check(true, 'pending 版本 assertEditable 不抛异常');
$service->assertEditable('cpq_option_group', ['status' => 'normal']);
check(true, '非版本化表 assertEditable 不抛异常');

foreach (['pending', 'published', 'expired'] as $status) {
    expectThrow(function () use ($service, $status) {
        $service->assertDraftDeletable('cpq_product_series', ['status' => $status]);
    }, "非草稿（{$status}）版本 assertDraftDeletable 抛异常");
}
$service->assertDraftDeletable('cpq_product_series', ['status' => 'draft']);
check(true, 'draft 版本 assertDraftDeletable 不抛异常');

// ---------------------------------------------------------------------
// 3. 系列状态机：draft 直接 publish 拒绝 → pending → publish → 复制 → 接替
// ---------------------------------------------------------------------
echo "\n== 系列状态机与版本接替 ==\n";
expectThrow(function () use ($service, $seriesA1) {
    $service->publish(ProductSeries::get($seriesA1));
}, '系列 draft 直接 publish 被拒绝（须先提交审批）');

Db::name('cpq_product_series')->where('id', $seriesA1)->update(['status' => 'pending']);
$service->publish(ProductSeries::get($seriesA1));
check(Db::name('cpq_product_series')->where('id', $seriesA1)->value('status') === 'published', '系列 pending→publish 成功');

$seriesA2Data = $service->copyNewVersion(ProductSeries::get($seriesA1));
$seriesA2 = (int)$seriesA2Data['id'];
check($seriesA2Data['version'] == 2 && $seriesA2Data['status'] === 'draft', '系列 copyNewVersion 生成 version+1 草稿');
expectThrow(function () use ($service, $seriesA1) {
    $service->copyNewVersion(ProductSeries::get($seriesA1));
}, '已存在草稿时再次 copyNewVersion 抛异常');

// 复制出的系列 v2 发布前需至少一个有效型号（发布校验先于接替执行）
$modelB = $insertModel($seriesA2, 'CPQ-TEST-MODEL-B', 1);
Db::name('cpq_product_series')->where('id', $seriesA2)->update(['status' => 'pending']);
$service->publish(ProductSeries::get($seriesA2));
check(Db::name('cpq_product_series')->where('id', $seriesA1)->value('status') === 'expired', '系列 v2 发布后同 code 旧 published 版本自动 expired');
check((int)Db::name('cpq_product_model')->where('id', $modelA1)->value('series_id') === $seriesA2, '系列接替：旧版本下型号改挂新系列行');
check((int)Db::name('cpq_product_model')->where('id', $modelB)->value('series_id') === $seriesA2, '系列接替：新版本自带型号保持在原位');

expectThrow(function () use ($service, $seriesA1) {
    $service->expire(ProductSeries::get($seriesA1));
}, 'expire 非 published 版本（已 expired）抛异常');
$service->expire(ProductSeries::get($seriesA2));
check(Db::name('cpq_product_series')->where('id', $seriesA2)->value('status') === 'expired', '系列 published→expire 成功');

// ---------------------------------------------------------------------
// 4. 型号状态机：发布接替时子数据与引用改挂
// ---------------------------------------------------------------------
echo "\n== 型号状态机与版本接替 ==\n";
expectThrow(function () use ($service, $modelA1) {
    $service->publish(ProductModel::get($modelA1));
}, '型号 draft 直接 publish 被拒绝');

// 引用行：规则 / 模板 / BOM 映射挂在型号 v1 上
$ruleId = (int)Db::name('cpq_config_rule')->insertGetId([
    'code' => 'CPQ-TEST-RULE-A', 'name' => '测试规则', 'type' => 'REQUIRES',
    'model_id' => $modelA1, 'product_line' => '',
    'condition_json' => '{}', 'action_json' => '[]',
    'version' => 1, 'status' => 'draft', 'createtime' => $now, 'updatetime' => $now,
]);
$templateId = (int)Db::name('cpq_config_template')->insertGetId([
    'code' => 'CPQ-TEST-TEMPLATE-A', 'name' => '测试模板', 'model_id' => $modelA1,
    'config_json' => '{}', 'version' => 1, 'status' => 'draft',
    'createtime' => $now, 'updatetime' => $now,
]);
$bomId = (int)Db::name('cpq_bom_mapping')->insertGetId([
    'model_id' => $modelA1, 'option_value_id' => $optionA, 'material_code' => 'CPQ-TEST-MAT-A',
    'version' => 1, 'status' => 'draft', 'createtime' => $now, 'updatetime' => $now,
]);

Db::name('cpq_product_model')->where('id', $modelA1)->update(['status' => 'pending']);
$service->publish(ProductModel::get($modelA1));
check(Db::name('cpq_product_model')->where('id', $modelA1)->value('status') === 'published', '型号 pending→publish 成功');

$modelA2Data = $service->copyNewVersion(ProductModel::get($modelA1));
$modelA2 = (int)$modelA2Data['id'];
check($modelA2Data['version'] == 2 && $modelA2Data['status'] === 'draft', '型号 copyNewVersion 生成 version+1 草稿');
check(Db::name('cpq_model_parameter')->where('model_id', $modelA2)->count() === 1, '型号复制：model_parameter 子数据已复制到新行');
check(Db::name('cpq_model_option_group')->where('model_id', $modelA2)->count() === 1, '型号复制：model_option_group 子数据已复制到新行');

Db::name('cpq_product_model')->where('id', $modelA2)->update(['status' => 'pending']);
$service->publish(ProductModel::get($modelA2));
check(Db::name('cpq_product_model')->where('id', $modelA1)->value('status') === 'expired', '型号 v2 发布后旧 published 版本自动 expired');
check((int)Db::name('cpq_config_rule')->where('id', $ruleId)->value('model_id') === $modelA2, '型号接替：配置规则 model_id 改挂新行');
check((int)Db::name('cpq_config_template')->where('id', $templateId)->value('model_id') === $modelA2, '型号接替：配置模板 model_id 改挂新行');
check((int)Db::name('cpq_bom_mapping')->where('id', $bomId)->value('model_id') === $modelA2, '型号接替：BOM 映射 model_id 改挂新行');
check(Db::name('cpq_model_parameter')->where('model_id', $modelA1)->count() === 0, '型号接替：旧行 model_parameter 残留被清理');
check(Db::name('cpq_model_option_group')->where('model_id', $modelA1)->count() === 0, '型号接替：旧行 model_option_group 残留被清理');

// ---------------------------------------------------------------------
// 5. 型号子表写入守卫
// ---------------------------------------------------------------------
echo "\n== 型号子表写入守卫 ==\n";
expectThrow(function () use ($service, $modelA2) {
    $service->assertWritableParent('cpq_model_parameter', ['model_id' => $modelA2]);
}, '已发布型号写入 model_parameter 被 assertWritableParent 拒绝');
$service->assertWritableParent('cpq_model_parameter', ['model_id' => $modelB]);
check(true, '草稿型号写入 model_parameter 不抛异常');
$service->assertWritableParent('cpq_option_value', ['model_id' => $modelA2]);
check(true, '非型号子表 assertWritableParent 不干预');

$service->expire(ProductModel::get($modelA2));
check(Db::name('cpq_product_model')->where('id', $modelA2)->value('status') === 'expired', '型号 published→expire 成功');
expectThrow(function () use ($service, $modelA2) {
    $service->expire(ProductModel::get($modelA2));
}, 'expire 非 published 型号抛异常');

// ---------------------------------------------------------------------
// 6. BOM 映射免审批：draft 直接 publish
// ---------------------------------------------------------------------
echo "\n== BOM 映射免审批发布 ==\n";
$service->publish(BomMapping::get($bomId));
check(Db::name('cpq_bom_mapping')->where('id', $bomId)->value('status') === 'published', 'cpq_bom_mapping 草稿可直接发布');
expectThrow(function () use ($service, $bomId) {
    $service->publish(BomMapping::get($bomId));
}, '已发布 BOM 映射再次 publish 抛异常');

// ---------------------------------------------------------------------
// 7. 引用保护
// ---------------------------------------------------------------------
echo "\n== 删除引用保护 ==\n";
$message = expectThrow(function () use ($service, $seriesA2) {
    $service->assertDeletable('cpq_product_series', Db::name('cpq_product_series')->where('id', $seriesA2)->select());
}, '被型号引用的系列 assertDeletable 抛异常');
check(strpos($message, '产品型号') !== false && strpos($message, 'CPQ-TEST-SERIES-A') !== false, '系列阻断信息可读（含编码与引用方）');

$message = expectThrow(function () use ($service, $optionA) {
    $service->assertDeletable('cpq_option_value', Db::name('cpq_option_value')->where('id', $optionA)->select());
}, '被 BOM 映射引用的选项 assertDeletable 抛异常');
check(strpos($message, 'BOM映射') !== false, '选项阻断信息可读（含引用方 BOM映射）');

$message = expectThrow(function () use ($service, $paramId) {
    $service->assertDeletable('cpq_parameter_definition', Db::name('cpq_parameter_definition')->where('id', $paramId)->select());
}, '被型号参数引用的参数定义 assertDeletable 抛异常');
check(strpos($message, '型号参数') !== false, '参数定义阻断信息可读（含引用方 型号参数）');

$service->assertDeletable('cpq_product_series', Db::name('cpq_product_series')->where('id', $seriesU1)->select());
check(true, '无引用的系列 assertDeletable 不抛异常');
$service->assertDeletable('cpq_option_value', Db::name('cpq_option_value')->where('id', $optionB)->select());
check(true, '无引用的选项 assertDeletable 不抛异常');
$service->assertDeletable('cpq_parameter_definition', Db::name('cpq_parameter_definition')->where('id', $paramFree)->select());
check(true, '无引用的参数定义 assertDeletable 不抛异常');

// ---------------------------------------------------------------------
// 8. 产品线数据范围
// ---------------------------------------------------------------------
echo "\n== 产品线数据范围 ==\n";
Db::name('cpq_admin_product_line')->insertAll([
    ['admin_id' => 9101, 'product_line' => $lineA, 'createtime' => $now, 'updatetime' => $now],
    ['admin_id' => 9102, 'product_line' => '*', 'createtime' => $now, 'updatetime' => $now],
]);

$limited = new ProductLineScopeService(9101, false);
$limited->assertLineAllowed($lineA);
check(true, '受限管理员：被授权产品线放行');
expectThrow(function () use ($limited, $lineB) {
    $limited->assertLineAllowed($lineB);
}, '受限管理员：未授权产品线抛异常');
expectThrow(function () use ($limited) {
    $limited->assertLineAllowed('');
}, '受限管理员：空产品线抛异常');

$wildcard = new ProductLineScopeService(9102, false);
check($wildcard->isUnrestricted(), '含 * 记录的管理员不受限制');
$wildcard->assertLineAllowed($lineB);
check(true, '* 管理员：任意产品线放行');

$failClosed = new ProductLineScopeService(9103, false);
expectThrow(function () use ($failClosed, $lineA) {
    $failClosed->assertLineAllowed($lineA);
}, '无任何范围记录的管理员 fail-closed 全拒绝');

$super = new ProductLineScopeService(9999, true);
check($super->isUnrestricted(), '显式超管标记（isSuperAdmin=true）不受限制');

// 经由生命周期服务验证越权发布被拒
$seriesB1 = $insertSeries('CPQ-TEST-SERIES-B', 1, $lineB, 'pending');
$insertModel($seriesB1, 'CPQ-TEST-MODEL-C', 1);
expectThrow(function () use ($service, $seriesB1, $limited) {
    $service->publish(ProductSeries::get($seriesB1), $limited);
}, '越权发布（assertLineScope 经 publish）被拒绝');
$service->publish(ProductSeries::get($seriesB1), $super);
check(Db::name('cpq_product_series')->where('id', $seriesB1)->value('status') === 'published', '授权范围内发布成功');

// ---------------------------------------------------------------------
// 9. 审计日志
// ---------------------------------------------------------------------
echo "\n== 审计日志 ==\n";
$auditOf = function ($action, $objectType, $objectId) {
    return Db::name('cpq_audit_log')
        ->where('action', $action)
        ->where('object_type', $objectType)
        ->where('object_id', $objectId)
        ->find();
};
$log = $auditOf(AuditLogService::ACTION_PUBLISH, 'cpq_product_series', $seriesA1);
check($log && $log['object_code'] === 'CPQ-TEST-SERIES-A', '审计：系列 publish 已记录（object_type/object_id/object_code 匹配）');
check($auditOf(AuditLogService::ACTION_EXPIRE, 'cpq_product_series', $seriesA2) !== null, '审计：系列 expire 已记录');
$log = $auditOf(AuditLogService::ACTION_COPY, 'cpq_product_series', $seriesA2);
check($log !== null, '审计：系列 copy 已记录（object_id 为新行）');
check($auditOf(AuditLogService::ACTION_PUBLISH, 'cpq_product_model', $modelA2) !== null, '审计：型号 publish 已记录');
check($auditOf(AuditLogService::ACTION_COPY, 'cpq_product_model', $modelA2) !== null, '审计：型号 copy 已记录');
check($auditOf(AuditLogService::ACTION_PUBLISH, 'cpq_bom_mapping', $bomId) !== null, '审计：BOM 映射 publish 已记录');
check(Db::name('cpq_audit_log')->where('username', 'system')->count() > 0, '审计：CLI 上下文操作人落 system');

echo "\nM1 master data tests: PASS ({$assertCount} assertions)\n";
