<?php
/**
 * M1 配置规则静态分析与 DSL 安全测试（GYTAI-66）。
 *
 * 纯单元测试（不依赖数据库），覆盖：
 *  - 受控 JSON DSL 白名单：未知动作/比较符/公式操作、非法字段字符、
 *    脚本式内容一律在保存与发布前被拒绝（严禁 eval / 动态 SQL）；
 *  - 循环依赖检测（C-005）：直接环、间接环、无环通过；
 *  - 永真冲突检测：set 互斥、require vs exclude、强制值被排除、min>max；
 *  - 不可达选项检测：永真 exclude / hide；带条件的规则不误报；
 *  - 性能目标：500ms 内完成一次完整配置校验（方案性能目标）。
 *
 * 用法：php tests/cpq/rule_analysis.php（或 composer test:cpq-rules）
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);

use app\common\library\cpq\RuleAnalyzer;
use app\common\library\cpq\RuleDsl;
use app\common\service\cpq\ConfigurationService;

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

function expectReject(callable $fn, $message)
{
    global $assertCount;
    $assertCount++;
    try {
        $fn();
    } catch (InvalidArgumentException $exception) {
        echo "[ok] {$message}（异常：{$exception->getMessage()}）\n";
        return;
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出 InvalidArgumentException 但未抛出');
}

// ---------------------------------------------------------------------
// 1. DSL 白名单与安全边界
// ---------------------------------------------------------------------
$demoCondition = '{"all":[{"field":"configuration.power_level","operator":"=","value":"high"},{"field":"context.temperature","operator":">","value":40}]}';
check(RuleDsl::assertConditionJson($demoCondition) !== [], '合法嵌套条件（all + 比较符）通过校验');
check(RuleDsl::assertConditionJson('{}') === [], '空条件对象表示恒真');
check(RuleDsl::assertActionJson('[{"action":"require","target":"cooling_level","value":"enhanced"}]') !== [], '合法 require 动作通过校验');
check(RuleDsl::assertActionJson('{"action":"min_max","target":"quantity","min":1,"max":10}') !== [], '单个动作对象自动规范化为数组');

expectReject(function () {
    RuleDsl::assertConditionJson('not-json');
}, '非法 JSON 条件被拒绝');
expectReject(function () {
    RuleDsl::assertConditionJson('{"field":"configuration.a","operator":"LIKE","value":"%"}');
}, '白名单外比较符（SQL 风格 LIKE）被拒绝');
expectReject(function () {
    RuleDsl::assertConditionJson('{"field":"configuration.a;DROP TABLE","operator":"=","value":1}');
}, '条件字段含注入式字符被拒绝');
expectReject(function () {
    RuleDsl::assertActionJson('[{"action":"eval","target":"x","value":"phpinfo()"}]');
}, 'eval 动作被拒绝（严禁执行用户代码）');
expectReject(function () {
    RuleDsl::assertActionJson('[{"action":"set","target":"x\";system(\'id\');//","value":1}]');
}, '目标含注入式字符被拒绝');
expectReject(function () {
    RuleDsl::assertActionJson('[{"action":"formula","target":"x","operation":"pow","operands":[{"value":2}]}]');
}, '白名单外公式操作被拒绝');
expectReject(function () {
    RuleDsl::assertActionJson('[{"action":"formula","target":"x","operation":"sum","operands":[]}]');
}, '公式缺操作数被拒绝');
expectReject(function () {
    RuleDsl::assertActionJson('[{"action":"min_max","target":"quantity","min":10,"max":1}]');
}, 'min_max 的 min>max 在保存期被拒绝');
expectReject(function () {
    RuleDsl::assertActionJson('[{"action":"one_of","target":"features","value":[]}]');
}, 'one_of 空候选集被拒绝');
expectReject(function () {
    RuleDsl::assertActionJson('[{"action":"require"}]');
}, '动作缺少 target 被拒绝');
expectReject(function () {
    RuleDsl::assertScope(0, '');
}, '适用范围为空被拒绝');

// ---------------------------------------------------------------------
// 2. 循环依赖检测（C-005）
// ---------------------------------------------------------------------
$acyclic = [
    ['code' => 'R-A', 'condition' => ['field' => 'configuration.power_level', 'operator' => '=', 'value' => 'high'],
        'actions' => [['action' => 'require', 'target' => 'cooling_level', 'value' => 'enhanced']]],
    ['code' => 'R-B', 'condition' => [],
        'actions' => [['action' => 'formula', 'target' => 'calculated_capacity', 'operation' => 'multiply', 'operands' => [['field' => 'configuration.quantity'], ['value' => 2]]]]],
];
check(RuleAnalyzer::analyze($acyclic)['cycles'] === [], '无环规则集通过检测');

$directCycle = [
    ['code' => 'R-CYCLE-1', 'condition' => ['field' => 'configuration.a', 'operator' => '=', 'value' => 'x'],
        'actions' => [['action' => 'set', 'target' => 'b', 'value' => 'y']]],
    ['code' => 'R-CYCLE-2', 'condition' => ['field' => 'configuration.b', 'operator' => '=', 'value' => 'y'],
        'actions' => [['action' => 'set', 'target' => 'a', 'value' => 'x']]],
];
$cycles = RuleAnalyzer::analyze($directCycle)['cycles'];
check(count($cycles) === 1, '直接循环依赖被检测（A↔B）');
check($cycles[0]['rule_codes'] === ['R-CYCLE-1', 'R-CYCLE-2'], '环报告涉及的两条规则编码');

$indirectCycle = [
    ['code' => 'R-I-1', 'condition' => ['field' => 'configuration.a', 'operator' => '=', 'value' => '1'],
        'actions' => [['action' => 'default', 'target' => 'b', 'value' => '2']]],
    ['code' => 'R-I-2', 'condition' => ['field' => 'configuration.b', 'operator' => '=', 'value' => '2'],
        'actions' => [['action' => 'formula', 'target' => 'c', 'operation' => 'sum', 'operands' => [['value' => 1]]]]],
    ['code' => 'R-I-3', 'condition' => ['all' => [
        ['field' => 'configuration.c', 'operator' => 'not_empty'],
        ['field' => 'context.env', 'operator' => '=', 'value' => 'prod'],
    ]], 'actions' => [['action' => 'set', 'target' => 'a', 'value' => '1']]],
];
$indirectCycles = RuleAnalyzer::analyze($indirectCycle)['cycles'];
check(count($indirectCycles) === 1, '间接循环依赖被检测（A→B→C→A）');
check(count($indirectCycles[0]['rule_codes']) === 3, '环报告全部三条规则编码');

expectReject(function () use ($directCycle) {
    RuleAnalyzer::assertPublishable($directCycle);
}, 'assertPublishable 对循环规则集抛出异常');

// ---------------------------------------------------------------------
// 3. 永真冲突检测
// ---------------------------------------------------------------------
$conflictSet = [
    ['code' => 'R-SET-1', 'condition' => [], 'actions' => [['action' => 'set', 'target' => 'mode', 'value' => 'auto']]],
    ['code' => 'R-SET-2', 'condition' => [], 'actions' => [['action' => 'set', 'target' => 'mode', 'value' => 'manual']]],
];
check(RuleAnalyzer::analyze($conflictSet)['conflicts'] !== [], '永真 set 同一目标不同值被判冲突');

$harmonySet = [
    ['code' => 'R-SET-3', 'condition' => [], 'actions' => [['action' => 'set', 'target' => 'mode', 'value' => 'auto']]],
    ['code' => 'R-SET-4', 'condition' => [], 'actions' => [['action' => 'set', 'target' => 'mode', 'value' => 'auto']]],
];
check(RuleAnalyzer::analyze($harmonySet)['conflicts'] === [], '永真 set 同一目标相同值不判冲突');

$requireVsExclude = [
    ['code' => 'R-REQ', 'condition' => [], 'actions' => [['action' => 'require', 'target' => 'cooling_level', 'value' => 'enhanced']]],
    ['code' => 'R-EXC', 'condition' => [], 'actions' => [['action' => 'exclude', 'target' => 'cooling_level', 'value' => 'enhanced']]],
];
check(RuleAnalyzer::analyze($requireVsExclude)['conflicts'] !== [], '永真 require 与永真 exclude 同值被判冲突');

$forcedExcluded = [
    ['code' => 'R-FORCE', 'condition' => [], 'actions' => [['action' => 'set', 'target' => 'power_level', 'value' => 'high']]],
    ['code' => 'R-BAN', 'condition' => [], 'actions' => [['action' => 'exclude', 'target' => 'power_level', 'value' => 'high']]],
];
check(RuleAnalyzer::analyze($forcedExcluded)['conflicts'] !== [], '永真强制值被永真排除被判冲突');

$conditionalNoConflict = [
    ['code' => 'R-COND-REQ', 'condition' => ['field' => 'configuration.a', 'operator' => '=', 'value' => 'x'],
        'actions' => [['action' => 'require', 'target' => 'cooling_level', 'value' => 'enhanced']]],
    ['code' => 'R-COND-EXC', 'condition' => ['field' => 'configuration.a', 'operator' => '=', 'value' => 'y'],
        'actions' => [['action' => 'exclude', 'target' => 'cooling_level', 'value' => 'enhanced']]],
];
check(RuleAnalyzer::analyze($conditionalNoConflict)['conflicts'] === [], '带条件的 require/exclude 不参与永真冲突判定');

$selfContradict = [
    ['code' => 'R-BAD-RANGE', 'condition' => ['field' => 'configuration.a', 'operator' => '=', 'value' => 'x'],
        'actions' => [['action' => 'min_max', 'target' => 'quantity', 'min' => 10, 'max' => 1]]],
];
check(RuleAnalyzer::analyze($selfContradict)['conflicts'] !== [], 'min>max 永假范围被判冲突（无论条件是否为空）');

// ---------------------------------------------------------------------
// 4. 不可达选项检测
// ---------------------------------------------------------------------
$groups = [
    ['code' => 'power_level', 'input_type' => 'single', 'options' => [['code' => 'standard'], ['code' => 'high']]],
    ['code' => 'features', 'input_type' => 'multiple', 'options' => [['code' => 'remote'], ['code' => 'offline']]],
];

$unreachableOption = [
    ['code' => 'R-KILL', 'condition' => [], 'severity' => 'blocking',
        'actions' => [['action' => 'exclude', 'target' => 'power_level', 'value' => 'high']]],
];
$unreachable = RuleAnalyzer::analyze($unreachableOption, $groups)['unreachable'];
check(count($unreachable) === 1 && strpos($unreachable[0], 'power_level.high') !== false, '永真 exclude 使选项不可达');

$unreachableGroup = [
    ['code' => 'R-HIDE', 'condition' => [], 'severity' => 'blocking',
        'actions' => [['action' => 'hide', 'target' => 'features']]],
];
check(RuleAnalyzer::analyze($unreachableGroup, $groups)['unreachable'] !== [], '永真 hide 使整组不可达');

$conditionalExclude = [
    ['code' => 'R-COND-KILL', 'condition' => ['field' => 'configuration.power_level', 'operator' => '=', 'value' => 'high'],
        'severity' => 'blocking', 'actions' => [['action' => 'exclude', 'target' => 'features', 'value' => 'offline']]],
];
check(RuleAnalyzer::analyze($conditionalExclude, $groups)['unreachable'] === [], '带条件的 exclude 不误报不可达');

$warningExclude = [
    ['code' => 'R-WARN-EXC', 'condition' => [], 'severity' => 'warning',
        'actions' => [['action' => 'exclude', 'target' => 'power_level', 'value' => 'high']]],
];
check(RuleAnalyzer::analyze($warningExclude, $groups)['unreachable'] === [], 'warning 级别永真 exclude 不判不可达');

// ---------------------------------------------------------------------
// 5. 性能目标：一次完整校验 < 500ms
// ---------------------------------------------------------------------
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

$service = new ConfigurationService();
$startedAt = microtime(true);
$bigResult = $service->validate($bigSchema, $bigInput);
$elapsedMs = (microtime(true) - $startedAt) * 1000;
check($bigResult['is_valid'] === true, '大规模配置（60 组 / 240 规则）校验通过');
check($elapsedMs < 500, sprintf('性能目标：一次完整校验 %.1fms < 500ms', $elapsedMs));

echo "\n全部 {$assertCount} 项断言通过：rule analysis tests: PASS\n";
