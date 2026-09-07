<?php
/** GYTAI-73：异步任务、导入导出、编号、字典与集成 outbox 验收。 */

define('APP_PATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR);
require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'thinkphp' . DIRECTORY_SEPARATOR . 'base.php';

think\Loader::addNamespace('app', APP_PATH);
think\Config::set('database', include APP_PATH . 'database.php');

use app\common\service\cpq\AsyncJobService;
use app\common\service\cpq\DictionaryService;
use app\common\service\cpq\ExportJobService;
use app\common\service\cpq\ImportJobService;
use app\common\service\cpq\IntegrationAuthService;
use app\common\service\cpq\IntegrationCredentialService;
use app\common\service\cpq\IntegrationEventService;
use app\common\service\cpq\NumberRuleService;
use app\common\service\cpq\SchedulerService;
use think\Config;
use think\Db;

const TEST_DB = 'cpq_m3_platform_test';
const PREFIX = 'fa_';
if (($argv[1] ?? '') === '--number-worker') {
    Config::set('database.database', TEST_DB);
    echo (new NumberRuleService())->next('TESTQUOTE', 'RACE', strtotime('2026-09-05'));
    exit(0);
}
$assertCount = 0;
function checkPlatform($condition, $message) { global $assertCount; $assertCount++; if (!$condition) throw new RuntimeException('[FAIL] '.$message); echo "[ok] {$message}\n"; }
function throwsPlatform(callable $fn, $message) { global $assertCount; $assertCount++; try { $fn(); } catch (\Throwable $e) { echo "[ok] {$message}（{$e->getMessage()}）\n"; return $e; } throw new RuntimeException('[FAIL] '.$message); }

$db = Config::get('database');
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $db['hostname'], $db['hostport'] ?: 3306);
$pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS `'.TEST_DB.'`');
$pdo->exec('CREATE DATABASE `'.TEST_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$pdo->exec('USE `'.TEST_DB.'`');
$pdo->exec(str_replace('__PREFIX__', PREFIX, file_get_contents(dirname(__DIR__, 2).'/database/cpq/install.sql')));
$pdo->exec('CREATE TABLE `fa_auth_group` (`id` int unsigned primary key, `name` varchar(100), `rules` text, `status` varchar(30)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE `fa_auth_group_access` (`uid` int unsigned, `group_id` int unsigned) ENGINE=InnoDB');
register_shutdown_function(function () use ($dsn,$db) { try { (new PDO($dsn,$db['username'],$db['password']))->exec('DROP DATABASE IF EXISTS `'.TEST_DB.'`'); } catch (\Throwable $e) {} });
Config::set('database.database', TEST_DB);

echo "\n== 编号与字典 ==\n";
$ruleId=Db::name('cpq_number_rule')->insertGetId(['code'=>'TESTQUOTE','name'=>'报价编号','pattern'=>'Q-{YYYY}-{SCOPE}-{SEQ4}','period_type'=>'year','initial_value'=>1,'status'=>'enabled','createtime'=>time(),'updatetime'=>time()]);
$numbers=[]; for($i=0;$i<50;$i++) $numbers[]=(new NumberRuleService())->next('TESTQUOTE','CN',strtotime('2026-09-05'));
checkPlatform(count(array_unique($numbers))===50 && $numbers[0]==='Q-2026-CN-0001' && $numbers[49]==='Q-2026-CN-0050','同周期编号连续且不重号');
checkPlatform(Db::name('cpq_number_counter')->where('rule_id',$ruleId)->count()===1,'唯一计数器行承担并发锁');
$workers=[];
for($i=0;$i<12;$i++) {
    $pipes=[];
    $process=proc_open([PHP_BINARY,__FILE__,'--number-worker'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    if (is_resource($process)) { fclose($pipes[0]); $workers[]=[$process,$pipes]; }
}
$raced=[];
foreach($workers as [$process,$pipes]) {
    $raced[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0) throw new RuntimeException('编号并发子进程失败：'.$error);
}
sort($raced);
checkPlatform(count($raced)===12 && count(array_unique($raced))===12 && $raced[0]==='Q-2026-RACE-0001' && $raced[11]==='Q-2026-RACE-0012','12 路并发编号无重号且无断号');
$dictId=Db::name('cpq_dictionary_value')->insertGetId(['dictionary_code'=>'currency','value_code'=>'CNY','label'=>'人民币','status'=>'enabled','createtime'=>time(),'updatetime'=>time()]);
$dict=new DictionaryService(); $dict->reference($dictId,'quote','1');
throwsPlatform(function()use($dict,$dictId){$dict->updateValue($dictId,['label'=>'人民币元']);},'已引用字典不可修改');
throwsPlatform(function()use($dict,$dictId){$dict->deleteValue($dictId);},'已引用字典不可删除');
checkPlatform($dict->updateValue($dictId,['status'=>'disabled'])['status']==='disabled','已引用字典允许停用');

echo "\n== 统一任务与导入确认 ==\n";
$jobs=new AsyncJobService();
$job=$jobs->create('email','quote','1',['recipient'=>'masked@example.invalid'],7,1,'idem-email',2);
$same=$jobs->create('email','quote','1',['recipient'=>'different@example.invalid'],7,1,'idem-email',2);
checkPlatform($job['job_key']===$same['job_key'],'相同幂等键只创建一个任务');
$otherUserJob=$jobs->create('email','quote','1',['recipient'=>'masked@example.invalid'],8,1,'idem-email',2);
checkPlatform($otherUserJob['job_key']!==$job['job_key'],'相同显式幂等键在不同创建人之间不复用任务');
$implicitA=$jobs->create('email','quote','2',['recipient'=>'a@example.invalid'],7,1,'',2);
$implicitB=$jobs->create('email','quote','2',['recipient'=>'a@example.invalid'],8,1,'',2);
$implicitA2=$jobs->create('email','quote','2',['recipient'=>'a@example.invalid'],7,1,'',2);
checkPlatform($implicitA['job_key']!==$implicitB['job_key'] && $implicitA['job_key']===$implicitA2['job_key'],'隐式幂等哈希按创建人隔离且同人重试复用');
checkPlatform(!array_key_exists('payload_json',$job) && !array_key_exists('idempotency_hash',$job),'任务状态不回显请求载荷和幂等哈希');
$claimed=$jobs->claim($job['job_key']); $jobs->fail($job['job_key'],'SEND_FAILED','token=abc123 timeout');
$failed=$jobs->status($job['job_key'],7);
checkPlatform($failed['status']==='failed' && strpos($failed['error_message'],'abc123')===false,'失败原因脱敏并进入 failed');
$report=$jobs->prepareErrorReport($job['job_key'],7);
checkPlatform($failed['has_error_report']===true && strpos(file_get_contents($report['absolute_path']),'abc123')===false,'失败任务生成可下载的脱敏错误报告');
throwsPlatform(function()use($jobs,$job){$jobs->prepareErrorReport($job['job_key'],8);},'非创建人不能下载错误报告');
@unlink($report['absolute_path']);
$retried=$jobs->retry($job['job_key'],7,false);
checkPlatform($retried['status']==='pending' && (int)$retried['retry_count']===1,'失败任务可受控重试');
checkPlatform(AsyncJobService::requiresAsync(5001) && !AsyncJobService::requiresAsync(5000),'>5,000 行强制异步边界正确');

$imports=new ImportJobService($jobs);
$preview=$imports->preview('customer',[['code'=>'IMP-C-1','name'=>'导入客户','default_currency'=>'CNY']],7);
checkPlatform($preview['preview']['valid_count']===1 && Db::name('cpq_customer')->where('code','IMP-C-1')->count()===0,'导入预览不写业务表');
throwsPlatform(function()use($imports,$preview){$imports->confirm($preview['import_id'],'wrong',7,false);},'错误确认令牌被拒绝');
$importJob=$imports->confirm($preview['import_id'],$preview['preview_token'],7,false);
$raw=$jobs->claim($importJob['job_key']); $imports->process($raw);
checkPlatform(Db::name('cpq_customer')->where('code','IMP-C-1')->count()===1 && $jobs->status($importJob['job_key'],7)['status']==='succeeded','确认后任务写入业务表并成功');

echo "\n== 凭证、签名与 Outbox ==\n";
$vault=new IntegrationCredentialService('unit-test-master-key');
$config=$vault->save(['code'=>'ERP-TEST','name'=>'ERP测试','system_type'=>'erp','base_url'=>'https://erp.example.invalid','auth_type'=>'hmac','hmac_algorithm'=>'sha256','timeout_ms'=>1200,'max_retries'=>1,'status'=>'enabled','credentials'=>['hmac_secret'=>'never-return-this']]);
$stored=Db::name('cpq_integration_config')->where('id',$config['id'])->find();
checkPlatform(!isset($config['credential_ciphertext']) && !isset($config['credential_nonce']) && $config['credentials_configured']===true,'接口配置不回显密文或明文凭证');
checkPlatform(strpos($stored['credential_ciphertext'],'never-return-this')===false && $vault->credentials($config['id'])['hmac_secret']==='never-return-this','凭证加密落库且可供内部签名');
$updatedConfig=$vault->save(['name'=>'ERP测试-更新'],$config['id']);
checkPlatform($updatedConfig['name']==='ERP测试-更新' && $vault->credentials($config['id'])['hmac_secret']==='never-return-this','普通配置更新不清空或回显既有凭证');
$auth=new IntegrationAuthService(); $sig=$auth->signHmac('{"x":1}',1000,'secret');
checkPlatform($auth->verifyHmac('{"x":1}',1000,$sig,'secret','sha256',300,1001),'合法 HMAC 签名通过');
checkPlatform(!$auth->verifyHmac('{"x":2}',1000,$sig,'secret','sha256',300,1001),'篡改载荷签名失败');

$events=new IntegrationEventService();
$event=$events->enqueue('quote.approved','quote','88',['quote_id'=>88],$config['id'],'crm-event-1');
$duplicate=$events->enqueue('quote.approved','quote','88',['quote_id'=>999],$config['id'],'crm-event-1');
checkPlatform((int)$event['id']===(int)$duplicate['id'],'重复来源事件被 outbox 幂等去重');
$claimedEvent=$events->claimDue(); $once=$events->markFailed($claimedEvent['id'],'Authorization: Bearer top-secret',1000);
checkPlatform($once['status']==='failed' && strpos($once['last_error'],'top-secret')===false,'集成失败脱敏并安排重试');
Db::name('cpq_integration_event')->where('id',$event['id'])->update(['next_retry_at'=>1000]);
$claimedAgain=$events->claimDue(1000); $dead=$events->markFailed($claimedAgain['id'],'timeout',1000);
checkPlatform($dead['status']==='dead' && (int)$dead['retry_count']===2,'超过最大重试次数进入死信');

echo "\n== 调度器 ==\n";
$bookId=Db::name('cpq_price_book')->insertGetId(['code'=>'SCHED-PB','name'=>'计划价格表','currency'=>'CNY','effective_date'=>date('Y-m-d'),'version'=>1,'status'=>'pending','createtime'=>time(),'updatetime'=>time()]);
$releaseId=Db::name('cpq_release_version')->insertGetId(['object_type'=>'cpq_price_book','object_id'=>$bookId,'version'=>1,'content_hash'=>hash('sha256','scheduled'),'planned_effective_at'=>time()-1,'status'=>'pending','createtime'=>time(),'updatetime'=>time()]);
$oldQuote=Db::name('cpq_quote')->insertGetId(['code'=>'SCHED-Q','name'=>'过期报价','owner_id'=>1,'product_line'=>'SCHED','currency'=>'CNY','status'=>'approved','current_revision_no'=>1,'submitted_at'=>time()-31*86400,'createtime'=>time()-31*86400,'updatetime'=>time()]);
$retryEvent=$events->enqueue('quote.sync','quote',(string)$oldQuote,['quote_id'=>$oldQuote],$config['id'],'retry-event');
Db::name('cpq_integration_event')->where('id',$retryEvent['id'])->update(['status'=>'failed','next_retry_at'=>time()-1]);
$scheduled=(new SchedulerService())->run(time(),30,100);
checkPlatform($scheduled['published']===1 && Db::name('cpq_release_version')->where('id',$releaseId)->value('status')==='published','定时发布生效');
checkPlatform($scheduled['quotes_expired']===1 && Db::name('cpq_quote')->where('id',$oldQuote)->value('status')==='expired','报价到期自动失效');
checkPlatform($scheduled['integration_requeued']===1 && Db::name('cpq_integration_event')->where('id',$retryEvent['id'])->value('status')==='pending','到期同步重试重新排队');

echo "\n== 越权导出下载 ==\n";
$now=time();
Db::name('auth_group')->insert(['id'=>1,'name'=>'system_admin','rules'=>'*','status'=>'normal']);
Db::name('auth_group_access')->insert(['uid'=>10,'group_id'=>1]);
$seriesId=Db::name('cpq_product_series')->insertGetId(['code'=>'EXP-S','name'=>'导出系列','product_line'=>'EXP-LINE','version'=>1,'status'=>'published','createtime'=>$now,'updatetime'=>$now]);
$modelId=Db::name('cpq_product_model')->insertGetId(['series_id'=>$seriesId,'code'=>'EXP-M','name'=>'导出型号','version'=>1,'status'=>'published','createtime'=>$now,'updatetime'=>$now]);
$quoteId=Db::name('cpq_quote')->insertGetId(['code'=>'EXP-Q','name'=>'=HYPERLINK("http://evil.invalid","x")','owner_id'=>10,'product_line'=>'EXP-LINE','currency'=>'CNY','status'=>'approved','current_revision_no'=>1,'createtime'=>$now,'updatetime'=>$now]);
$lineId=Db::name('cpq_quote_line')->insertGetId(['quote_id'=>$quoteId,'line_no'=>1,'model_id'=>$modelId,'quantity'=>'2','createtime'=>$now,'updatetime'=>$now]);
$revisionId=Db::name('cpq_quote_revision')->insertGetId(['quote_id'=>$quoteId,'revision_no'=>1,'status'=>'approved','createtime'=>$now,'updatetime'=>$now]);
Db::name('cpq_quote_price_snapshot')->insert(['revision_id'=>$revisionId,'quote_line_id'=>$lineId,'model_id'=>$modelId,'model_code'=>'EXP-M','quantity'=>'2','pricing_currency'=>'CNY','quote_currency'=>'CNY','control_unit_price'=>'80','untaxed_amount'=>'160','tax_amount'=>'20.8','total_amount'=>'180.8','createtime'=>$now]);
$exportService=new ExportJobService($jobs);
$realExport=$exportService->createQuoteExport(['product_line'=>'EXP-LINE','idempotency_key'=>'real-export'],10,false);
$realRaw=$jobs->claim($realExport['job_key']); $realDone=$exportService->process($realRaw);
$realFile=$jobs->raw($realExport['job_key']);
checkPlatform($realDone['status']==='succeeded' && $realDone['result']['row_count']===1 && is_file($realFile['file_path']),'报价明细异步生成 XLSX 文件');
checkPlatform(!isset($realDone['result']['download_token']) && !isset($realDone['result']['download_token_hash']),'公开任务结果不含下载令牌或其哈希');
$realToken=$exportService->claimDownloadToken($realExport['job_key'],10);
throwsPlatform(function()use($exportService,$realExport){$exportService->claimDownloadToken($realExport['job_key'],10);},'下载令牌领取一次后即烧毁');
$realDownload=$exportService->prepareDownload($realExport['job_key'],$realToken,10);
checkPlatform($realDownload['size']>1000,'XLSX 短期令牌下载成功');
$sheet=(new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($realFile['file_path'])->getActiveSheet();
checkPlatform($sheet->getCell('B2')->getDataType()===\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,'导出单元格全部显式字符串写入，公式注入不生效');
checkPlatform(strpos((string)$sheet->getCell('B2')->getValue(),'=HYPERLINK')===0,'注入载荷按字面字符串保留而非执行为公式');
throwsPlatform(function()use($exportService,$realExport,$realToken){$exportService->prepareDownload($realExport['job_key'],$realToken,10);},'下载成功后令牌立即烧毁，重复下载被拒绝');
@unlink($realFile['file_path']);

$exportJob=$jobs->create('excel_export','quote_detail','',['filters'=>[]],10,0,'export-auth-test');
$rawExport=$jobs->claim($exportJob['job_key']);
$file=RUNTIME_PATH.'cpq-export-auth-test.xlsx'; file_put_contents($file,'test'); $downloadToken='download-secret';
$jobs->succeed($exportJob['job_key'],['row_count'=>0,'download_token'=>$downloadToken],['file_path'=>$file,'file_hash'=>hash_file('sha256',$file),'download_token_hash'=>hash('sha256',$downloadToken),'expires_at'=>time()+600]);
throwsPlatform(function()use($exportJob,$downloadToken){(new ExportJobService())->prepareDownload($exportJob['job_key'],$downloadToken,11);},'非创建人越权下载被拒绝');
$exportJobId=(int)Db::name('cpq_job')->where('job_key',$exportJob['job_key'])->value('id');
checkPlatform(Db::name('cpq_download_log')->where('result','denied')->where('job_id',$exportJobId)->count()===1,'越权下载写入审计');
$allowed=(new ExportJobService())->prepareDownload($exportJob['job_key'],$downloadToken,10);
checkPlatform($allowed['size']===4 && Db::name('cpq_download_log')->where('result','allowed')->count()===2,'创建人下载哈希校验通过并审计');
@unlink($file);

echo "\nM3 async/integration platform tests: PASS ({$assertCount} assertions)\n";
