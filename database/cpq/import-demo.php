<?php
/**
 * CPQ 演示数据导入脚本（不依赖 mysql 客户端，仅需 PHP + 已配置的 .env）
 *
 * 用法（项目根目录或任意目录执行均可）：
 *   php database/cpq/import-demo.php            # 导入通用 demo + 色选机 demo（默认）
 *   php database/cpq/import-demo.php base       # 仅导入通用 demo.sql
 *   php database/cpq/import-demo.php meyer      # 仅导入色选机 demo-meyer.sql
 *
 * 行为：
 *   1. 读取项目根 .env 的 [database] 节（host/port/库名/账号/前缀）；
 *   2. 将 SQL 中的 __PREFIX__ 占位符替换为实际前缀（解决 1146 Table doesn't exist）；
 *   3. 逐条执行语句，失败的语句输出「错误信息 + 语句摘要」后继续（SQL 本身幂等）；
 *   4. 最后输出关键表行数统计。
 */

$root = dirname(__DIR__, 2);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "[错误] 未找到 {$envFile}，请先配置 .env\n");
    exit(1);
}
$env = parse_ini_file($envFile, true);
$db = $env['database'] ?? null;
if (!$db || empty($db['database'])) {
    fwrite(STDERR, "[错误] .env 中缺少 [database] 配置\n");
    exit(1);
}
$prefix = isset($db['prefix']) ? $db['prefix'] : 'fa_';

$targets = [];
$which = strtolower((string)($argv[1] ?? 'both'));
if ($which === 'base' || $which === 'both') {
    $targets[] = ['demo.sql', '通用演示数据（DEMO 前缀）'];
}
if ($which === 'meyer' || $which === 'both') {
    $targets[] = ['demo-meyer.sql', '色选机演示数据（MEY 前缀）'];
}
if (!$targets) {
    fwrite(STDERR, "[错误] 未知参数：{$which}（可用：base / meyer / both）\n");
    exit(1);
}

echo "数据库: {$db['database']} @ {$db['hostname']}:{$db['hostport']}，表前缀: {$prefix}\n";
try {
    $pdo = new PDO(
        "mysql:host={$db['hostname']};port={$db['hostport']};dbname={$db['database']};charset=utf8mb4",
        $db['username'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[错误] 数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * 按语句拆分 SQL：跳过注释/空行，仅在单引号平衡且行尾为分号时切分。
 */
function splitSql($sql)
{
    $statements = [];
    $buffer = '';
    $inString = false;
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = ltrim($line);
        if ($buffer === '' && ($trimmed === '' || $trimmed[0] === '-' || $trimmed[0] === '#')) {
            continue; // 整行注释或空行
        }
        $buffer .= ($buffer === '' ? '' : "\n") . $line;
        $escape = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\') { $escape = true; continue; }
            if ($ch === "'") { $inString = !$inString; }
        }
        if (!$inString && substr(rtrim($line), -1) === ';') {
            $stmt = rtrim(trim($buffer), ';');
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

$totalFail = 0;
foreach ($targets as $target) {
    list($file, $label) = $target;
    $path = __DIR__ . '/' . $file;
    echo "\n=== 导入 {$file}（{$label}）===\n";
    if (!is_file($path)) {
        echo "[跳过] 文件不存在: {$path}\n";
        continue;
    }
    $sql = file_get_contents($path);
    $sql = str_replace('__PREFIX__', $prefix, $sql);
    $statements = splitSql($sql);
    $ok = 0;
    $fail = 0;
    foreach ($statements as $i => $stmt) {
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            $fail++;
            $totalFail++;
            $summary = preg_replace('/\s+/', ' ', $stmt);
            if (function_exists('mb_substr')) {
                $summary = mb_substr($summary, 0, 140);
            } else {
                $summary = substr($summary, 0, 280);
            }
            echo "[失败 #{$i}] " . $e->getMessage() . "\n";
            echo "   语句: {$summary}...\n";
        }
    }
    echo "结果: 共 " . count($statements) . " 条，成功 {$ok}，失败 {$fail}\n";
}

echo "\n=== 关键表数据统计 ===\n";
$stats = [
    '产品系列'   => 'cpq_product_series',
    '产品型号'   => 'cpq_product_model',
    '配置组'     => 'cpq_option_group',
    '配置选项'   => 'cpq_option_value',
    '价格表'     => 'cpq_price_book',
    '三层策略'   => 'cpq_price_policy',
    '客户'       => 'cpq_customer',
    '审批规则'   => 'cpq_approval_rule',
    '报价模板'   => 'cpq_quote_template',
    '报价单'     => 'cpq_quote',
    '后台账号'   => 'admin',
];
foreach ($stats as $label => $table) {
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$prefix}{$table}`")->fetchColumn();
        echo str_pad($label, 12, '　') . $cnt . "\n";
    } catch (PDOException $e) {
        echo str_pad($label, 12, '　') . "（查询失败: " . $e->getMessage() . "）\n";
    }
}

echo $totalFail === 0 ? "\n✅ 全部语句执行成功\n" : "\n⚠ 有 {$totalFail} 条语句失败，请把上方 [失败] 信息反馈排查\n";
