<?php
/**
 * M3 审批状态机 / 委托代理 / 模板 / 打印服务级测试（GYTAI-75，方案 §4.4-4.6、P02/P59/P60/P70-P75）。
 *
 * 覆盖：
 *  - 三条固定审批路径：none（销售确认）/ line（产线审批）/ company（产线→公司）；
 *  - 职责分离：报价负责人/提交人不能处理特批节点（无权异常）；
 *  - 重复操作与幂等：同幂等键返回既有结果；已处理任务再操作被拒绝；
 *  - 驳回 / 退回（报价回到可编辑 returned）/ 转交 / 加签会签；
 *  - 版本失效：修订后旧任务自动失效并拒绝动作；
 *  - 委托代理：创建/审批/越权审批拒绝/重叠拒绝/代理待办可见/代理动作留痕/撤销；
 *  - 模板：板块与变量白名单校验、预览缺失变量、发布、市场默认唯一；
 *  - 打印：异步任务幂等（成功不覆盖）、生成落盘 + 哈希、下载计数、篡改后哈希校验失败。
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/m3_approval.php
 *
 * 使用独立临时库 cpq_m3_approval_test（install.sql + 最小 fa_admin/fa_auth_group 表），结束时自动 DROP。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\service\cpq\ApprovalDelegationService;
use app\common\service\cpq\ApprovalService;
use app\common\service\cpq\QuoteDocumentService;
use app\common\service\cpq\QuoteRevisionService;
use app\common\service\cpq\QuoteTemplateService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m3_approval_test';
const PREFIX = 'fa_';
const LINE = 'CPQ-A-LINE';

const ADMIN_OWNER = 1;    // 报价负责人/提交人（超管组）
const ADMIN_LINE = 2;     // 产线审批人
const ADMIN_COMPANY = 3;  // 公司审批人
const ADMIN_DELEGATE = 4; // 代理人

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

function expectRuntime(callable $fn, $message)
{
    global $assertCount;
    $assertCount++;
    try {
        $fn();
    } catch (\RuntimeException $exception) {
        echo "[ok] {$message}（{$exception->getMessage()}）\n";
        return $exception;
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出 RuntimeException 但未抛出');
}

function expectInvalid(callable $fn, $message)
{
    global $assertCount;
    $assertCount++;
    try {
        $fn();
    } catch (\InvalidArgumentException $exception) {
        echo "[ok] {$message}（{$exception->getMessage()}）\n";
        return $exception;
    }
    throw new RuntimeException('[FAIL] ' . $message . ' —— 应抛出 InvalidArgumentException 但未抛出');
}

// ---------------------------------------------------------------------
// 临时库（install.sql + 最小后台账号表）
// ---------------------------------------------------------------------
$dbConfig = Config::get('database');
$rootDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbConfig['hostname'], $dbConfig['hostport'] ?: 3306);
$pdo = new PDO($rootDsn, $dbConfig['username'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `' . TEST_DB . '`');
$pdo->exec('CREATE DATABASE `' . TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `' . TEST_DB . '`');
$pdo->exec(str_replace('__PREFIX__', PREFIX, file_get_contents(dirname(__DIR__, 2) . '/database/cpq/install.sql')));
// 审批服务依赖的最小后台账号/权限表（仅含被引用的列）
$pdo->exec("CREATE TABLE `fa_admin` (`id` INT UNSIGNED NOT NULL PRIMARY KEY, `username` VARCHAR(50) NOT NULL DEFAULT '', `nickname` VARCHAR(50) NOT NULL DEFAULT '', `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) NOT NULL DEFAULT '', `rules` TEXT NULL, `status` VARCHAR(30) NOT NULL DEFAULT 'normal') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE `fa_auth_group_access` (`uid` INT UNSIGNED NOT NULL, `group_id` INT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

$now = time();

// ---------------------------------------------------------------------
// 种子：账号/角色/数据范围 + 最小定价主数据 + 审批规则 + 默认模板
// ---------------------------------------------------------------------
$pdo->exec("INSERT INTO `fa_admin` (`id`,`username`,`nickname`) VALUES
    (1,'admin','演示销售'),(2,'cpq_line','产线审批员'),(3,'cpq_company','公司审批员'),(4,'cpq_delegate','代理审批员')");
$groupSuper = (int)Db::name('auth_group')->insertGetId(['name' => 'administrators', 'rules' => '*']);
$groupLine = (int)Db::name('auth_group')->insertGetId(['name' => 'line_pricer', 'rules' => '1']);
$groupCompany = (int)Db::name('auth_group')->insertGetId(['name' => 'company_pricer', 'rules' => '1']);
Db::name('auth_group_access')->insertAll([
    ['uid' => ADMIN_OWNER, 'group_id' => $groupSuper],
    ['uid' => ADMIN_LINE, 'group_id' => $groupLine],
    ['uid' => ADMIN_COMPANY, 'group_id' => $groupCompany],
    ['uid' => ADMIN_DELEGATE, 'group_id' => $groupLine],
]);
foreach ([ADMIN_LINE, ADMIN_COMPANY, ADMIN_DELEGATE] as $approverId) {
    Db::name('cpq_admin_product_line')->insert([
        'admin_id' => $approverId, 'product_line' => LINE, 'createtime' => $now, 'updatetime' => $now,
    ]);
}

$seriesId = (int)Db::name('cpq_product_series')->insertGetId([
    'code' => 'CPQ-A-SERIES', 'name' => '审批测试系列', 'business_unit' => 'CPQ-A-BU',
    'product_line' => LINE, 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
$modelId = (int)Db::name('cpq_product_model')->insertGetId([
    'series_id' => $seriesId, 'code' => 'CPQ-A-MODEL-A', 'name' => '审批测试型号A', 'category_code' => 'CPQ-A-CAT',
    'base_item_code' => 'BASE-CPQ-A-MODEL-A', 'unit' => 'set', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
$levelId = (int)Db::name('cpq_customer_level')->insertGetId([
    'code' => 'CPQ-A-LV', 'name' => '标准', 'sort' => 20, 'default_discount' => 1.000000,
    'market_scope' => 'all', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$regionId = (int)Db::name('cpq_region')->insertGetId([
    'code' => 'CPQ-A-REGION', 'name' => '审批测试区域', 'parent_id' => 0, 'path' => '/', 'level' => 1,
    'default_currency' => 'CNY', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_region')->where('id', $regionId)->update(['path' => '/' . $regionId . '/']);
$customerId = (int)Db::name('cpq_customer')->insertGetId([
    'code' => 'CPQ-A-CUST', 'name' => '审批测试客户', 'type' => 'direct', 'country_code' => 'CN',
    'region_id' => $regionId, 'customer_level_id' => $levelId, 'default_currency' => 'CNY',
    'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$bookId = (int)Db::name('cpq_price_book')->insertGetId([
    'code' => 'CPQ-A-BOOK', 'name' => '审批测试价目', 'company' => '', 'business_unit' => '',
    'market_scope' => 'all', 'currency' => 'CNY', 'tax_mode' => 'tax_exclusive', 'priority' => 10,
    'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_price_entry')->insert([
    'price_book_id' => $bookId, 'target_type' => 'model', 'target_id' => $modelId,
    'amount' => 120000, 'unit' => 'set', 'min_qty' => 0, 'max_qty' => null,
    'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_price_policy')->insert([
    'code' => 'CPQ-A-POLICY', 'name' => '审批测试策略', 'dimension_key' => '',
    'company' => '', 'business_unit' => '', 'market_scope' => 'all', 'region_code' => '', 'customer_level' => '',
    'agent_level' => '', 'customer_id' => null, 'agent_id' => null, 'product_line' => '',
    'target_type' => 'model', 'target_id' => $modelId, 'currency' => 'CNY', 'unit' => 'set',
    'guide_price' => 120000, 'line_floor' => 108000, 'company_floor' => 96000, 'cost' => 60000,
    'priority' => 10, 'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_tax_rule')->insert([
    'code' => 'CPQ-A-TAX', 'country_code' => 'CN', 'region_code' => '', 'product_type' => '',
    'rate' => 0.130000, 'effective_date' => '2026-01-01', 'status' => 'normal',
    'createtime' => $now, 'updatetime' => $now,
]);
$salesOrgId = (int)Db::name('cpq_sales_org')->insertGetId([
    'code' => 'CPQ-A-ORG', 'name' => '审批测试销售组织', 'parent_id' => 0, 'path' => '/',
    'level' => 1, 'manager_id' => 0, 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_sales_org')->where('id', $salesOrgId)->update(['path' => '/' . $salesOrgId . '/']);

Db::name('cpq_approval_rule')->insertAll([
    ['code' => 'CPQ-A-APR-LINE', 'name' => '产线审批规则', 'node' => 'line_approval', 'approver_role' => '',
     'product_line' => LINE, 'candidate_admin_ids' => '[' . ADMIN_LINE . ']', 'sla_hours' => 24,
     'version' => 1, 'status' => 'enabled', 'createtime' => $now, 'updatetime' => $now],
    ['code' => 'CPQ-A-APR-COMPANY', 'name' => '公司审批规则', 'node' => 'company_approval', 'approver_role' => '',
     'product_line' => LINE, 'candidate_admin_ids' => '[' . ADMIN_COMPANY . ']', 'sla_hours' => 48,
     'version' => 1, 'status' => 'enabled', 'createtime' => $now, 'updatetime' => $now],
]);

$templateService = new QuoteTemplateService();
$templateId = (int)Db::name('cpq_quote_template')->insertGetId([
    'code' => 'CPQ-A-QT-ZH', 'name' => '审批测试模板', 'name_en' => 'Approval Test Template',
    'language' => 'zh', 'market_scope' => 'all', 'paper_size' => 'A4', 'is_default' => 1,
    'content_json' => json_encode($templateService->defaultContent('zh'), JSON_UNESCAPED_UNICODE),
    'allowed_variables' => json_encode(array_keys(QuoteTemplateService::VARIABLE_WHITELIST)),
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);

$quoteService = new QuoteRevisionService();
$approvalService = new ApprovalService();
$delegationService = new ApprovalDelegationService();
$documentService = new QuoteDocumentService();

/** 建一个 1 行报价草稿并按折扣提交，返回 [quote_id, submit结果] */
function submitQuote($discount, $key, $reason = '审批测试')
{
    global $quoteService, $modelId, $customerId, $salesOrgId;
    $draft = $quoteService->createDraft([
        'name' => '审批测试报价-' . $key,
        'customer_id' => $customerId,
        'product_line' => LINE,
        'currency' => 'CNY',
        'sales_org_id' => $salesOrgId,
        'lines' => [[
            'model_id' => $modelId, 'quantity' => 1, 'unit' => 'set',
            'manual_discount' => $discount, 'discount_reason' => $discount < 1 ? $reason : '',
        ]],
        'terms' => [['term_type' => 'payment', 'content' => '30% 预付，货到付清']],
    ], ADMIN_OWNER);
    $submitted = $quoteService->submit((int)$draft['id'], ADMIN_OWNER, $key);
    return [(int)$draft['id'], $submitted];
}

