<?php
/**
 * GYTAI-75 M3 待办/审批/模板/打印前端静态契约测试。
 *
 * 浏览器测试负责交互；本文件守住不能依赖人工目测的边界：
 * 页面入口齐全、金额不经 JS 浮点计算、审批动作白名单（仅批准/驳回/退回/加签/转交）、
 * 审批动作携带幂等键、详情 JSON 内嵌转义（XSS）、敏感字段由服务端脱敏后下发、
 * 菜单权限节点已注册、演示数据含审批规则/默认模板/职责分离的演示审批人。
 *
 * 用法：docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/frontend_m3.php
 */

$root = dirname(__DIR__, 2);
$assertions = 0;

function checkM3($condition, $message)
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
}

// 1. 页面入口与脚本（P02/P59/P60/P70-P75）
$pages = [
    'todo' => ['index'],
    'approval_task' => ['index', 'detail'],
    'approval_instance' => ['index', 'flow'],
    'approval_record' => ['index'],
    'approval_rule' => ['index', 'add', 'edit', 'form'],
    'approval_delegation' => ['index', 'actions'],
    'quote_template' => ['index', 'add', 'edit', 'form'],
    'quote_document' => ['index'],
];
foreach ($pages as $page => $views) {
    checkM3(is_file($root . '/application/admin/controller/cpq/' . str_replace(' ', '', ucwords(str_replace('_', ' ', $page))) . '.php'),
        '缺少控制器 ' . $page);
    foreach ($views as $view) {
        checkM3(is_file($root . '/application/admin/view/cpq/' . $page . '/' . $view . '.html'), $page . ' 缺少视图 ' . $view);
    }
    checkM3(is_file($root . '/public/assets/js/backend/cpq/' . $page . '.js'), '缺少页面脚本 ' . $page . '.js');
}

$install = file_get_contents($root . '/application/common/service/cpq/MenuRuleService.php');
$demo = file_get_contents($root . '/database/cpq/demo.sql');
$jsAll = '';
foreach (array_keys($pages) as $page) {
    $jsAll .= file_get_contents($root . '/public/assets/js/backend/cpq/' . $page . '.js');
}
$detailJs = file_get_contents($root . '/public/assets/js/backend/cpq/approval_task.js');
$detailView = file_get_contents($root . '/application/admin/view/cpq/approval_task/detail.html');
$detailService = file_get_contents($root . '/application/common/service/cpq/ApprovalService.php');

// 2. 菜单权限节点（P02/P59/P60/P70-P75 + 操作节点）
foreach (['cpq/todo', 'cpq/approval_task', 'cpq/approval_instance', 'cpq/approval_record',
    'cpq/approval_rule', 'cpq/approval_delegation', 'cpq/quote_template', 'cpq/quote_document'] as $menu) {
    checkM3(strpos($install, "'" . $menu . "'") !== false, '菜单未注册 ' . $menu);
}
foreach (['batchtransfer', 'candidates', 'simulate', 'setdefault', 'verify', 'download', 'generate'] as $action) {
    checkM3(strpos($install, "'" . $action . "'") !== false, '菜单缺少操作节点 ' . $action);
}

// 3. 金额不经 JS 浮点运算（审批/待办/模板页面金额只展示服务端 Decimal 字符串）
checkM3(!preg_match('/\b(parseFloat|toFixed|Math\.round|Number)\s*\(/',
    file_get_contents($root . '/public/assets/js/backend/cpq/todo.js')
    . file_get_contents($root . '/public/assets/js/backend/cpq/approval_task.js')
    . file_get_contents($root . '/public/assets/js/backend/cpq/approval_instance.js')
    . file_get_contents($root . '/public/assets/js/backend/cpq/approval_record.js')
    . file_get_contents($root . '/public/assets/js/backend/cpq/approval_delegation.js')
    . file_get_contents($root . '/public/assets/js/backend/cpq/quote_template.js')),
    'M3 审批/待办页面出现 JS 浮点计算');

// 4. 审批动作白名单：前端只有批准/驳回/退回/加签/转交，且无报价编辑入口
checkM3(strpos($detailView, 'data-action="approve"') !== false
    && strpos($detailView, 'data-action="reject"') !== false
    && strpos($detailView, 'data-action="return"') !== false
    && strpos($detailView, 'data-action="add_sign"') !== false
    && strpos($detailView, 'data-action="transfer"') !== false, '审批详情缺少五个动作按钮');
