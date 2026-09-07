<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

/** 两阶段 Excel 导入：preview 绝不写业务表，confirm 后才创建任务。 */
class ImportJobService
{
    private $jobs;

    public function __construct(AsyncJobService $jobs = null)
    {
        $this->jobs = $jobs ?: new AsyncJobService();
    }

    public function preview($type, array $rows, $requestedBy, $ttl = 1800)
    {
        if (count($rows) > 100000) {
            throw new InvalidArgumentException('单次导入不能超过 100,000 行');
        }
        $result = (new ImportPreviewService())->preview((string)$type, $rows);
        $token = bin2hex(random_bytes(24));
        $now = time();
        $id = Db::name('cpq_import_batch')->insertGetId([
            'type' => (string)$type,
            'preview_token_hash' => hash('sha256', $token),
            'preview_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'previewed', 'row_count' => count($rows),
            'requested_by' => (int)$requestedBy,
            'expires_at' => $now + max(300, (int)$ttl),
            'createtime' => $now, 'updatetime' => $now,
        ]);
        return [
            'import_id' => (int)$id,
            'preview_token' => $token,
            'expires_at' => $now + max(300, (int)$ttl),
            'requires_async' => AsyncJobService::requiresAsync(count($rows)),
            'preview' => $result,
        ];
    }

