<?php

namespace app\api\controller\cpq;

use app\common\controller\Api;
use app\common\service\cpq\ImportJobService;

class Import extends Api
{
    public function create($type = null)
    {
        $payload = $this->payload();
        $this->respond(function () use ($type, $payload) { return (new ImportJobService())->preview((string)$type, (array)($payload['rows'] ?? []), (int)$this->auth->id); }, 202);
    }

    public function confirm($id = null)
    {
        $payload = $this->payload();
        $this->respond(function () use ($id, $payload) { return (new ImportJobService())->confirm((int)$id, (string)($payload['preview_token'] ?? ''), (int)$this->auth->id); }, 202);
    }

    private function payload()
    {
        $raw = file_get_contents('php://input'); $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : $this->request->post();
    }

    private function respond(callable $callback, $http)
    {
        $trace=bin2hex(random_bytes(12));
        try { $payload=$callback(); }
        catch (\InvalidArgumentException $e) { $this->error($e->getMessage(), ['business_code'=>'CPQ_IMPORT_INVALID','trace_id'=>$trace], 422); }
        catch (\Throwable $e) { $this->error('导入服务异常', ['business_code'=>'CPQ_INTERNAL_ERROR','trace_id'=>$trace], 500); }
        $this->success('Accepted', ['business_code'=>'OK','trace_id'=>$trace,'payload'=>$payload], $http);
    }
}