function pendingTaskOf($quoteId)
{
    return Db::name('cpq_approval_task')->where('quote_id', (int)$quoteId)->where('status', 'pending')->find();
}

// ---------------------------------------------------------------------
// 1. 路径一：正常价格 → 销售确认 → 已批准
// ---------------------------------------------------------------------
echo "\n== 1. 固定路径一（none：销售确认）==\n";
list($quoteA, $submitA) = submitQuote(1, 'CPQ-A-SUBMIT-1');
check($submitA['approval_level'] === 'none', '正常价格审批等级 none');
$taskA = pendingTaskOf($quoteA);
check($taskA && $taskA['node'] === 'sales_confirm', '正常路径首节点为销售确认');
check((int)$taskA['assignee_id'] === ADMIN_OWNER, '销售确认任务固定指派给报价负责人');
check((int)$taskA['sla_deadline'] === (int)$taskA['arrived_at'] + ApprovalService::SALES_CONFIRM_SLA_HOURS * 3600, '销售确认 SLA=24h');

$result = $approvalService->act((int)$taskA['id'], ADMIN_OWNER, 'confirm', ['comment' => '确认无误', 'idempotency_key' => 'CPQ-A-ACT-1']);
check($result['instance_status'] === 'completed', '销售确认后实例完成');
check(Db::name('cpq_quote')->where('id', $quoteA)->value('status') === 'approved', '报价状态已批准');

