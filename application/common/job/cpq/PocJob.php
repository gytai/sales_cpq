<?php
// CPQ M0 队列 PoC 任务：被 think-queue Redis 驱动投递/消费后写回结果文件
// 仅用于 tests/cpq/poc.php 的可复核验证，不承担业务逻辑
namespace app\common\job\cpq;

use think\queue\Job;

class PocJob
{
    public function fire(Job $job, $data)
    {
        if (empty($data['result_file']) || empty($data['marker'])) {
            $job->delete();
            return;
        }
        file_put_contents($data['result_file'], json_encode([
            'marker'    => $data['marker'],
            'attempts'  => $job->attempts(),
            'consumed'  => date('c'),
        ]));
        $job->delete();
    }
}
