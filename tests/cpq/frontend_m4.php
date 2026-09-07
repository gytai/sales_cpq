<?php
/**
 * GYTAI-78 M4 驾驶舱、报表和系统管理前端静态契约测试。
 *
 * 用法：docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/frontend_m4.php
 */

$root = dirname(__DIR__, 2);
$assertions = 0;

function checkM4Frontend($condition, $message)
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
}

function controllerNameM4($page)
{
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $page)));
}

$pages = [
    'dashboard',
    'report_quote',
    'report_pricing',
    'report_approval',
    'report_configuration',
    'report_export',
    'data_scope',
    'dictionary',
    'number_rule',
    'integration_config',
    'job',
    'audit_log',
];

foreach ($pages as $page) {
    checkM4Frontend(
        is_file($root . '/application/admin/controller/cpq/' . controllerNameM4($page) . '.php'),
        '缺少控制器 ' . $page
    );
    checkM4Frontend(
        is_file($root . '/application/admin/view/cpq/' . $page . '/index.html'),
        '缺少视图 ' . $page . '/index.html'
    );
    checkM4Frontend(
        is_file($root . '/public/assets/js/backend/cpq/' . $page . '.js'),
        '缺少页面脚本 ' . $page . '.js'
    );
}

checkM4Frontend(
    is_file($root . '/public/assets/js/backend/cpq/report_common.js'),
    '缺少报表共享筛选与下钻模块'
);

$dashboardView = file_get_contents($root . '/application/admin/view/cpq/dashboard/index.html');
$dashboardJs = file_get_contents($root . '/public/assets/js/backend/cpq/dashboard.js');
$reportCommon = file_get_contents($root . '/public/assets/js/backend/cpq/report_common.js');
$install = file_get_contents($root . '/application/common/service/cpq/MenuRuleService.php');

foreach (['company', 'sales_org_id', 'product_line', 'region_id', 'currency', 'created_from', 'created_to'] as $filter) {
    checkM4Frontend(
        strpos($dashboardView . $dashboardJs . $reportCommon, $filter) !== false,
        '驾驶舱缺少统一筛选 ' . $filter
    );
}
checkM4Frontend(
    strpos($reportCommon, 'canonicalFilters') !== false
        && strpos($reportCommon, 'drilldownUrl') !== false,
    '报表下钻未复用规范化筛选对象'
);
checkM4Frontend(
    strpos($dashboardJs, "performance.mark('cpq-first-screen-ready')") !== false
        && strpos($dashboardJs, 'window.__CPQ_FIRST_SCREEN_READY__ = true') !== false,
    '驾驶舱缺少业务首屏完成标记'
);

$reportJs = $reportCommon;
foreach (['dashboard', 'report_quote', 'report_pricing', 'report_approval', 'report_configuration', 'report_export'] as $page) {
    $script = file_get_contents($root . '/public/assets/js/backend/cpq/' . $page . '.js');
    $reportJs .= $script;
    checkM4Frontend(
        strpos($script, 'backend/cpq/report_common') !== false,
        $page . ' 未复用共享筛选模块'
    );
}
checkM4Frontend(
    !preg_match('/\b(parseFloat|toFixed|Math\.round|Number)\s*\(/', $reportJs),
    'M4 报表页面出现 JavaScript 浮点金额计算'
);
checkM4Frontend(
    strpos(file_get_contents($root . '/public/assets/js/backend/cpq/report_export.js'), "type: 'POST'") !== false,
    'P94 导出必须通过 POST 创建异步任务'
);

// 图表 tooltip 统一走共享转义 formatter（防图表数据注入 HTML）
checkM4Frontend(
    strpos($reportCommon, 'function tooltipFormatter') !== false,
    '报表共享模块缺少安全 tooltip formatter'
);
foreach (['dashboard', 'report_approval', 'report_configuration'] as $page) {
    $script = file_get_contents($root . '/public/assets/js/backend/cpq/' . $page . '.js');
    checkM4Frontend(
        strpos($script, 'ReportCommon.tooltipFormatter') !== false,
        $page . ' 图表 tooltip 未使用共享转义 formatter'
    );
}

// P91 毛利报表：敏感角色查看必须写资源级审计
$pricingController = file_get_contents($root . '/application/admin/controller/cpq/ReportPricing.php');
checkM4Frontend(
    strpos($pricingController, 'recordAccess') !== false
        && strpos($pricingController, 'cpq_report_pricing') !== false,
    'P91 毛利报表敏感角色查看必须写资源级审计'
);

foreach (['cpq/dashboard', 'cpq/report_quote', 'cpq/report_pricing', 'cpq/report_approval',
    'cpq/report_configuration', 'cpq/report_export', 'cpq/data_scope', 'cpq/dictionary',
    'cpq/number_rule', 'cpq/integration_config', 'cpq/job', 'cpq/audit_log'] as $menu) {
    checkM4Frontend(strpos($install, "'" . $menu . "'") !== false, '菜单未注册 ' . $menu);
}

$credentialService = file_get_contents($root . '/application/common/service/cpq/IntegrationCredentialService.php');
foreach (['credential_ciphertext', 'credential_nonce', 'credential_tag', 'credential_key_version'] as $field) {
    checkM4Frontend(
        preg_match('/unset\s*\([^;]*\$row\[\'' . preg_quote($field, '/') . '\'\]/s', $credentialService) === 1,
        '接口公开响应未移除 ' . $field
    );
}
$integrationView = file_get_contents($root . '/application/admin/view/cpq/integration_config/index.html');
$integrationJs = file_get_contents($root . '/public/assets/js/backend/cpq/integration_config.js');
checkM4Frontend(
    strpos($integrationView . $integrationJs, '重置凭证') !== false
        && strpos($integrationView . $integrationJs, '查看原凭证') === false,
    '接口管理必须只允许重置凭证且不可查看原凭证'
);

$auditController = file_get_contents($root . '/application/admin/controller/cpq/AuditLog.php');
checkM4Frontend(
    !preg_match('/public function (add|edit|del|multi|destroy|restore)\s*\(/', $auditController),
    '审计控制器不得暴露写接口'
);
$auditView = file_get_contents($root . '/application/admin/view/cpq/audit_log/index.html');
checkM4Frontend(
    !preg_match('/btn-(add|edit|del)|data-operate-(add|edit|del)/', $auditView),
    '审计页面不得暴露新增、编辑或删除操作'
);

foreach (['frontend_m4.php', 'm4_reporting.php', 'browser/m4_check.js'] as $test) {
    checkM4Frontend(is_file($root . '/tests/cpq/' . $test), '缺少 M4 测试 ' . $test);
}

echo "frontend_m4: {$assertions} assertions passed.\n";