// ---------------------------------------------------------------------
// 2. 路径二：低于指导价 → 产线价格审批（含职责分离 + 幂等 + 重复操作）
// ---------------------------------------------------------------------
echo "\n== 2. 固定路径二（line：产线价格审批）==\n";
list($quoteB, $submitB) = submitQuote(0.93, 'CPQ-A-SUBMIT-2');
check($submitB['approval_level'] === 'line', '折扣 0.93 触发产线审批');
$taskB = pendingTaskOf($quoteB);
check($taskB && $taskB['node'] === 'line_approval', '产线路径节点为产线价格审批');
check((int)$taskB['assignee_id'] === ADMIN_LINE, '产线任务指派给规则候选人');

expectRuntime(function () use ($approvalService, $taskB) {
    $approvalService->act((int)$taskB['id'], ADMIN_OWNER, 'approve', ['comment' => '自己批自己']);
}, '职责分离：报价负责人不能处理产线审批任务');

$actionCount = Db::name('cpq_approval_action')->where('quote_id', $quoteB)->count();
$result = $approvalService->act((int)$taskB['id'], ADMIN_LINE, 'approve', [
    'comment' => '产线同意', 'reason_category' => '价格偏低', 'idempotency_key' => 'CPQ-A-ACT-2',
]);
check($result['instance_status'] === 'completed', '产线批准后实例完成（产线路径仅一个审批节点）');
check(Db::name('cpq_quote')->where('id', $quoteB)->value('status') === 'approved', '报价状态已批准');

$repeat = $approvalService->act((int)$taskB['id'], ADMIN_LINE, 'approve', [
    'comment' => '重复点击', 'idempotency_key' => 'CPQ-A-ACT-2',
]);
check(!empty($repeat['idempotent']), '同幂等键重复动作返回既有结果');
check(Db::name('cpq_approval_action')->where('quote_id', $quoteB)->count() === $actionCount + 1, '幂等重复未产生新动作记录');

expectRuntime(function () use ($approvalService, $taskB) {
    $approvalService->act((int)$taskB['id'], ADMIN_LINE, 'approve', ['comment' => '换个幂等键再点']);
}, '重复操作：已处理任务再次动作被拒绝');