    public function confirm($importId, $previewToken, $requestedBy, $dispatch = true)
    {
        Db::startTrans();
        try {
            $batch = Db::name('cpq_import_batch')->where('id', (int)$importId)->lock(true)->find();
            if (!$batch) throw new InvalidArgumentException('导入预览不存在');
            if ((int)$batch['requested_by'] !== (int)$requestedBy) throw new InvalidArgumentException('无权确认该导入');
            if (!hash_equals((string)$batch['preview_token_hash'], hash('sha256', (string)$previewToken))) {
                throw new InvalidArgumentException('导入确认令牌无效');
            }
            if ((int)$batch['expires_at'] < time()) {
                Db::name('cpq_import_batch')->where('id', (int)$importId)->update(['status' => 'expired', 'updatetime' => time()]);
                throw new InvalidArgumentException('导入预览已过期，请重新预览');
            }
            if ($batch['job_id']) {
                $job = Db::name('cpq_job')->where('id', (int)$batch['job_id'])->find();
                Db::commit();
                return $this->jobs->publicJob($job);
            }
            $preview = json_decode((string)$batch['preview_json'], true) ?: [];
            if (!empty($preview['error_count'])) {
                throw new InvalidArgumentException('预览仍有错误，不能确认导入');
            }
            $created = $this->jobs->create('excel_import', 'import', (string)$importId, [
                'import_batch_id' => (int)$importId,
            ], (int)$requestedBy, (int)$batch['row_count'], 'import:' . (int)$importId);
            $raw = $this->jobs->raw($created['job_key']);
            Db::name('cpq_import_batch')->where('id', (int)$importId)->update([
                'job_id' => (int)$raw['id'], 'status' => 'confirmed',
                'confirmed_at' => time(), 'updatetime' => time(),
            ]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $dispatch ? $this->jobs->dispatch($created['job_key']) : $created;
    }

    public function process(array $job)
    {
        $payload = json_decode((string)$job['payload_json'], true) ?: [];
        $batch = Db::name('cpq_import_batch')->where('id', (int)($payload['import_batch_id'] ?? 0))->find();
        if (!$batch) throw new RuntimeException('导入批次不存在');
        $preview = json_decode((string)$batch['preview_json'], true) ?: [];
        $items = $preview['items'] ?? [];
        if (!is_array($items) || !empty($preview['error_count'])) throw new RuntimeException('导入预览无效');

        Db::name('cpq_import_batch')->where('id', (int)$batch['id'])->update(['status' => 'processing', 'updatetime' => time()]);
        Db::startTrans();
        try {
            $changed = 0;
            foreach ($items as $item) {
                $this->persist((string)$batch['type'], $item);
                $changed++;
            }
            Db::name('cpq_import_batch')->where('id', (int)$batch['id'])->update(['status' => 'succeeded', 'updatetime' => time()]);
            (new AuditLogService())->record('import', 'cpq_import_batch', (int)$batch['id'], ['type' => $batch['type'], 'row_count' => $changed]);
            Db::commit();
            return $this->jobs->succeed($job['job_key'], ['imported_count' => $changed, 'type' => $batch['type']]);
        } catch (\Throwable $exception) {
            Db::rollback();
            Db::name('cpq_import_batch')->where('id', (int)$batch['id'])->update(['status' => 'failed', 'updatetime' => time()]);
            throw $exception;
        }
    }

    private function persist($type, array $item)
    {
        $now = time();
        if ($type === 'customer') {
            $data = $this->only($item, ['code','name','name_en','type','credit_code','country_code','region_id','customer_level_id','agent_id','default_currency','tax_no','payment_terms','trade_terms','sales_org_id','owner_id','status','remark']);
            $data += ['type'=>'direct','country_code'=>'CN','default_currency'=>'CNY','owner_id'=>0,'status'=>'normal'];
            return $this->upsert('cpq_customer', ['code' => $data['code']], $data, $now);
        }
        if ($type === 'price_entry') {
            $bookId = (int)($item['price_book_id'] ?? 0);
            if (!$bookId && !empty($item['price_book_code'])) {
                $bookId = (int)Db::name('cpq_price_book')->where('code', $item['price_book_code'])->order('version desc')->value('id');
            }
            if (!$bookId) throw new RuntimeException('价格表不存在：' . ($item['price_book_code'] ?? ''));
            $data = $this->only($item, ['target_type','target_id','amount','unit','min_qty','max_qty']);
            $data['price_book_id'] = $bookId;
            $data += ['unit'=>'item','min_qty'=>'0'];
            return $this->upsert('cpq_price_entry', ['price_book_id'=>$bookId,'target_type'=>$data['target_type'],'target_id'=>$data['target_id'],'min_qty'=>$data['min_qty']], $data, $now);
        }
        if ($type === 'price_policy') {
            $data = $this->only($item, ['code','name','company','business_unit','market_scope','region_code','customer_level','agent_level','customer_id','agent_id','product_line','target_type','target_id','currency','unit','guide_price','line_floor','company_floor','cost','priority','effective_date','expiry_date','version','status']);
            $data += ['name'=>$data['code'],'market_scope'=>'all','currency'=>'CNY','unit'=>'item','cost'=>'0','priority'=>0,'effective_date'=>date('Y-m-d'),'version'=>1,'status'=>'draft'];
            $dimensions = $data; unset($dimensions['name'],$dimensions['guide_price'],$dimensions['line_floor'],$dimensions['company_floor'],$dimensions['cost'],$dimensions['priority'],$dimensions['effective_date'],$dimensions['expiry_date'],$dimensions['version'],$dimensions['status']); ksort($dimensions);
            $data['dimension_key'] = hash('sha256', json_encode($dimensions, JSON_UNESCAPED_UNICODE));
            return $this->upsert('cpq_price_policy', ['code'=>$data['code'],'version'=>$data['version']], $data, $now);
        }
        $specs = [
            'exchange_rate' => ['cpq_exchange_rate', ['source_currency','target_currency','rate','source','effective_date','expiry_date','status'], ['source_currency','target_currency','effective_date'], ['source'=>'manual','status'=>'normal']],
            'tax_rule' => ['cpq_tax_rule', ['code','country_code','region_code','product_type','rate','effective_date','expiry_date','status'], ['code','effective_date'], ['status'=>'normal']],
            'fee_rule' => ['cpq_fee_rule', ['code','name','fee_type','condition_json','calculation_type','value','currency','include_in_margin','include_in_floor','priority','effective_date','expiry_date','status'], ['code','effective_date'], ['condition_json'=>'{}','currency'=>'CNY','include_in_margin'=>1,'include_in_floor'=>1,'priority'=>0,'effective_date'=>date('Y-m-d'),'status'=>'normal']],
        ];
        if (!isset($specs[$type])) throw new RuntimeException('未实现的导入类型');
        list($table,$fields,$keys,$defaults) = $specs[$type];
        $data = $this->only($item, $fields) + $defaults;
        $where = []; foreach ($keys as $key) $where[$key] = $data[$key];
        return $this->upsert($table, $where, $data, $now);
    }

    private function upsert($table, array $where, array $data, $now)
    {
        $existing = Db::name($table)->where($where)->find();
        $data['updatetime'] = $now;
        if ($existing) return Db::name($table)->where('id', (int)$existing['id'])->update($data);
        $data['createtime'] = $now;
        return Db::name($table)->insertGetId($data);
    }

    private function only(array $item, array $fields)
    {
        return array_intersect_key($item, array_flip($fields));
    }
}
