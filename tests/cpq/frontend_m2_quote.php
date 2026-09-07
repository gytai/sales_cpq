<?php

/**
 * GYTAI-70 M2 报价向导前端静态契约测试。
 *
 * 浏览器测试负责交互；本文件确保页面入口存在，并守住不能依赖人工目测的边界：
 * 金额不经 JS 浮点计算、试算/提交全部走后端重算、试算响应按角色脱敏、
 * 后端错误可回到对应步骤、历史版本只读、菜单权限节点已注册。
 */

$root = dirname(__DIR__, 2);
$assertions = 0;

function checkQuoteFrontend($condition, $message)
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
}

// 1. 页面入口与脚本
checkQuoteFrontend(is_file($root . '/application/admin/controller/cpq/Quote.php'), '缺少报价后台控制器');
foreach (['index', 'wizard', 'detail', 'diff'] as $view) {
    checkQuoteFrontend(is_file($root . '/application/admin/view/cpq/quote/' . $view . '.html'), 'quote 缺少 ' . $view . ' 视图');
}
checkQuoteFrontend(is_file($root . '/public/assets/js/backend/cpq/quote.js'), '缺少 quote.js');
checkQuoteFrontend(is_file($root . '/public/assets/js/backend/cpq/quote_wizard.js'), '缺少 quote_wizard.js');

$controller = file_get_contents($root . '/application/admin/controller/cpq/Quote.php');
$quoteJs = file_get_contents($root . '/public/assets/js/backend/cpq/quote.js');
$wizardJs = file_get_contents($root . '/public/assets/js/backend/cpq/quote_wizard.js');
$wizardView = file_get_contents($root . '/application/admin/view/cpq/quote/wizard.html');
$indexView = file_get_contents($root . '/application/admin/view/cpq/quote/index.html');
$detailView = file_get_contents($root . '/application/admin/view/cpq/quote/detail.html');
$install = file_get_contents($root . '/application/admin/command/CpqInstall.php');

// 2. 金额不经过 JS 浮点运算（最终金额只展示服务端 Decimal 字符串）
checkQuoteFrontend(!preg_match('/\b(parseFloat|toFixed|Math\.round|Number)\s*\(/', $quoteJs . $wizardJs), '报价页面出现 JS 浮点计算');

// 3. 六步向导结构
for ($step = 1; $step <= 6; $step++) {
    checkQuoteFrontend(strpos($wizardView, 'data-step="' . $step . '"') !== false, '向导缺少第 ' . $step . ' 步');
}

// 4. 保存/试算/提交全部走后端（同版本重算），含乐观锁与幂等键
checkQuoteFrontend(strpos($wizardJs, "cpq/quote/save") !== false, '向导未接草稿保存接口');
checkQuoteFrontend(strpos($wizardJs, "cpq/quote/recalculate") !== false, '向导未接服务端试算接口');
checkQuoteFrontend(strpos($wizardJs, "cpq/quote/submit") !== false, '向导未接提交接口');
checkQuoteFrontend(strpos($wizardJs, 'optimistic_lock_version') !== false, '向导未携带乐观锁版本');
checkQuoteFrontend(strpos($wizardJs, 'idempotency_key') !== false && strpos($wizardJs, 'genIdempotencyKey') !== false, '提交缺少幂等键');
checkQuoteFrontend(strpos($wizardJs, 'cpq/configurator/validateConfiguration') !== false, '配置未走服务端校验');
checkQuoteFrontend(strpos($wizardJs, 'cpq/configurator/schema') !== false, '配置结构未走后端 schema 接口');

// 5. 后端错误回到对应步骤/字段
checkQuoteFrontend(strpos($wizardJs, 'function errorStep') !== false
    && strpos($wizardJs, "indexOf('CPQ_CONFIG') === 0") !== false
    && strpos($wizardJs, "indexOf('CPQ_PRICE') === 0") !== false, '向导缺少错误→步骤映射');