// ---------------------------------------------------------------------
// 3. 路径三：低于产线控制价 → 产线 → 公司两级审批
// ---------------------------------------------------------------------
echo "\n== 3. 固定路径三（company：产线→公司）==\n";
list($quoteC, $submitC) = submitQuote(0.85, 'CPQ-A-SUBMIT-3');
check($submitC['approval_level'] === 'company', '折扣 0.85 触发公司审批');
$taskC1 = pendingTaskOf($quoteC);
check($taskC1 && $taskC1['node'] === 'line_approval', '公司路径首节点为产线审批');
$result = $approvalService->act((int)$taskC1['id'], ADMIN_LINE, 'approve', ['comment' => '产线通过']);
check($result['instance_status'] === 'active', '产线通过后实例仍在途');
$taskC2 = pendingTaskOf($quoteC);
check($taskC2 && $taskC2['node'] === 'company_approval' && (int)$taskC2['assignee_id'] === ADMIN_COMPANY, '推进到公司价格审批节点');
$result = $approvalService->act((int)$taskC2['id'], ADMIN_COMPANY, 'approve', ['comment' => '公司同意']);
check($result['instance_status'] === 'completed', '公司批准后实例完成');
check(Db::name('cpq_quote')->where('id', $quoteC)->value('status') === 'approved', '报价状态已批准');

// ---------------------------------------------------------------------
// 4. 驳回与退回
// ---------------------------------------------------------------------
echo "\n== 4. 驳回与退回 ==\n";
list($quoteD) = submitQuote(0.93, 'CPQ-A-SUBMIT-4');
$taskD = pendingTaskOf($quoteD);
$result = $approvalService->act((int)$taskD['id'], ADMIN_LINE, 'reject', ['comment' => '折扣无依据', 'reason_category' => '价格偏低']);
check($result['instance_status'] === 'rejected', '驳回后实例已驳回');
check(Db::name('cpq_quote')->where('id', $quoteD)->value('status') === 'rejected', '驳回后报价状态 rejected（终态）');

list($quoteE) = submitQuote(0.93, 'CPQ-A-SUBMIT-5');
$taskE = pendingTaskOf($quoteE);
$result = $approvalService->act((int)$taskE['id'], ADMIN_LINE, 'return', ['comment' => '请补充客户资信', 'reason_category' => '资料不全']);
check($result['instance_status'] === 'returned', '退回后实例已退回');
check(Db::name('cpq_quote')->where('id', $quoteE)->value('status') === 'returned', '退回后报价回到可编辑 returned 态');

// ---------------------------------------------------------------------
// 5. 转交与加签（会签）
// ---------------------------------------------------------------------
echo "\n== 5. 转交与加签 ==\n";
list($quoteF) = submitQuote(0.93, 'CPQ-A-SUBMIT-6');
$taskF = pendingTaskOf($quoteF);
$approvalService->act((int)$taskF['id'], ADMIN_LINE, 'transfer', ['comment' => '转给代理审批员', 'next_assignee_id' => ADMIN_DELEGATE]);
$taskF2 = pendingTaskOf($quoteF);
check((int)$taskF2['assignee_id'] === ADMIN_DELEGATE && $taskF2['node'] === 'line_approval', '转交后新任务指向转交人');
check(Db::name('cpq_approval_task')->where('id', (int)$taskF['id'])->value('status') === 'transferred', '原任务标记已转交');

list($quoteG) = submitQuote(0.93, 'CPQ-A-SUBMIT-7');
$taskG = pendingTaskOf($quoteG);
$approvalService->act((int)$taskG['id'], ADMIN_LINE, 'add_sign', ['comment' => '加签公司审批员会签', 'next_assignee_id' => ADMIN_COMPANY]);
$signTask = Db::name('cpq_approval_task')
    ->where('quote_id', $quoteG)->where('node', 'line_approval')
    ->where('assignee_id', ADMIN_COMPANY)->where('is_required', 1)->where('status', 'pending')->find();
check($signTask !== null, '加签生成会签（is_required=1）待办任务');
$result = $approvalService->act((int)$taskG['id'], ADMIN_LINE, 'approve', ['comment' => '产线同意']);
check($result['instance_status'] === 'active', '会签未处理完，节点不推进');
$result = $approvalService->act((int)$signTask['id'], ADMIN_COMPANY, 'approve', ['comment' => '会签同意']);
check($result['instance_status'] === 'completed', '会签完成后实例完成');

// ---------------------------------------------------------------------
// 6. 版本变化 → 旧任务自动失效
// ---------------------------------------------------------------------
echo "\n== 6. 版本失效 ==\n";
list($quoteH) = submitQuote(0.93, 'CPQ-A-SUBMIT-8');
$taskH = pendingTaskOf($quoteH);
$quoteService->createRevision($quoteH, ADMIN_OWNER);
$quoteHRow = Db::name('cpq_quote')->where('id', $quoteH)->find();
expectRuntime(function () use ($approvalService, $taskH) {
    $approvalService->act((int)$taskH['id'], ADMIN_LINE, 'approve', ['comment' => '旧版本批准']);
}, '版本变化后旧任务动作被拒绝');
check(Db::name('cpq_approval_task')->where('id', (int)$taskH['id'])->value('status') !== 'pending', '旧任务已失效（非待处理）');

