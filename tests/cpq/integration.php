<?php

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\repository\cpq\ConfigurationSchemaRepository;
use app\common\service\cpq\ConfigurationService;

function expectIntegration($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$repository = new ConfigurationSchemaRepository();
$models = $repository->getPublishedModels();
expectIntegration(count($models) >= 1, '至少应存在一个已发布演示型号');

$schema = $repository->getPublishedSchema($models[0]['id']);
expectIntegration($schema['model']['code'] === 'CPQ-DEMO-EQUIPMENT-A', '应读取到演示型号');
expectIntegration(count($schema['groups']) === 5, '演示型号应包含五个配置组');
expectIntegration(count($schema['rules']) === 4, '演示型号应包含四条已发布规则');

$service = new ConfigurationService();
$validation = $service->validate($schema, [
    'power_level' => 'high',
    'cooling_level' => 'enhanced',
    'features' => ['monitoring'],
    'quantity' => 2,
]);
expectIntegration($validation['is_valid'] === true, '数据库配置方案应通过服务端校验');
expectIntegration($validation['configuration']['calculated_capacity'] === '5', '数据库公式规则应正确执行');
expectIntegration(count($validation['bom']) === 4, '数据库配置应生成完整BOM');

echo "CPQ database integration tests: PASS\n";
