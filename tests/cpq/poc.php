<?php
// CPQ M0 组件 PoC：队列（think-queue + Redis）、PDF（mpdf）、Excel（PhpSpreadsheet）三项可复核验证
// 运行环境：需安装 vendor（composer install）与可用的 Redis（.env [queue] 段配置）；推荐在 Docker app 容器内执行
// 用法：php tests/cpq/poc.php

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
$rootPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require $rootPath . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');
think\Config::set('queue', include APP_PATH . 'extra/queue.php');

function expectPoc($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// ---- 1. 队列 PoC：push -> Redis -> pop -> fire -> delete ----
$queueName = 'cpq_poc';
$marker = 'poc-' . bin2hex(random_bytes(8));
$resultFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpq_queue_poc_' . $marker . '.json';

think\queue\Queue::push('app\common\job\cpq\PocJob', [
    'marker'      => $marker,
    'result_file' => $resultFile,
], $queueName);

$job = think\queue\Queue::pop($queueName);
expectPoc($job !== null, '队列 PoC：pop 未取到任务（Redis 连接或 think-queue 配置异常）');
$job->fire();
expectPoc(is_file($resultFile), '队列 PoC：任务未执行（结果文件不存在）');
$result = json_decode(file_get_contents($resultFile), true);
expectPoc($result['marker'] === $marker, '队列 PoC：回传 marker 不匹配');
unlink($resultFile);
echo "Queue PoC (think-queue Redis): PASS\n";

// ---- 2. Excel PoC：写入 + 读回 ----
$spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$spreadsheet->getActiveSheet()->setCellValue('A1', 'CPQ基线')->setCellValue('B1', '1.30');
$excelFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpq_excel_poc.xlsx';
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($excelFile);
$readBack = PhpOffice\PhpSpreadsheet\IOFactory::load($excelFile);
expectPoc($readBack->getActiveSheet()->getCell('A1')->getValue() === 'CPQ基线', 'Excel PoC：读回单元格内容不符');
unlink($excelFile);
echo "Excel PoC (PhpSpreadsheet): PASS\n";

// ---- 3. PDF PoC：中英文渲染 ----
$mpdf = new Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
$mpdf->WriteHTML('<h1>CPQ 报价单 Quote</h1><p>中文渲染测试 / English rendering test</p>');
$pdf = $mpdf->Output('', Mpdf\Output\Destination::STRING_RETURN);
expectPoc(strncmp($pdf, '%PDF', 4) === 0 && strlen($pdf) > 5000, 'PDF PoC：生成的 PDF 内容无效');
echo "PDF PoC (mpdf " . Mpdf\Mpdf::VERSION . "): PASS\n";

echo "CPQ component PoC: PASS\n";