checkQuoteFrontend(strpos($wizardJs, 'block_reasons') !== false, '向导未展示服务端阻断原因');

// 6. 服务端职责：作用域、乐观锁、提交校验、脱敏
foreach (['assertScoped', 'ProductLineScopeService', 'updateDraft', 'createDraft', 'recalcBeforeSubmit', 'QuoteRevisionService'] as $needle) {
    checkQuoteFrontend(strpos($controller, $needle) !== false, '控制器缺少 ' . $needle);
}
checkQuoteFrontend(strpos($controller, 'maskForRoles') !== false, '试算响应未按角色脱敏');
checkQuoteFrontend(strpos($controller, 'SensitiveFieldService::rolesOfAdmin') !== false, '未从服务端会话解析角色');
checkQuoteFrontend(strpos($controller, 'editableStatuses') !== false, '向导未限制非草稿态编辑');

// 6.1 审查加固：API 与后台出口同一脱敏/数据范围口径（GYTAI-78 审查 H1/H2/M2/M3）
$apiController = file_get_contents($root . '/application/api/controller/cpq/Quote.php');
checkQuoteFrontend(preg_match('/function recalculate[\s\S]*?maskForRoles/', $apiController) === 1, 'API 试算必须按角色脱敏');
checkQuoteFrontend(preg_match('/function recalculate[\s\S]*?recordAccess/', $apiController) === 1, 'API 试算敏感角色必须写查看审计');
checkQuoteFrontend(strpos($apiController, 'diffForRoles') !== false, 'API 版本差异必须走按角色脱敏出口');
checkQuoteFrontend(strpos($apiController, 'QuoteDataScopeService') !== false, 'API 报价列表必须应用统一数据范围');
checkQuoteFrontend(strpos($controller, 'diffForRoles') !== false, '后台 diffdata 必须走按角色脱敏出口');
checkQuoteFrontend(substr_count($controller, 'applyToQuoteQuery') >= 2, '后台报价默认列表必须应用统一数据范围');
checkQuoteFrontend(preg_match('/function recalculate[\s\S]*?recordAccess/', $controller) === 1, '后台试算敏感角色必须写查看审计');

// 7. 列表多视图/筛选与状态化操作
checkQuoteFrontend(strpos($indexView, 'id="cpq-quote-views"') !== false, '列表缺少多视图切换');
checkQuoteFrontend(substr_count($indexView, 'data-status=') >= 6, '列表多视图分组不足');
checkQuoteFrontend(strpos($quoteJs, "row.status === 'draft' || row.status === 'withdrawn'") !== false, '列表编辑/提交未按状态显示');
checkQuoteFrontend(strpos($quoteJs, "row.status === 'submitted'") !== false, '列表撤回未按状态显示');
checkQuoteFrontend(strpos($quoteJs, 'btn-cpq-revision') !== false && strpos($quoteJs, 'btn-cpq-diff') !== false, '列表缺少修订/差异入口');

// 8. 历史只读与版本差异
checkQuoteFrontend(strpos($quoteJs, "['submitted', 'approved', 'sent', 'accepted', 'revised']") !== false, '详情页修订入口未限定已提交状态');
checkQuoteFrontend(strpos($detailView, 'cpq-detail-revisions') !== false, '详情页缺少版本历史');
checkQuoteFrontend(strpos($quoteJs, 'cpq/quote/diffdata') !== false, '差异页未接后端差异接口');

// 9. 菜单权限节点
checkQuoteFrontend(strpos($install, "'cpq/quote'") !== false, 'CpqInstall 未注册报价菜单');
foreach (['wizard', 'save', 'recalculate', 'submit', 'withdraw', 'copy', 'revision', 'diffdata'] as $action) {
    checkQuoteFrontend(strpos($install, "'" . $action . "' =>") !== false, 'CpqInstall 缺少 cpq/quote/' . $action . ' 节点');
}

echo 'M2 quote frontend contract tests: PASS (' . $assertions . " assertions)\n";