// ---------------------------------------------------------------------
// 7. 委托与代理
// ---------------------------------------------------------------------
echo "\n== 7. 委托代理 ==\n";
$delegation = $delegationService->create([
    'delegate_id' => ADMIN_DELEGATE,
    'product_line' => LINE,
    'reason' => '休假期间代理审批',
    'starts_at' => $now - 3600,
    'ends_at' => $now + 86400,
], ADMIN_LINE);
$delegationId = (int)$delegation['id'];
check($delegationId > 0, '委托申请已创建（待审批）');

expectRuntime(function () use ($delegationService, $delegationId) {
    $delegationService->approve($delegationId, ADMIN_DELEGATE, true);
}, '无委托审批权限的角色审批被拒绝');
$delegationService->approve($delegationId, ADMIN_OWNER, true);
check(Db::name('cpq_approval_delegation')->where('id', $delegationId)->value('status') === 'active', '委托经审批后生效');
check($delegationService->resolveDelegator(ADMIN_LINE, ADMIN_DELEGATE, 'approval', LINE) === ADMIN_LINE, '代理人解析命中委托人');

expectInvalid(function () use ($delegationService, $now) {
    $delegationService->create([
        'delegate_id' => ADMIN_DELEGATE, 'product_line' => LINE,
        'reason' => '重复委托', 'starts_at' => $now, 'ends_at' => $now + 7200,
    ], ADMIN_LINE);
}, '同代理人时间窗重叠的委托被拒绝');

list($quoteI) = submitQuote(0.93, 'CPQ-A-SUBMIT-9');
$taskI = pendingTaskOf($quoteI);
check((int)$taskI['assignee_id'] === ADMIN_LINE, '委托不影响任务指派（仍指向委托人）');
$delegateTodo = $approvalService->myTaskList(ADMIN_DELEGATE, 'pending', [], 0, 20);
$delegateTaskIds = array_map(function ($row) { return (int)$row['id']; }, $delegateTodo['rows']);
check(in_array((int)$taskI['id'], $delegateTaskIds, true), '代理人的待办列表可见委托人任务');
$delegateRow = null;
foreach ($delegateTodo['rows'] as $row) {
    if ((int)$row['id'] === (int)$taskI['id']) {
        $delegateRow = $row;
    }
}
check($delegateRow && (int)$delegateRow['is_delegate_view'] === 1, '代理待办带代理标记');

$result = $approvalService->act((int)$taskI['id'], ADMIN_DELEGATE, 'approve', ['comment' => '代理批准']);
check($result['instance_status'] === 'completed', '代理人批准成功');
$actionRow = Db::name('cpq_approval_action')->where('task_id', (int)$taskI['id'])->order('id desc')->find();
check((int)$actionRow['actor_id'] === ADMIN_DELEGATE && (int)$actionRow['delegate_from_id'] === ADMIN_LINE, '代理动作留痕（实际处理人+委托人）');
$delegated = $delegationService->delegatedActions(ADMIN_DELEGATE, 0, 20);
check($delegated['total'] >= 1, '代理操作记录可查询');

$delegationService->cancel($delegationId, ADMIN_LINE);
check($delegationService->resolveDelegator(ADMIN_LINE, ADMIN_DELEGATE, 'approval', LINE) === 0, '撤销后代理立即失效');

// ---------------------------------------------------------------------
// 8. SLA 与待办列表
// ---------------------------------------------------------------------
echo "\n== 8. SLA 与待办 ==\n";
list($quoteJ) = submitQuote(0.93, 'CPQ-A-SUBMIT-10');
$todo = $approvalService->myTaskList(ADMIN_LINE, 'pending', ['product_line' => LINE], 0, 20);
$row = null;
foreach ($todo['rows'] as $item) {
    if ((int)$item['quote_id'] === $quoteJ) {
        $row = $item;
    }
}
check($row !== null, '审批人待办包含新任务');
check($row['overdue'] === 0 && $row['sla_remaining'] > 80000, 'SLA 剩余约 24h 且未超时');
Db::name('cpq_approval_task')->where('id', (int)$row['id'])->update(['sla_deadline' => $now - 10]);
$todo2 = $approvalService->myTaskList(ADMIN_LINE, 'pending', [], 0, 20);
foreach ($todo2['rows'] as $item) {
    if ((int)$item['id'] === (int)$row['id']) {
        check((int)$item['overdue'] === 1, '超过 SLA 截止的任务标记超时');
    }
}
$approvalService->urge((int)$row['id'], ADMIN_OWNER);
check(Db::name('cpq_approval_action')->where('task_id', (int)$row['id'])->where('action', 'urge')->count() === 1, '催办记录一条动作');
expectRuntime(function () use ($approvalService, $row) {
    $approvalService->urge((int)$row['id'], ADMIN_LINE);
}, '非发起人/非负责人越权催办被拒绝');
check(Db::name('cpq_approval_action')->where('task_id', (int)$row['id'])->where('action', 'urge')->count() === 1, '越权催办不产生新动作记录');

