<?php

namespace app\api\controller\cpq;

use app\common\controller\Api;
use app\common\service\cpq\ExportJobService;

class Export extends Api
{
    public function quotes()
    {
        $payload=$this->payload(); $trace=bin2hex(random_bytes(12));
        try { $job=(new ExportJobService())->createQuoteExport($payload, (int)$this->auth->id); }
        catch (\InvalidArgumentException $e) { $this->error($e->getMessage(), ['business_code'=>'CPQ_EXPORT_FORBIDDEN','trace_id'=>$trace], 403); }
        catch (\Throwable $e) { $this->error('导出服务异常', ['business_code'=>'CPQ_INTERNAL_ERROR','trace_id'=>$trace], 500); }
        $this->success('Accepted', ['business_code'=>'OK','trace_id'=>$trace,'payload'=>$job], 202);
    }

    public function download($id = null)
    {
        $file=(new ExportJobService())->prepareDownload((string)$id, (string)$this->request->request('token'), (int)$this->auth->id);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.basename($file['filename']).'"');
        header('Content-Length: '.$file['size']); readfile($file['absolute_path']); exit;
    }

    private function payload()
    {
        $raw=file_get_contents('php://input'); $data=json_decode((string)$raw,true);
        return is_array($data)?$data:$this->request->post();
    }
}
