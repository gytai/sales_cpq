<?php

namespace app\common\job;

use think\queue\Job;

/**
 * CPQ 队列链路冒烟任务（M0 PoC 专用）
 * 消费成功后写入标记文件，供 tests/cpq/poc.php 校验 Redis/Sync 链路真实可用
 */
class CpqSmokeJob
{
    const MARKER_NAME = 'cpq_queue_smoke.json';

    public function fire(Job $job, $data)
    {
        $marker = self::markerPath();
        $written = file_put_contents($marker, json_encode([
            'token'      => isset($data['token']) ? $data['token'] : null,
            'queue'      => $job->getQueue(),
            'attempts'   => $job->attempts(),
            'fired_at'   => date('c'),
        ], JSON_UNESCAPED_UNICODE));

        if ($written === false) {
            // 标记不可写视为失败，让队列按 tries 重试/记录
            $job->release(1);
            return;
        }

        $job->delete();
    }

    public function failed($data)
    {
        trace('CpqSmokeJob failed: ' . json_encode($data, JSON_UNESCAPED_UNICODE), 'error');
    }

    public static function markerPath()
    {
        return rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . self::MARKER_NAME;
    }
}