$detail = $approvalService->taskDetail((int)$row['id'], ADMIN_LINE);
check($detail['can_act'] === 1 && $detail['allowed_actions'] === ['approve', 'reject', 'return', 'add_sign', 'transfer'], '详情仅允许五种审批动作');
check(isset($detail['pricing']['totals']['total']) && $detail['pricing']['totals']['total'] !== '', '详情含脱敏后价格汇总');
check(isset($detail['risks']) && count($detail['risks']) >= 1, '详情含风险项');
$sensitiveAuditBefore = Db::name('cpq_audit_log')->where('action', 'view_sensitive')->count();
$ownerDetail = $approvalService->taskDetail((int)$row['id'], ADMIN_OWNER);
check(Db::name('cpq_audit_log')->where('action', 'view_sensitive')->count() === $sensitiveAuditBefore + 1, '敏感角色查看审批详情写 view_sensitive 审计');
$latestSensitiveAudit = Db::name('cpq_audit_log')->where('action', 'view_sensitive')->order('id desc')->find();
check($latestSensitiveAudit && strpos(json_encode($latestSensitiveAudit, JSON_UNESCAPED_UNICODE), 'margin') === false, '敏感查看审计明细不含敏感字段值');

// ---------------------------------------------------------------------
// 9. 模板服务
// ---------------------------------------------------------------------
echo "\n== 9. 报价模板 ==\n";
expectInvalid(function () use ($templateService) {
    $templateService->validateContent(['cover_title' => '报价 {{quote.unknown_field}}']);
}, '白名单外变量保存被拒绝');
expectInvalid(function () use ($templateService) {
    $templateService->validateContent(['show_unknown_section' => 1]);
}, '未知板块保存被拒绝');

$template = Db::name('cpq_quote_template')->where('id', $templateId)->find();
$preview = $templateService->preview($template);
check(strpos($preview['html'], 'Q-DEMO-0001') !== false, '预览以测试数据渲染变量');
check(is_array($preview['missing_variables']), '预览返回缺失变量列表');

$draftTemplateId = (int)Db::name('cpq_quote_template')->insertGetId([
    'code' => 'CPQ-A-QT-DRAFT', 'name' => '草稿模板', 'name_en' => '', 'language' => 'zh', 'market_scope' => 'all',
    'paper_size' => 'A4', 'is_default' => 0,
    'content_json' => json_encode($templateService->defaultContent('zh'), JSON_UNESCAPED_UNICODE),
    'allowed_variables' => json_encode(array_keys(QuoteTemplateService::VARIABLE_WHITELIST)),
    'version' => 1, 'status' => 'draft', 'createtime' => $now, 'updatetime' => $now,
]);
expectInvalid(function () use ($templateService, $draftTemplateId) {
    $templateService->setDefault($draftTemplateId);
}, '草稿模板不可设为默认');
$templateService->publish($draftTemplateId);
check(Db::name('cpq_quote_template')->where('id', $draftTemplateId)->value('status') === 'published', '草稿模板发布成功');
expectInvalid(function () use ($templateService, $draftTemplateId) {
    $templateService->publish($draftTemplateId);
}, '已发布模板重复发布被拒绝');

$templateService->setDefault($draftTemplateId);
check((int)Db::name('cpq_quote_template')->where('id', $draftTemplateId)->value('is_default') === 1, '新模板设为默认');
check((int)Db::name('cpq_quote_template')->where('id', $templateId)->value('is_default') === 0, '同语言+市场默认唯一（旧默认被取消）');
$templateService->setDefault($templateId); // 还原默认模板供打印测试

// ---------------------------------------------------------------------
// 10. 打印任务：异步生成 / 幂等 / 哈希 / 下载计数
// ---------------------------------------------------------------------
echo "\n== 10. 打印记录 ==\n";
$job = $documentService->createJob($quoteA, 'zh', 0, ADMIN_OWNER);
$documentId = (int)$job['document']['id'];
// 队列驱动差异：sync 下投递即处理（直接 succeeded），redis 下为 pending
check(!empty($job['created']) && in_array($job['document']['status'], ['pending', 'processing', 'succeeded'], true), 'PDF 任务已受理（排队或同步生成）');
check((int)$job['document']['template_id'] === $templateId, '未指定模板时匹配语言+市场默认模板');

$processed = $documentService->process($documentId);
check($processed['status'] === 'succeeded', '任务处理完成');
check(strlen($processed['file_hash']) === 64 && (int)$processed['file_size'] > 512, '文件落盘并记录 SHA-256 与体积');
$filePath = ROOT_PATH . $processed['file_path'];
check(is_file($filePath), 'PDF 文件存在：' . basename($filePath));

$verify = $documentService->verifyHash($documentId);
check($verify['match'] === true && $verify['expected'] === $processed['file_hash'], '哈希验证通过');

$duplicate = $documentService->createJob($quoteA, 'zh', 0, ADMIN_OWNER);
check(empty($duplicate['created']) && (int)$duplicate['document']['id'] === $documentId, '同版本+模板+语言幂等复用（正式文件不覆盖）');

$download = $documentService->prepareDownload($documentId, ADMIN_OWNER);
check(is_file($download['absolute_path']), '受控下载返回文件');
check((int)Db::name('cpq_quote_document')->where('id', $documentId)->value('download_count') === 1, '下载计数 +1');

