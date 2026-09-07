<?php

namespace app\api\controller\cpq;

use app\common\controller\Api;
use app\common\service\cpq\AsyncJobService;

class Job extends Api
{
    public function detail($id = null)
    {
        $this->respond(function () use ($id) { return (new AsyncJobService())->status((string)$id, (int)$this->auth->id); });
    }

    public function retry($id = null)
    {
        if (!$this->request->isPost()) $this->error('Method Not Allowed', null, 405);
        $this->respond(function () use ($id) { return (new AsyncJobService())->retry((string)$id, (int)$this->auth->id); });
    }

    public function errorReport($id = null)
    {
        $file=(new AsyncJobService())->prepareErrorReport((string)$id, (int)$this->auth->id);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.basename($file['filename']).'"');
        header('Content-Length: '.$file['size']); readfile($file['absolute_path']); exit;
    }

    private function respond(callable $callback)
    {
        $trace = bin2hex(random_bytes(12));
        try { $payload = $callback(); }
        catch (\InvalidArgumentException $e) { $this->error($e->getMessage(), ['business_code'=>'CPQ_JOB_INVALID','trace_id'=>$trace], 422); }
        catch (\Throwable $e) { $this->error('任务服务异常', ['business_code'=>'CPQ_INTERNAL_ERROR','trace_id'=>$trace], 500); }
        $this->success('OK', ['business_code'=>'OK','trace_id'=>$trace,'payload'=>$payload]);
    }
}
