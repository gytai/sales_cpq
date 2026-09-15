<?php

/**
 * GYTAI-74 M2 前端静态契约测试。
 *
 * 浏览器测试负责交互；本文件确保所有页面入口存在，并守住两个不能依赖
 * 人工目测的边界：金额不经 JS 浮点计算、未授权敏感值不会由页面模板创建。
 */

$root = dirname(__DIR__, 2);
$assertions = 0;

function checkM2($condition, $message)
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
}

$pages = [
    'price_book', 'price_entry', 'price_policy', 'price_rule', 'pricing',
    'exchange_rate', 'tax_rule', 'fee_rule', 'release_version',
    'customer', 'customer_level', 'agent', 'agent_level', 'region',
    'sales_org', 'sales_org_member',
];
foreach ($pages as $page) {
    checkM2(is_file($root . '/application/admin/view/cpq/' . $page . '/index.html'), $page . ' 缺少 index 视图');
    checkM2(is_file($root . '/public/assets/js/backend/cpq/' . $page . '.js'), $page . ' 缺少 RequireJS 控制器');
}

$pricingJs = file_get_contents($root . '/public/assets/js/backend/cpq/pricing.js');
$pricingView = file_get_contents($root . '/application/admin/view/cpq/pricing/index.html');
$policyJs = file_get_contents($root . '/public/assets/js/backend/cpq/price_policy.js');
$policyForm = file_get_contents($root . '/application/admin/view/cpq/price_policy/form.html');
$priceEntryForm = file_get_contents($root . '/application/admin/view/cpq/price_entry/form.html');
$pricingController = file_get_contents($root . '/application/admin/controller/cpq/Pricing.php');
$policyController = file_get_contents($root . '/application/admin/controller/cpq/PricePolicy.php');
$priceEntryController = file_get_contents($root . '/application/admin/controller/cpq/PriceEntry.php');

checkM2(strpos($pricingJs, "Fast.api.ajax({url:'cpq/pricing/' + action") !== false, '模拟器未接后台定价接口');
checkM2(strpos($pricingJs, 'price_trace.steps') !== false, '模拟器未渲染规则命中顺序');
checkM2(strpos($pricingJs, 'lastSafeResult = result') !== false, '导出未绑定到服务端脱敏后的响应');
checkM2(!preg_match('/\b(parseFloat|toFixed|Math\.round|Number)\s*\(/', $pricingJs . $policyJs), '金额页面出现 JS 浮点计算');
checkM2(strpos($policyForm, '{if $canViewCost}') !== false, '成本字段未在服务端模板条件中隔离');
checkM2(strpos($policyForm, '{if $canViewCompanyFloor}') !== false, '公司控制价字段未在服务端模板条件中隔离');
checkM2(strpos($pricingController, 'maskForRoles') !== false, '模拟器响应未执行角色脱敏');
checkM2(strpos($policyController, 'maskRows') !== false, '价格矩阵列表未调用字段级脱敏');
checkM2(strpos($pricingController, 'SensitiveFieldService::rolesOfAdmin') !== false, '模拟器未从服务端会话解析角色');
checkM2(strpos($pricingJs, 'JSON.stringify(lastSafeResult') !== false, '安全轨迹导出未使用脱敏响应缓存');
// GYTAI-84：配置/加购不再要求操作员手写 JSON，改成交互控件
checkM2(strpos($pricingView, 'name="configuration"') === false && strpos($pricingView, 'name="accessories"') === false, '模拟器仍暴露配置/配件 JSON 文本域');
checkM2(strpos($pricingView, 'cpq-config-groups') !== false && strpos($pricingView, 'cpq-accessory-add') !== false, '模拟器缺少配置组/加购交互容器');
checkM2(strpos($pricingJs, 'cpq/pricing/context') !== false, '模拟器未从后台拉取交互配置上下文');
checkM2(strpos($pricingController, 'public function context()') !== false, '模拟器缺少交互配置上下文接口');
checkM2(strpos($priceEntryController, "post('q_word/a', [])") !== false, '定价对象下拉未按数组读取 selectpage 搜索词');
checkM2(substr_count($priceEntryController, '$applyFilter(Db::name($table))') === 2, '定价对象下拉的计数和列表未使用相同筛选条件');
checkM2(strpos($priceEntryForm, 'cpq/price_entry/selectbook') !== false, '价格条目表单未使用可维护价格表数据源');
checkM2(strpos($priceEntryController, "where('status', 'in', ['draft', 'pending'])") !== false, '价格表下拉未排除已发布和已失效版本');

echo 'M2 frontend contract tests: PASS (' . $assertions . " assertions)\n";