file_put_contents($filePath, 'tampered', FILE_APPEND);
$tampered = $documentService->verifyHash($documentId);
check($tampered['match'] === false, '篡改后哈希验证失败');
expectRuntime(function () use ($documentService, $documentId) {
    $documentService->prepareDownload($documentId, ADMIN_OWNER);
}, '篡改文件下载被拒绝（哈希校验失败）');
@unlink($filePath); // 不污染演示目录

// ---------------------------------------------------------------------
// 11. 审批中撤回：在途待办取消、实例 withdrawn、非发起人拒绝
// ---------------------------------------------------------------------
echo "\n== 11. 审批中撤回 ==\n";
list($quoteK) = submitQuote(0.93, 'CPQ-A-SUBMIT-11');
$taskK = pendingTaskOf($quoteK);
expectRuntime(function () use ($quoteService, $quoteK) {
    $quoteService->withdraw($quoteK, ADMIN_LINE);
}, '非发起人撤回被拒绝');
check(Db::name('cpq_quote')->where('id', $quoteK)->value('status') === 'submitted', '越权撤回失败后报价仍在审批中');
$withdrawnK = $quoteService->withdraw($quoteK, ADMIN_OWNER);
check($withdrawnK['status'] === 'withdrawn', '发起人撤回后报价回到可编辑 withdrawn 态');
check(Db::name('cpq_approval_task')->where('id', (int)$taskK['id'])->value('status') === 'cancelled', '撤回联动取消在途待办任务');
check(Db::name('cpq_approval_instance')->where('quote_id', $quoteK)->value('status') === 'withdrawn', '撤回后审批实例 withdrawn');