checkM3(strpos($detailJs, 'cpq/quote/wizard') === false && strpos($detailJs, 'cpq/quote/save') === false,
    '审批详情页不得提供报价编辑/保存入口');
checkM3(strpos($detailJs, 'idempotency_key') !== false && strpos($detailJs, 'genActionKey') !== false,
    '审批动作缺少幂等键');
checkM3(strpos($detailJs, 'can_act') !== false && strpos($detailJs, 'version_stale') !== false,
    '审批详情未按服务端 can_act/version_stale 控制操作区');

// 5. 敏感字段：详情数据由服务端 maskForRoles 脱敏后内嵌，JSON 以 HEX 标记转义防 XSS
checkM3(strpos($detailService, 'maskForRoles') !== false, '审批详情未按角色脱敏');
$detailController = file_get_contents($root . '/application/admin/controller/cpq/ApprovalTask.php');
checkM3(strpos($detailController, 'JSON_HEX_TAG') !== false && strpos($detailView, '__CPQ_APPROVAL__') !== false,
    '审批详情 JSON 内嵌未做 HEX 转义');

// 6. SLA 与版本失效提示
checkM3(strpos($jsAll, 'slaFormatter') !== false && strpos($jsAll, '已超时') !== false, '缺少 SLA 超时提示');
checkM3(strpos($detailJs, '报价版本已变化') !== false, '缺少版本变化任务失效提示');
checkM3(strpos(file_get_contents($root . '/public/assets/js/backend/cpq/quote_document.js'), 'setTimeout') !== false,
    '打印记录缺少排队/生成中自动轮询');

// 7. 待办分类与批量批准默认禁用
$todoView = file_get_contents($root . '/application/admin/view/cpq/todo/index.html');
foreach (['pending', 'processed', 'initiated', 'cc'] as $category) {
    checkM3(strpos($todoView, 'data-category="' . $category . '"') !== false, '待办缺少分类 ' . $category);
}
checkM3(strpos($todoView, 'btn disabled') !== false || strpos($todoView, ' disabled') !== false, '待办页批量批准按钮应默认禁用');
checkM3(strpos(file_get_contents($root . '/public/assets/js/backend/cpq/todo.js'), 'batchtransfer') !== false, '待办缺少批量转交');

// 8. 模板结构化编辑与变量白名单
$templateForm = file_get_contents($root . '/application/admin/view/cpq/quote_template/form.html');
$templateJs = file_get_contents($root . '/public/assets/js/backend/cpq/quote_template.js');
checkM3(strpos($templateForm, '__CPQ_TEMPLATE_VARIABLES__') !== false, '模板表单未下发变量白名单');
checkM3(strpos($templateJs, 'content_json') !== false && strpos($templateJs, 'assemble') !== false,
    '模板表单缺少结构化组装 content_json');
checkM3(strpos($templateJs, 'cpq/quote_template/preview') !== false, '模板缺少预览');

// 9. 打印记录：哈希验证/受控下载/失败重试
$documentJs = file_get_contents($root . '/public/assets/js/backend/cpq/quote_document.js');
foreach (['cpq/quote_document/verify', 'cpq/quote_document/download', 'cpq/quote_document/retry', 'cpq/quote_document/generate'] as $endpoint) {
    checkM3(strpos($documentJs, $endpoint) !== false, '打印记录未接 ' . $endpoint);
}

// 10. 演示数据：审批规则（产线/公司）、中英文默认模板、职责分离的演示审批人
foreach (['CPQ-DEMO-APR-LINE', 'CPQ-DEMO-APR-COMPANY', 'CPQ-DEMO-QT-ZH', 'CPQ-DEMO-QT-EN', 'cpq_approver', 'cpq_approver2'] as $seed) {
    checkM3(strpos($demo, $seed) !== false, '演示数据缺少 ' . $seed);
}

// 11. 服务级测试与浏览器脚本存在
checkM3(is_file($root . '/tests/cpq/m3_approval.php'), '缺少 M3 服务级测试');
checkM3(is_file($root . '/tests/cpq/browser/m3_approval_check.js'), '缺少 M3 浏览器验收脚本');

echo "frontend_m3: {$assertions} assertions passed.\n";
