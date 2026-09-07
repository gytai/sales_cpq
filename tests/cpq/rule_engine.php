<?php
/**
 * M1 配置规则引擎与 BOM 校验的数据库集成测试（GYTAI-66）。
 *
 * 覆盖验收用例：
 *  - C-005：新规则形成循环依赖 / 永真冲突 / 不可达选项 → 发布失败；
 *  - C-006：规则版本更新后旧版本行冻结（内容不变），历史配置按旧
 *    快照 schema 还原仍得到相同 configuration_hash；
 *  - C-007 异常分支：未发布型号的 schema 读取被拒绝；前端绕过的非法
 *    配置在服务端被拒绝；
 *  - P20：BOM 缺失映射校验（映射 / 物料编码 / no_material 标记）。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/rule_engine.php
 *
 * 使用独立临时库 cpq_m1_rule_engine_test（执行 install.sql 建最终结构），
 * 结束时自动 DROP；所有种子数据编码带 CPQ-TEST- 前缀。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\admin\model\cpq\BomMapping;
use app\admin\model\cpq\ConfigRule;
use app\admin\model\cpq\ProductModel;
use app\admin\model\cpq\ProductSeries;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use app\common\service\cpq\ConfigurationService;
use app\common\service\cpq\MasterDataLifecycleService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m1_rule_engine_test';
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
check(Db::name('cpq_config_rule')->count() === 0, '临时库已就位（fa_cpq_config_rule 为空）');

$service = new MasterDataLifecycleService();
$now = time();
$line = 'CPQ-TEST-LINE-R';

// ---------------------------------------------------------------------
// 种子数据：系列（待审批）+ 型号（待审批）+ 配置组/选项/结构
// ---------------------------------------------------------------------
$seriesId = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-TEST-SERIES-R', 'name' => '规则引擎测试系列', 'name_en' => '',
    'product_line' => $line, 'description' => '', 'weigh' => 0,
    'version' => 1, 'status' => 'pending', 'createtime' => $now, 'updatetime' => $now,
]);
$modelId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-TEST-MODEL-R', 'name' => '规则引擎测试型号',
    'name_en' => '', 'category_code' => 'EQUIPMENT', 'base_item_code' => 'BASE-R',
    'unit' => 'set', 'weigh' => 0,
    'version' => 1, 'status' => 'pending', 'createtime' => $now, 'updatetime' => $now,
]);

$groupIds = [];
foreach ([
    ['code' => 'power_level', 'input_type' => 'single', 'affects_bom' => 1],
    ['code' => 'cooling_level', 'input_type' => 'single', 'affects_bom' => 0],
    ['code' => 'features', 'input_type' => 'multiple', 'affects_bom' => 1],
    ['code' => 'quantity', 'input_type' => 'number', 'affects_bom' => 0],
] as $group) {
    $groupIds[$group['code']] = (int)Db::name('cpq_option_group')->insertGetId([
        'code' => $group['code'], 'name' => $group['code'], 'name_en' => '',
        'input_type' => $group['input_type'], 'is_required' => 0, 'min_select' => 0, 'max_select' => 3,
        'affects_price' => 1, 'affects_bom' => $group['affects_bom'], 'affects_lead_time' => 0,
        'affects_weight' => 0, 'help_text' => '', 'sort' => 0, 'status' => 'normal',
        'createtime' => $now, 'updatetime' => $now,
    ]);
}

$optionIds = [];
foreach ([
    // power_level：选项自带物料编码 → 不算缺失映射
    ['group' => 'power_level', 'code' => 'standard', 'material_code' => 'MAT-POWER-STD', 'no_material' => 0],
    ['group' => 'power_level', 'code' => 'high', 'material_code' => 'MAT-POWER-HIGH', 'no_material' => 0],
    ['group' => 'cooling_level', 'code' => 'standard', 'material_code' => '', 'no_material' => 0],
    ['group' => 'cooling_level', 'code' => 'enhanced', 'material_code' => '', 'no_material' => 0],
    // features：monitoring 自带物料；offline 无物料无映射（缺失）；training 标记不产生物料
    ['group' => 'features', 'code' => 'monitoring', 'material_code' => 'MAT-MONITORING', 'no_material' => 0],
    ['group' => 'features', 'code' => 'offline', 'material_code' => '', 'no_material' => 0],
    ['group' => 'features', 'code' => 'training', 'material_code' => '', 'no_material' => 1],
] as $option) {
    $optionIds[$option['group'] . '.' . $option['code']] = (int)Db::name('cpq_option_value')->insertGetId([
        'group_id' => $groupIds[$option['group']], 'code' => $option['code'], 'name' => $option['code'],
        'name_en' => '', 'material_code' => $option['material_code'], 'default_qty' => 1,
        'min_qty' => 0, 'max_qty' => null, 'step' => 1, 'price_key' => '', 'cost_key' => '',
        'image' => '', 'parameter_json' => null, 'no_material' => $option['no_material'],
        'weigh' => 0, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
    ]);
}

foreach (['power_level', 'cooling_level', 'features', 'quantity'] as $sort => $code) {
    Db::name('cpq_model_option_group')->insert([
        'model_id' => $modelId, 'group_id' => $groupIds[$code], 'sort' => $sort,
        'is_visible' => 1, 'is_required' => 0, 'default_value' => null,
        'createtime' => $now, 'updatetime' => $now,
    ]);
}

// 发布型号与系列（型号发布要求可见配置组 + 选项；系列发布要求有效型号）
$modelRow = ProductModel::get($modelId);
$service->publish($modelRow);
check(Db::name('cpq_product_model')->where('id', $modelId)->value('status') === 'published', '型号发布成功');
$service->publish(ProductSeries::get($seriesId));
check(Db::name('cpq_product_series')->where('id', $seriesId)->value('status') === 'published', '系列发布成功');

$insertRule = function ($code, $condition, $actions, $severity = 'blocking') use ($modelId, $line, $now) {
    return (int)Db::name('cpq_config_rule')->insertGetId([
        'code' => $code, 'name' => $code, 'description' => '', 'type' => 'REQUIRES',
        'model_id' => $modelId, 'product_line' => $line,
        'condition_json' => json_encode($condition, JSON_UNESCAPED_UNICODE),
        'action_json' => json_encode($actions, JSON_UNESCAPED_UNICODE),
        'priority' => 0, 'severity' => $severity, 'message' => '', 'version' => 1,
        'effective_date' => null, 'expiry_date' => null, 'status' => 'pending',
        'createtime' => $now, 'updatetime' => $now,
    ]);
};

// ---------------------------------------------------------------------
// C-005：循环依赖 / 永真冲突 / 不可达选项 → 发布失败
// ---------------------------------------------------------------------
$cycle1 = $insertRule('CPQ-TEST-CYCLE-1',
    ['field' => 'configuration.power_level', 'operator' => '=', 'value' => 'high'],
    [['action' => 'set', 'target' => 'cooling_level', 'value' => 'enhanced']]);
$service->publish(ConfigRule::get($cycle1));
check(Db::name('cpq_config_rule')->where('id', $cycle1)->value('status') === 'published', '无环规则正常发布');

$cycle2 = $insertRule('CPQ-TEST-CYCLE-2',
    ['field' => 'configuration.cooling_level', 'operator' => '=', 'value' => 'enhanced'],
    [['action' => 'set', 'target' => 'power_level', 'value' => 'high']]);
$message = expectThrow(function () use ($service, $cycle2) {
    $service->publish(ConfigRule::get($cycle2));
}, 'C-005：形成循环依赖的新规则发布失败');
check(strpos($message, '循环依赖') !== false, 'C-005：失败原因明确指向循环依赖');
check(Db::name('cpq_config_rule')->where('id', $cycle2)->value('status') === 'pending', 'C-005：被拒绝的规则保持待审批状态');

$conflict1 = $insertRule('CPQ-TEST-CONF-1', [], [['action' => 'set', 'target' => 'quantity', 'value' => 1]]);
$service->publish(ConfigRule::get($conflict1));
$conflict2 = $insertRule('CPQ-TEST-CONF-2', [], [['action' => 'set', 'target' => 'quantity', 'value' => 2]]);
$message = expectThrow(function () use ($service, $conflict2) {
    $service->publish(ConfigRule::get($conflict2));
}, 'C-005：与已发布规则构成永真冲突的新规则发布失败');
check(strpos($message, '永真冲突') !== false, 'C-005：失败原因明确指向永真冲突');

$unreachable = $insertRule('CPQ-TEST-UNREACH', [],
    [['action' => 'exclude', 'target' => 'power_level', 'value' => 'high']]);
$message = expectThrow(function () use ($service, $unreachable) {
    $service->publish(ConfigRule::get($unreachable));
}, 'C-005：使已发布选项不可达的新规则发布失败');
check(strpos($message, '不可达') !== false, 'C-005：失败原因明确指向不可达选项');

$badDsl = $insertRule('CPQ-TEST-BAD-DSL',
    ['field' => 'configuration.power_level', 'operator' => '=', 'value' => 'high'],
    [['action' => 'eval', 'target' => 'quantity', 'value' => 'phpinfo()']]);
expectThrow(function () use ($service, $badDsl) {
    $service->publish(ConfigRule::get($badDsl));
}, 'DSL 白名单外的动作（eval）在发布期被拒绝');

// ---------------------------------------------------------------------
// C-006：规则版本更新后旧版本冻结，历史配置按旧快照可还原
// ---------------------------------------------------------------------
$snapshotRuleId = $insertRule('CPQ-TEST-SNAPSHOT',
    ['field' => 'configuration.power_level', 'operator' => '=', 'value' => 'high'],
    [['action' => 'require', 'target' => 'cooling_level', 'value' => 'enhanced']]);
$service->publish(ConfigRule::get($snapshotRuleId));

$repository = new ConfigurationSchemaRepository();
$configService = new ConfigurationService();
$historicalConfig = ['power_level' => 'high', 'cooling_level' => 'enhanced', 'quantity' => 2];

$schemaV1 = $repository->getPublishedSchema($modelId);
$snapshotV1 = null;
foreach ($schemaV1['rules'] as $rule) {
    if ($rule['code'] === 'CPQ-TEST-SNAPSHOT') {
        $snapshotV1 = $rule;
    }
}
check($snapshotV1 !== null && $snapshotV1['version'] === 1, 'C-006：schema 携带已发布规则的版本号（v1）');
$hashV1 = $configService->validate($schemaV1, $historicalConfig)['configuration_hash'];

// 复制新版本 → 修改动作 → 发布 v2（接替 v1）
$v2Data = $service->copyNewVersion(ConfigRule::get($snapshotRuleId));
$v2Id = (int)$v2Data['id'];
Db::name('cpq_config_rule')->where('id', $v2Id)->update([
    'action_json' => json_encode([['action' => 'require', 'target' => 'cooling_level', 'value' => 'standard']]),
    'status' => 'pending', 'updatetime' => time(),
]);
$frozenV1 = Db::name('cpq_config_rule')->where('id', $snapshotRuleId)->find();
$service->publish(ConfigRule::get($v2Id));

$afterV1 = Db::name('cpq_config_rule')->where('id', $snapshotRuleId)->find();
check($afterV1['status'] === 'expired', 'C-006：v2 发布后 v1 被接替为已失效');
check(
    $afterV1['condition_json'] === $frozenV1['condition_json']
    && $afterV1['action_json'] === $frozenV1['action_json']
    && (int)$afterV1['version'] === 1,
    'C-006：v1 规则行内容冻结（条件/动作/版本号不变）'
);
expectThrow(function () use ($service, $afterV1) {
    $service->assertEditable('cpq_config_rule', $afterV1);
}, 'C-006：已失效的历史规则版本不可直接编辑（版本冻结）');

$schemaV2 = $repository->getPublishedSchema($modelId);
$snapshotV2 = null;
foreach ($schemaV2['rules'] as $rule) {
    if ($rule['code'] === 'CPQ-TEST-SNAPSHOT') {
        $snapshotV2 = $rule;
    }
}
check($snapshotV2 !== null && $snapshotV2['version'] === 2, 'C-006：当前 schema 切换到 v2 规则');

// 历史快照还原：用保存的 v1 schema 重放历史配置，哈希不变
$replayV1 = $configService->validate($schemaV1, $historicalConfig);
check($replayV1['is_valid'] === true && $replayV1['configuration_hash'] === $hashV1, 'C-006：历史配置按 v1 快照还原，configuration_hash 不变');
$underV2 = $configService->validate($schemaV2, $historicalConfig);
check($underV2['is_valid'] === false, 'C-006：同一历史配置在 v2 规则下不再合法（快照语义成立）');

// ---------------------------------------------------------------------
// P20：BOM 缺失映射校验
// ---------------------------------------------------------------------
$schema = $repository->getPublishedSchema($modelId);
$missing = $configService->findMissingBomMappings($schema);
check(count($missing) === 1 && $missing[0]['option_code'] === 'offline', 'P20：无映射且无物料编码的选项被判定缺失映射');

// 标记不产生物料的选项不参与缺失判定（training 已在种子里标记）
$missingCodes = array_column($missing, 'option_code');
check(!in_array('training', $missingCodes, true), 'P20：明确标记不产生物料的选项免于校验');
check(!in_array('monitoring', $missingCodes, true) && !in_array('high', $missingCodes, true), 'P20：选项自带物料编码视为已映射');

// 补齐映射后缺失清零
$mappingId = (int)Db::name('cpq_bom_mapping')->insertGetId([
    'model_id' => $modelId, 'option_value_id' => $optionIds['features.offline'],
    'material_code' => 'MAT-OFFLINE', 'qty_formula' => '1', 'unit' => 'item',
    'substitute_material_code' => '', 'loss_rate' => 0, 'version' => 1,
    'status' => 'draft', 'createtime' => $now, 'updatetime' => $now,
]);
$service->publish(BomMapping::get($mappingId));
$missingAfter = $configService->findMissingBomMappings($repository->getPublishedSchema($modelId));
check($missingAfter === [], 'P20：发布 BOM 映射后缺失映射清零');

// ---------------------------------------------------------------------
// C-007 异常分支：未发布型号 / 非法配置被服务端拒绝
// ---------------------------------------------------------------------
$draftModelId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-TEST-MODEL-DRAFT', 'name' => '草稿型号',
    'name_en' => '', 'category_code' => 'EQUIPMENT', 'base_item_code' => 'BASE-D',
    'unit' => 'set', 'weigh' => 0,
    'version' => 1, 'status' => 'draft', 'createtime' => $now, 'updatetime' => $now,
]);
expectThrow(function () use ($repository, $draftModelId) {
    $repository->getPublishedSchema($draftModelId);
}, 'C-007：未发布型号的 schema 读取被拒绝');
expectThrow(function () use ($repository) {
    $repository->getPublishedSchema(999999);
}, 'C-007：不存在型号的 schema 读取被拒绝');

$bypass = $configService->validate($repository->getPublishedSchema($modelId), [
    'power_level' => 'not-a-real-option',
    'cooling_level' => 'standard',
    'quantity' => 2,
]);
$bypassCodes = array_column($bypass['errors'], 'code');
check($bypass['is_valid'] === false && in_array('CPQ_CONFIG_OPTION_INVALID', $bypassCodes, true), 'C-007：前端绕过提交非法选项被服务端拒绝');

echo "\n全部 {$assertCount} 项断言通过：rule engine tests: PASS\n";
