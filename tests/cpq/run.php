<?php

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';
require APP_PATH . 'common' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'cpq' . DIRECTORY_SEPARATOR . 'ConfigurationService.php';

use app\common\service\cpq\ConfigurationService;

function expectTrue($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function issueCodes(array $issues)
{
    return array_column($issues, 'code');
}

$schema = [
    'model' => [
        'code' => 'CPQ-DEMO-EQUIPMENT-A',
        'version' => 1,
        'base_item_code' => 'BASE-A',
        'unit' => 'set',
    ],
    'groups' => [
        [
            'code' => 'power_level',
            'name' => '功率等级',
            'input_type' => 'single',
            'is_required' => 1,
            'default_value' => 'standard',
            'options' => [
                ['id' => 101, 'code' => 'standard', 'material_code' => 'POWER-STANDARD', 'default_qty' => '1'],
                ['id' => 102, 'code' => 'high', 'material_code' => 'POWER-HIGH', 'default_qty' => '1'],
            ],
        ],
        [
            'code' => 'cooling_level',
            'name' => '散热等级',
            'input_type' => 'single',
            'is_required' => 0,
            'options' => [
                ['code' => 'standard', 'material_code' => 'COOL-STANDARD', 'default_qty' => '1'],
                ['code' => 'enhanced', 'material_code' => 'COOL-ENHANCED', 'default_qty' => '1'],
            ],
        ],
        [
            'code' => 'features',
            'name' => '功能模块',
            'input_type' => 'multiple',
            'is_required' => 1,
            'min_select' => 1,
            'max_select' => 2,
            'options' => [
                ['code' => 'monitoring', 'material_code' => 'FEATURE-MONITORING', 'default_qty' => '1'],
                ['code' => 'remote', 'material_code' => 'FEATURE-REMOTE', 'default_qty' => '1'],
                ['code' => 'offline', 'material_code' => 'FEATURE-OFFLINE', 'default_qty' => '1'],
            ],
        ],
        [
            'code' => 'quantity',
            'name' => '设备数量',
            'input_type' => 'number',
            'is_required' => 1,
        ],
        [
            'code' => 'calculated_capacity',
            'name' => '计算容量',
            'input_type' => 'readonly',
            'is_required' => 0,
        ],
    ],
    'rules' => [
        [
            'code' => 'REQUIRE-COOLING',
            'priority' => 100,
            'severity' => 'blocking',
            'message' => '高功率型号必须配置增强散热',
            'condition' => ['field' => 'configuration.power_level', 'operator' => '=', 'value' => 'high'],
            'actions' => [['action' => 'require', 'target' => 'cooling_level', 'value' => 'enhanced']],
        ],
        [
            'code' => 'EXCLUDE-OFFLINE',
            'priority' => 90,
            'severity' => 'blocking',
            'message' => '远程模块与离线模块不能同时选择',
            'condition' => ['field' => 'configuration.features', 'operator' => 'contains', 'value' => 'remote'],
            'actions' => [['action' => 'exclude', 'target' => 'features', 'value' => 'offline']],
        ],
        [
            'code' => 'QUANTITY-RANGE',
            'priority' => 80,
            'severity' => 'blocking',
            'message' => '设备数量必须在1到10之间',
            'condition' => [],
            'actions' => [['action' => 'min_max', 'target' => 'quantity', 'min' => 1, 'max' => 10]],
        ],
        [
            'code' => 'CAPACITY-FORMULA',
            'priority' => 70,
            'severity' => 'blocking',
            'condition' => [],
            'actions' => [[
                'action' => 'formula',
                'target' => 'calculated_capacity',
                'operation' => 'multiply',
                'operands' => [
                    ['field' => 'configuration.quantity'],
                    ['value' => 2.5],
                ],
            ]],
        ],
        [
            'code' => 'HIGH-POWER-WARNING',
            'priority' => 60,
            'severity' => 'warning',
            'message' => '高功率配置需要确认现场供电条件',
            'condition' => ['field' => 'configuration.power_level', 'operator' => '=', 'value' => 'high'],
            'actions' => [['action' => 'warning', 'target' => 'power_level']],
        ],
    ],
];

$service = new ConfigurationService();

$valid = $service->validate($schema, [
    'power_level' => 'high',
    'cooling_level' => 'enhanced',
    'features' => ['monitoring', 'remote'],
    'quantity' => 2,
    'unknown_field' => 'ignored',
]);
expectTrue($valid['is_valid'] === true, '合法配置应通过校验');
expectTrue($valid['configuration']['calculated_capacity'] === '5', '受控公式应生成只读计算值');
expectTrue(!isset($valid['configuration']['unknown_field']), '未知字段必须被移除');
expectTrue(count($valid['warnings']) === 1, '高功率配置应产生一条警告');
expectTrue(count($valid['bom']) === 5, '合法配置应生成基础物料和四条选项物料');

$sameConfiguration = $service->validate($schema, [
    'quantity' => '2.0000',
    'features' => ['remote', 'monitoring'],
    'cooling_level' => 'enhanced',
    'power_level' => 'high',
]);
expectTrue($valid['configuration_hash'] === $sameConfiguration['configuration_hash'], '等价配置必须生成相同哈希');

$integerWithTrailingZeros = $service->validate($schema, [
    'power_level' => 'standard',
    'features' => ['monitoring'],
    'quantity' => '1000',
]);
expectTrue($integerWithTrailingZeros['configuration']['quantity'] === '1000', '整数末尾的零不得被截断');

$scientificNotation = $service->validate($schema, [
    'power_level' => 'standard',
    'features' => ['monitoring'],
    'quantity' => '1e3',
]);
expectTrue($scientificNotation['configuration']['quantity'] === '1000', '科学计数法必须精确规范化');

$mappedSchema = $schema;
$mappedSchema['bom_mappings'] = [
    ['id' => 201, 'option_value_id' => null, 'material_code' => 'FRAME-A', 'qty_formula' => '1', 'unit' => 'set', 'loss_rate' => '0'],
    ['id' => 202, 'option_value_id' => 102, 'material_code' => 'POWER-MODULE', 'qty_formula' => '({configuration.quantity} * 2) + 1', 'unit' => 'item', 'loss_rate' => '0.1'],
];
$mapped = $service->validate($mappedSchema, [
    'power_level' => 'high',
    'cooling_level' => 'enhanced',
    'features' => ['monitoring'],
    'quantity' => '2',
]);
expectTrue(count($mapped['bom']) === 2, '存在已发布映射时必须按映射生成 BOM');
expectTrue($mapped['bom'][1]['quantity'] === '5.5', 'BOM 数量公式和损耗率必须精确计算');

$invalid = $service->validate($schema, [
    'power_level' => 'high',
    'features' => ['remote', 'offline', 'monitoring'],
    'quantity' => 11,
]);
$invalidCodes = issueCodes($invalid['errors']);
expectTrue($invalid['is_valid'] === false, '非法配置必须被阻止');
expectTrue(in_array('CPQ_CONFIG_REQUIRES', $invalidCodes, true), '依赖规则必须生效');
expectTrue(in_array('CPQ_CONFIG_EXCLUDES', $invalidCodes, true), '互斥规则必须生效');
expectTrue(in_array('CPQ_CONFIG_MAX_SELECT', $invalidCodes, true), '多选上限必须生效');
expectTrue(in_array('CPQ_CONFIG_MIN_MAX', $invalidCodes, true), '数值范围规则必须生效');

$missing = $service->validate($schema, ['quantity' => 1]);
expectTrue($missing['configuration']['power_level'] === 'standard', '默认值必须自动填充');
expectTrue(in_array('CPQ_CONFIG_REQUIRED', issueCodes($missing['errors']), true), '必选配置组必须校验');

echo "ConfigurationService tests: PASS\n";
