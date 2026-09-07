<?php

namespace app\common\service\cpq;

use app\common\job\CpqAsyncJob;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use think\Queue;

/** PDF/Excel/同步/邮件共用的任务状态、幂等、抢占与受控重试。 */
class AsyncJobService
{
    const LARGE_ROW_THRESHOLD = 5000;
    const STATUS_FAILED = 'failed';
    const TYPES = ['pdf', 'excel_import', 'excel_export', 'erp_sync', 'email', 'scheduled_publish'];
    const TERMINAL = ['succeeded', 'cancelled'];

    public function create($type, $businessType, $businessId, array $payload, $requestedBy, $rowCount = 0, $idempotencyKey = '', $maxRetries = 3)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('不支持的异步任务类型');
        }
        $rowCount = max(0, (int)$rowCount);
        $canonical = $this->canonicalJson($payload);
        // 幂等键按创建人隔离：同一显式/隐式键在不同用户之间不复用任务，
        // 防止跨用户命中他人导出/打印任务进而领取其下载令牌。
        $idemSource = trim((string)$idempotencyKey);
        if ($idemSource === '') {
            $idemSource = implode('|', [$type, $businessType, $businessId, (int)$requestedBy, hash('sha256', $canonical)]);
        } else {
            $idemSource = (int)$requestedBy . '|' . $idemSource;
        }
        $idemHash = hash('sha256', $idemSource);
        $existing = Db::name('cpq_job')->where('idempotency_hash', $idemHash)->find();
        if ($existing) {
            return $this->publicJob($existing);
        }
        $now = time();
        try {
            $id = Db::name('cpq_job')->insertGetId([
                'job_key' => bin2hex(random_bytes(16)),
                'type' => $type,
                'business_type' => (string)$businessType,
                'business_id' => (string)$businessId,
                'idempotency_hash' => $idemHash,
                'status' => 'pending',
                'progress' => 0,
                'row_count' => $rowCount,
                'payload_json' => $canonical,
                'max_retries' => max(0, min(10, (int)$maxRetries)),
                'requested_by' => (int)$requestedBy,
                'createtime' => $now,
                'updatetime' => $now,
            ]);
        } catch (\Throwable $exception) {
            // 并发相同幂等键只有一个创建者；唯一键失败后读取赢家。
            $existing = Db::name('cpq_job')->where('idempotency_hash', $idemHash)->find();
            if ($existing) {
                return $this->publicJob($existing);
            }
            throw $exception;
        }
        return $this->publicJob(Db::name('cpq_job')->where('id', (int)$id)->find());
    }

    public function dispatch($jobKey)
    {
        $job = $this->raw($jobKey);
        if ($job['status'] !== 'pending') {
            return $this->publicJob($job);
        }
        Queue::push(CpqAsyncJob::class, ['job_key' => $job['job_key']], 'default');
        return $this->status($jobKey);
    }

    /** 原子抢占，重复 worker 不会执行同一任务。 */
    public function claim($jobKey)
    {
        $changed = Db::name('cpq_job')->where('job_key', (string)$jobKey)->where('status', 'pending')->update([
            'status' => 'processing', 'progress' => 1, 'started_at' => time(), 'updatetime' => time(),
        ]);
        return $changed ? $this->raw($jobKey) : null;
    }

    public function succeed($jobKey, array $result = [], array $file = [])
    {
        $changes = [
            'status' => 'succeeded', 'progress' => 100,
            'result_json' => $this->canonicalJson($result),
            'error_code' => '', 'error_message' => '',
            'finished_at' => time(), 'updatetime' => time(),
        ];
        foreach (['file_path', 'file_hash', 'download_token_hash', 'expires_at', 'error_report_path'] as $field) {
            if (array_key_exists($field, $file)) {
                $changes[$field] = $file[$field];
            }
        }
        Db::name('cpq_job')->where('job_key', (string)$jobKey)->where('status', 'processing')->update($changes);
        return $this->status($jobKey);
    }

    public function fail($jobKey, $code, $message, $errorReportPath = '')
    {
        $safeMessage = $this->sanitizeError($message);
        if ($errorReportPath === '') {
            $dir = rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'cpq_job_errors';
            if ((is_dir($dir) || @mkdir($dir, 0770, true)) && is_dir($dir)) {
                $errorReportPath = $dir . DIRECTORY_SEPARATOR . preg_replace('/[^a-f0-9]/', '', (string)$jobKey) . '.json';
                file_put_contents($errorReportPath, json_encode(['business_code'=>(string)$code,'message'=>$safeMessage,'created_at'=>date(DATE_ATOM)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }
        Db::name('cpq_job')->where('job_key', (string)$jobKey)->where('status', 'processing')->update([
            'status' => 'failed', 'error_code' => substr((string)$code, 0, 64),
            'error_message' => $safeMessage,
            'error_report_path' => (string)$errorReportPath,
            'finished_at' => time(), 'updatetime' => time(),
        ]);
        return $this->status($jobKey);
    }

    public function retry($jobKey, $requestedBy, $dispatch = true)
    {
        $job = $this->raw($jobKey);
        if ($job['status'] !== 'failed') {
            throw new InvalidArgumentException('仅失败任务可以重试');
        }
        if ((int)$job['requested_by'] !== (int)$requestedBy && !$this->isPrivileged($requestedBy)) {
            throw new InvalidArgumentException('无权重试该任务');
        }
        if ((int)$job['retry_count'] >= (int)$job['max_retries']) {
            throw new InvalidArgumentException('任务已达到最大重试次数');
        }
        Db::name('cpq_job')->where('id', (int)$job['id'])->where('status', 'failed')->update([
            'status' => 'pending', 'progress' => 0,
            'retry_count' => (int)$job['retry_count'] + 1,
            'error_code' => '', 'error_message' => '',
            'started_at' => null, 'finished_at' => null, 'updatetime' => time(),
        ]);
        return $dispatch ? $this->dispatch($jobKey) : $this->status($jobKey, $requestedBy);
    }

    public function status($jobKey, $requestedBy = null)
    {
        $job = $this->raw($jobKey);
        if ($requestedBy !== null && (int)$job['requested_by'] !== (int)$requestedBy && !$this->isPrivileged($requestedBy)) {
            throw new InvalidArgumentException('无权查看该任务');
        }
        return $this->publicJob($job);
    }

    public static function requiresAsync($rowCount, $requestedAsync = false)
    {
        return (int)$rowCount > self::LARGE_ROW_THRESHOLD || (bool)$requestedAsync;
    }

    public function raw($jobKey)
    {
        $job = Db::name('cpq_job')->where('job_key', (string)$jobKey)->find();
        if (!$job) {
            throw new InvalidArgumentException('任务不存在');
        }
        return $job;
    }

    public function publicJob(array $job)
    {
        $job['has_file'] = !empty($job['file_path']);
        $job['has_error_report'] = !empty($job['error_report_path']);
        unset($job['idempotency_hash'], $job['payload_json'], $job['download_token_hash']);
        unset($job['file_path'], $job['error_report_path']);
        $job['result'] = json_decode((string)($job['result_json'] ?? ''), true) ?: null;
        if (is_array($job['result'])) {
            // 下载令牌只允许经专用一次性渠道交付，公共状态递归剔除。
            $job['result'] = $this->stripDownloadTokens($job['result']);
        }
        unset($job['result_json']);
        return $job;
    }

    /**
     * @param array $data
     * @return array
     */
    private function stripDownloadTokens(array $data)
    {
        foreach ($data as $key => $value) {
            if (in_array((string)$key, ['download_token', 'download_token_hash'], true)) {
                unset($data[$key]);
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->stripDownloadTokens($value);
            }
        }
        return $data;
    }

    public function prepareErrorReport($jobKey, $requestedBy)
    {
        $job = $this->raw($jobKey);
        if ((int)$job['requested_by'] !== (int)$requestedBy && !$this->isPrivileged($requestedBy)) {
            throw new InvalidArgumentException('无权下载该错误报告');
        }
        if ($job['status'] !== 'failed' || empty($job['error_report_path']) || !is_file($job['error_report_path'])) {
            throw new InvalidArgumentException('错误报告不存在');
        }
        (new AuditLogService())->record('download_error_report', 'cpq_job', (int)$job['id'], ['error_code'=>$job['error_code']]);
        return ['absolute_path'=>$job['error_report_path'],'filename'=>'cpq-job-'.$job['job_key'].'-errors.json','size'=>filesize($job['error_report_path'])];
    }

    private function isPrivileged($adminId)
    {
        return (bool)array_intersect(SensitiveFieldService::rolesOfAdmin((int)$adminId), ['system_admin', 'auditor']);
    }

    private function sanitizeError($message)
    {
        $message = preg_replace('/authorization\s*[:=]\s*bearer\s+[^\s,;]+/i', 'authorization=[REDACTED]', (string)$message);
        $message = preg_replace('/(authorization|token|password|secret|client_secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $message);
        return mb_substr($message, 0, 500);
    }

    private function canonicalJson(array $value)
    {
        $sort = function (&$item) use (&$sort) {
            if (!is_array($item)) return;
            foreach ($item as &$nested) $sort($nested);
            unset($nested);
            if (array_keys($item) !== range(0, count($item) - 1)) ksort($item);
        };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
