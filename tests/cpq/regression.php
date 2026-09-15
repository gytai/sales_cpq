<?php
/**
 * M4 收口集成回归（GYTAI-76）：串联全部 CPQ 测试套件，输出验收用例覆盖映射。
 *
 * 覆盖映射（与 docs/cpq/acceptance-tests.md 对齐）：
 *  - C-001~C-004  配置规则服务端校验        → run.php
 *  - C-005        循环依赖/冲突发布拦截      → rule_analysis.php + rule_engine.php
 *  - C-006        规则版本更新旧快照还原     → rule_engine.php
 *  - C-007        绕过前端直调 API 被拒      → integration.php + rule_engine.php
 *  - P-001~P-012  客户渠道/价格主数据        → price_channel.php + master_data.php
 *  - Q-001~Q-008  确定性定价与三层控制价     → pricing.php
 *  - Q-007~Q-010  报价快照/修订/幂等/提交    → quote.php
 *  - 审批链路/职责分离/PDF 篡改审计          → m3_approval.php
 *  - 集成平台/凭证保险箱                     → m3_platform.php
 *  - 报表/数据范围/敏感字段脱敏              → m4_reporting.php
 *  - SQLi/XSS/CSRF/越权/上传/日志/下载权限   → security.php
 *  - 500ms 配置校验/100 行 2s/PDF 30s/列表 2s → performance.php
 *  - 空库安装/已有库升级/幂等复跑            → upgrade.php
 *  - 前端静态契约（RequireJS/页面结构）      → frontend_*.php
 *  - 同字段同交互（日期区间/版本列/表单控件）→ frontend_consistency.php
 *  - 组件 PoC（队列/PDF/Excel）              → poc.php（依赖 Redis，可用 --skip-env 跳过）
 *
 * 用法（容器内）：
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/regression.php
 *   docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/regression.php --skip-env
 *
 * 任一套件失败则整体退出码非 0。
 */

$skipEnv = in_array('--skip-env', $argv, true);

$suites = [
    ['file' => 'run.php',            'label' => 'M1 配置规则校验（C-001~C-004）',        'env' => false],
    ['file' => 'rule_analysis.php',  'label' => 'M1 规则静态分析（C-005，500ms）',       'env' => false],
    ['file' => 'rule_engine.php',    'label' => 'M1 规则引擎/BOM/快照（C-005~C-007）',   'env' => false],
    ['file' => 'master_data.php',    'label' => 'M1 主数据治理（版本/状态机/引用保护）', 'env' => false],
    ['file' => 'price_channel.php',  'label' => 'M2 客户渠道与价格主数据（P-001~P-012）', 'env' => false],
    ['file' => 'pricing.php',        'label' => 'M2 定价引擎（Q-001~Q-008）',            'env' => false],
    ['file' => 'quote.php',          'label' => 'M2 报价快照/修订/幂等（Q-007~Q-010）',  'env' => false],
    ['file' => 'm3_approval.php',    'label' => 'M3 审批链路/职责分离/PDF 篡改审计',     'env' => false],
    ['file' => 'm3_platform.php',    'label' => 'M3 集成平台与凭证保险箱',               'env' => false],
    ['file' => 'm4_reporting.php',   'label' => 'M4 报表/数据范围/敏感字段脱敏',         'env' => false],
    ['file' => 'security.php',       'label' => 'M4 安全：注入/XSS/越权/上传/日志/下载', 'env' => false],
    ['file' => 'performance.php',    'label' => 'M4 性能：500ms/2s/30s/2s 四项目标',     'env' => false],
    ['file' => 'upgrade.php',        'label' => '升级脚本：空库安装/已有库升级/幂等',    'env' => false],
    ['file' => 'frontend_m2.php',    'label' => 'M2 前端静态契约',                       'env' => false],
    ['file' => 'frontend_m2_quote.php', 'label' => 'M2 报价前端静态契约',                'env' => false],
    ['file' => 'frontend_m3.php',    'label' => 'M3 前端静态契约',                       'env' => false],
    ['file' => 'frontend_m4.php',    'label' => 'M4 前端静态契约',                       'env' => false],
    ['file' => 'frontend_consistency.php', 'label' => '页面一致性静态契约（同字段同交互）', 'env' => false],
    ['file' => 'integration.php',    'label' => '服务端集成（C-007，依赖演示数据）',     'env' => true],
    ['file' => 'poc.php',            'label' => 'M0 组件 PoC（队列/PDF/Excel）',         'env' => true],
];

$dir = __DIR__;
$results = [];
$exitCode = 0;

printf("== CPQ M4 集成回归（%d 套件%s）==\n\n", count($suites), $skipEnv ? '，跳过环境依赖项' : '');

foreach ($suites as $suite) {
    if ($suite['env'] && $skipEnv) {
        $results[] = [$suite['label'], 'SKIP', 0.0, ''];
        printf("[skip] %s（--skip-env）\n", $suite['label']);
        continue;
    }
    $start = microtime(true);
    $output = [];
    $code = 0;
    exec(sprintf('%s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($dir . DIRECTORY_SEPARATOR . $suite['file'])), $output, $code);
    $elapsed = microtime(true) - $start;
    // 提取套件自检的汇总行（PASS/断言数），没有则取最后一行输出
    $summaryLine = '';
    foreach (array_reverse($output) as $line) {
        if (preg_match('/(PASS|断言|assertions)/iu', $line)) {
            $summaryLine = trim($line);
            break;
        }
    }
    if ($summaryLine === '' && $output) {
        $summaryLine = trim((string)end($output));
    }
    $status = $code === 0 ? 'PASS' : 'FAIL';
    if ($code !== 0) {
        $exitCode = 1;
    }
    $results[] = [$suite['label'], $status, $elapsed, $summaryLine];
    printf("[%s] %s（%.1fs）%s\n", strtolower($status), $suite['label'], $elapsed, $summaryLine !== '' ? " — {$summaryLine}" : '');
    if ($code !== 0) {
        echo "----- 失败套件输出尾部 -----\n";
        echo implode("\n", array_slice($output, -15)), "\n";
    }
}

$passed = count(array_filter($results, function ($r) { return $r[1] === 'PASS'; }));
$failed = count(array_filter($results, function ($r) { return $r[1] === 'FAIL'; }));
$skipped = count(array_filter($results, function ($r) { return $r[1] === 'SKIP'; }));

printf("\n== 汇总：%d 通过 / %d 失败 / %d 跳过 ==\n", $passed, $failed, $skipped);
exit($exitCode);
