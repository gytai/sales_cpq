<?php
/**
 * M0 组件 PoC：队列（think-queue）/ PDF（mpdf）/ Excel（PhpSpreadsheet）
 *
 * 用法：
 *   php tests/cpq/poc.php              # 全量（需要队列 connector 可达，Redis 需 ext-redis）
 *   php tests/cpq/poc.php --skip-queue # 只验证 PDF / Excel（本机无 Redis 时）
 *
 * 退出码非 0 即失败；每一步打印 PASS/FAIL 明细，结论汇总进 docs/cpq/m0-poc.md。
 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);

// base.php 已将 .env 解析进环境变量（putenv），此处只需注册队列配置
think\Config::set('queue', include APP_PATH . 'extra' . DIRECTORY_SEPARATOR . 'queue.php');

$skipQueue = in_array('--skip-queue', $argv, true);
$failures  = [];

function pocAssert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pocRun($name, callable $fn)
{
    global $failures;
    try {
        $fn();
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failures[] = $name . ': ' . $e->getMessage();
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

// ---------------------------------------------------------------- PDF (mpdf)
pocRun('PDF/mpdf 生成中文报价单', function () {
    pocAssert(class_exists('Mpdf\\Mpdf'), 'mpdf/mpdf 未安装（composer install 后应存在）');

    $tmpDir = rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'temp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0777, true);
    }

    // 中文必须真实嵌入字体渲染。容器/服务器用 fonts-wqy-zenhei（Dockerfile 已装）；
    // 本机无 CJK 字体时，可从镜像提取到 runtime/temp/fonts/（见 docker/README.md 故障排查）
    $cjkFonts = [
        ['dir' => '/usr/share/fonts/truetype/wqy', 'file' => 'wqy-zenhei.ttc', 'marker' => 'WenQuanYi'],
        ['dir' => $tmpDir . DIRECTORY_SEPARATOR . 'fonts', 'file' => 'wqy-zenhei.ttc', 'marker' => 'WenQuanYi'],
    ];
    $font = null;
    foreach ($cjkFonts as $candidate) {
        if (is_file($candidate['dir'] . DIRECTORY_SEPARATOR . $candidate['file'])) {
            $font = $candidate + ['name' => 'wqyzh'];
            break;
        }
    }
    pocAssert($font !== null, '环境中没有可用中文字体 wqy-zenhei.ttc（容器已装 fonts-wqy-zenhei；本机可从镜像提取，见 docker/README.md）');

    $defaultConfig     = (new Mpdf\Config\ConfigVariables())->getDefaults();
    $defaultFontConfig = (new Mpdf\Config\FontVariables())->getDefaults();
    $mpdf = new Mpdf\Mpdf([
        'mode'         => 'utf-8',
        'tempDir'      => $tmpDir,
        'fontDir'      => array_merge($defaultConfig['fontDir'], [$font['dir']]),
        'fontdata'     => $defaultFontConfig['fontdata'] + [
            $font['name'] => ['R' => $font['file'], 'TTCfontID' => ['R' => 0]],
        ],
        'default_font' => $font['name'],
    ]);
    $mpdf->SetTitle('CPQ PoC 报价单');
    $mpdf->WriteHTML('<h1>报价单 Quotation</h1><p>型号：CPQ-DEMO-EQUIPMENT-A；数量：2；金额：¥12,345.67</p>');

    $file = $tmpDir . DIRECTORY_SEPARATOR . 'cpq_poc_quote.pdf';
    $mpdf->Output($file, Mpdf\Output\Destination::FILE);

    pocAssert(is_file($file), 'PDF 文件未生成');
    pocAssert(strncmp(file_get_contents($file), '%PDF', 4) === 0, '生成文件不是合法 PDF');
    pocAssert(filesize($file) > 2048, 'PDF 体积异常小，内容可能缺失');
    pocAssert(strpos(file_get_contents($file), $font['marker']) !== false, 'PDF 未使用中文字体（' . $font['marker'] . '），中文将渲染为乱码');
});

// ------------------------------------------------------- Excel (PhpSpreadsheet)
pocRun('Excel/PhpSpreadsheet 中文读写', function () {
    pocAssert(class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet'), 'phpoffice/phpspreadsheet 未安装');

    $sheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet->getActiveSheet()->setCellValue('A1', '产品系列')->setCellValue('B1', '价格条目');

    $file = rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . 'cpq_poc.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($file);
    pocAssert(is_file($file) && filesize($file) > 1024, 'xlsx 未生成或体积异常');

    $loaded = PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    pocAssert($loaded->getActiveSheet()->getCell('A1')->getValue() === '产品系列', '中文单元格读回不匹配');
});

// ------------------------------------------------------------ 队列 (think-queue)
if (!$skipQueue) {
    pocRun('队列/think-queue 推送与消费', function () {
        $config    = think\Config::get('queue');
        $connector = isset($config['connector']) ? $config['connector'] : 'Redis';

        $marker = app\common\job\CpqSmokeJob::markerPath();
        if (is_file($marker)) {
            unlink($marker);
        }
        $token = 'poc-' . date('YmdHis') . '-' . mt_rand(1000, 9999);

        think\Queue::push('app\\common\\job\\CpqSmokeJob', ['token' => $token], 'default');

        if (strcasecmp($connector, 'Sync') !== 0) {
            // 非同步驱动：调用 queue:work 消费一条（非守护模式只处理下一条任务）
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rtrim(ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR . 'think')
                . ' queue:work --queue=default --tries=1 2>&1';
            exec($cmd, $output, $exitCode);
            pocAssert($exitCode === 0, 'queue:work 执行失败: ' . implode("\n", $output));
        }

        pocAssert(is_file($marker), '消费标记文件未生成（connector=' . $connector . '）');
        $payload = json_decode(file_get_contents($marker), true);
        pocAssert($payload && $payload['token'] === $token, '标记内容与推送 token 不一致');
        unlink($marker);
    });
} else {
    echo "[SKIP] 队列/think-queue（--skip-queue）\n";
}

echo "\n=== M0 组件 PoC 结果 ===\n";
if ($failures) {
    echo 'FAILED: ' . count($failures) . " 项\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}
echo "ALL PASS\n";