// ---------------------------------------------------------------------
// 12. 英文 + 多币种 PDF：USD 价目/策略/汇率 + 英文默认模板
// ---------------------------------------------------------------------
echo "\n== 12. 英文多币种 PDF ==\n";
$usdBookId = (int)Db::name('cpq_price_book')->insertGetId([
    'code' => 'CPQ-A-BOOK-USD', 'name' => '审批测试美元价目', 'company' => '', 'business_unit' => '',
    'market_scope' => 'all', 'currency' => 'USD', 'tax_mode' => 'tax_exclusive', 'priority' => 20,
    'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31', 'version' => 1, 'status' => 'published',
    'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_price_entry')->insert([
    'price_book_id' => $usdBookId, 'target_type' => 'model', 'target_id' => $modelId,
    'amount' => 20000, 'unit' => 'set', 'min_qty' => 0, 'max_qty' => null,
    'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_price_policy')->insert([
    'code' => 'CPQ-A-POLICY-USD', 'name' => '审批测试美元策略', 'dimension_key' => '',
    'company' => '', 'business_unit' => '', 'market_scope' => 'all', 'region_code' => '', 'customer_level' => '',
    'agent_level' => '', 'customer_id' => null, 'agent_id' => null, 'product_line' => '',
    'target_type' => 'model', 'target_id' => $modelId, 'currency' => 'USD', 'unit' => 'set',
    'guide_price' => 20000, 'line_floor' => 18000, 'company_floor' => 16000, 'cost' => 10000,
    'priority' => 10, 'effective_date' => '2026-01-01', 'expiry_date' => '2026-12-31',
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);
Db::name('cpq_exchange_rate')->insert([
    'source_currency' => 'USD', 'target_currency' => 'CNY', 'rate' => 7.10000000, 'source' => 'manual',
    'effective_date' => '2026-01-01', 'status' => 'normal', 'createtime' => $now, 'updatetime' => $now,
]);
$enTemplateId = (int)Db::name('cpq_quote_template')->insertGetId([
    'code' => 'CPQ-A-QT-EN', 'name' => '审批测试英文模板', 'name_en' => 'Approval Test Template EN',
    'language' => 'en', 'market_scope' => 'all', 'paper_size' => 'A4', 'is_default' => 1,
    'content_json' => json_encode($templateService->defaultContent('en'), JSON_UNESCAPED_UNICODE),
    'allowed_variables' => json_encode(array_keys(QuoteTemplateService::VARIABLE_WHITELIST)),
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
]);

$draftUsd = $quoteService->createDraft([
    'name' => '审批测试美元报价',
    'customer_id' => $customerId,
    'product_line' => LINE,
    'currency' => 'USD',
    'sales_org_id' => $salesOrgId,
    'lines' => [['model_id' => $modelId, 'quantity' => 1, 'unit' => 'set']],
], ADMIN_OWNER);
$quoteUsd = (int)$draftUsd['id'];
$submittedUsd = $quoteService->submit($quoteUsd, ADMIN_OWNER, 'CPQ-A-SUBMIT-12');
check($submittedUsd['approval_level'] === 'none', '美元报价正常价格审批等级 none');
$taskUsd = pendingTaskOf($quoteUsd);
check($taskUsd && $taskUsd['node'] === 'sales_confirm', '美元报价走销售确认节点');
$approvalService->act((int)$taskUsd['id'], ADMIN_OWNER, 'confirm', ['comment' => '确认美元报价', 'idempotency_key' => 'CPQ-A-ACT-12']);
check(Db::name('cpq_quote')->where('id', $quoteUsd)->value('status') === 'approved', '美元报价已批准');

$jobEn = $documentService->createJob($quoteUsd, 'en', 0, ADMIN_OWNER);
$docUsdId = (int)$jobEn['document']['id'];
check((int)$jobEn['document']['template_id'] === $enTemplateId, '英文任务自动匹配英文默认模板');
$processedUsd = $documentService->process($docUsdId);
check($processedUsd['status'] === 'succeeded', '英文 PDF 生成成功');
check($processedUsd['currency'] === 'USD', '多币种任务记录报价币种 USD');
check(strpos(basename($processedUsd['file_path']), '-en-') !== false, '英文文件名含语言段：' . basename($processedUsd['file_path']));
check(is_file(ROOT_PATH . $processedUsd['file_path']) && strlen($processedUsd['file_hash']) === 64, '英文文件落盘并记录 SHA-256');
@unlink(ROOT_PATH . $processedUsd['file_path']); // 不污染演示目录

// ---------------------------------------------------------------------
// 13. PDF 失败可重试：模板失效 → failed → 重试 → 成功 → 不覆盖
// ---------------------------------------------------------------------
echo "\n== 13. PDF 失败重试 ==\n";
$failTemplateSeed = [
    'code' => 'CPQ-A-QT-EN2', 'name' => '失败重试英文模板', 'name_en' => 'Retry Template',
    'language' => 'en', 'market_scope' => 'all', 'paper_size' => 'A4', 'is_default' => 0,
    'content_json' => json_encode($templateService->defaultContent('en'), JSON_UNESCAPED_UNICODE),
    'allowed_variables' => json_encode(array_keys(QuoteTemplateService::VARIABLE_WHITELIST)),
    'version' => 1, 'status' => 'published', 'createtime' => $now, 'updatetime' => $now,
];
$failTemplateId = (int)Db::name('cpq_quote_template')->insertGetId($failTemplateSeed);
list($quoteM) = submitQuote(1, 'CPQ-A-SUBMIT-13');
$quoteMRow = Db::name('cpq_quote')->where('id', $quoteM)->find();

// Sync 驱动下 createJob 投递即执行，没有“创建后、执行前”的窗口；
// 失败路径改为直接构造：手工插入指向该模板的 pending 任务，随后删除模板模拟失效。
$docMId = (int)Db::name('cpq_quote_document')->insertGetId([
    'quote_id' => $quoteM,
    'revision_no' => (int)$quoteMRow['current_revision_no'],
    'template_id' => $failTemplateId,
    'language' => 'en',
    'currency' => (string)$quoteMRow['currency'],
    'status' => 'pending',
    'requested_by' => ADMIN_OWNER,
    'createtime' => $now,
    'updatetime' => $now,
]);
Db::name('cpq_quote_template')->where('id', $failTemplateId)->delete(); // 模拟模板失效
$failedBefore = Db::name('cpq_audit_log')->where('action', 'pdf_failed')->count();
try {
    $documentService->process($docMId);
    throw new RuntimeException('[FAIL] 模板缺失应导致生成失败');
} catch (RuntimeException $exception) {
    check(strpos($exception->getMessage(), '模板不可用') !== false, '模板失效时生成失败（' . $exception->getMessage() . '）');
}
$rowM = Db::name('cpq_quote_document')->where('id', $docMId)->find();
check($rowM['status'] === 'failed', '失败状态落库');
check((string)$rowM['error_message'] !== '', '失败原因留存于任务记录');
check(Db::name('cpq_audit_log')->where('action', 'pdf_failed')->count() === $failedBefore + 1, '生成失败写 pdf_failed 审计');

// 重试：恢复模板后再次发起 → 复用 failed 记录重新排队
Db::name('cpq_quote_template')->insert(array_merge($failTemplateSeed, ['id' => $failTemplateId]));
$retryM = $documentService->createJob($quoteM, 'en', $failTemplateId, ADMIN_OWNER);
check(!empty($retryM['retried']) && (int)$retryM['document']['retry_count'] === 1 && (int)$retryM['document']['id'] === $docMId, '失败任务重试复用记录且 retry_count=1');
$processedM = $documentService->process($docMId);
check($processedM['status'] === 'succeeded' && strlen($processedM['file_hash']) === 64, '重试后生成成功并记录哈希');
$pathM = (string)$processedM['file_path'];

$againM = $documentService->createJob($quoteM, 'en', $failTemplateId, ADMIN_OWNER);
check(empty($againM['created']) && strpos($againM['message'], '不可覆盖') !== false, '成功后再次发起不覆盖正式文件');
check((string)Db::name('cpq_quote_document')->where('id', $docMId)->value('file_path') === $pathM, '正式文件路径保持不变');
@unlink(ROOT_PATH . $pathM); // 不污染演示目录

echo "\n全部 {$assertCount} 项断言通过。\n";
