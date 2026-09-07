<?php

namespace app\common\job;

use app\common\service\cpq\AsyncJobService;
use app\common\service\cpq\ExportJobService;
use app\common\service\cpq\ImportJobService;
use think\queue\Job;

class CpqAsyncJob
{
    public function fire(Job $queueJob, $data)
    {
        $key = (string)($data['job_key'] ?? '');
        $jobs = new AsyncJobService();
        try {
            $job = $key !== '' ? $jobs->claim($key) : null;
            if (!$job) {
                $queueJob->delete();
                return;
            }
            switch ($job['type']) {
                case 'excel_import':
                    (new ImportJobService($jobs))->process($job);
                    break;
                case 'excel_export':
                    (new ExportJobService($jobs))->process($job);
                    break;
                default:
                    throw new \RuntimeException('任务类型尚未配置安全处理器');
            }
            $queueJob->delete();
        } catch (\Throwable $exception) {
            if ($key !== '') {
                try { $jobs->fail($key, 'CPQ_JOB_FAILED', $exception->getMessage()); } catch (\Throwable $ignored) {}
            }
            trace('CpqAsyncJob failed: job=' . $key . ' error=' . $exception->getMessage(), 'error');
            $queueJob->delete();
        }
    }
}
