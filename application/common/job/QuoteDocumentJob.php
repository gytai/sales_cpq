<?php

namespace app\common\job;

use app\common\service\cpq\QuoteDocumentService;
use think\queue\Job;

/**
 * CPQ 报价 PDF 生成队列任务（M3，P60）。
 *
 * 由 QuoteDocumentService::createJob 投递到 default 队列；worker 消费时
 * 委托服务层生成（成功文件不可覆盖、哈希落库、失败标记 failed 可重试）。
 * Sync 驱动（测试）下 push 即同步执行。
 */
class QuoteDocumentJob
{
    public function fire(Job $job, $data)
    {
        $documentId = isset($data['document_id']) ? (int)$data['document_id'] : 0;
        if ($documentId <= 0) {
            trace('QuoteDocumentJob: 缺少 document_id', 'error');
            $job->delete();
            return;
        }

        try {
            (new QuoteDocumentService())->process($documentId);
            $job->delete();
        } catch (\Throwable $e) {
            // 服务层已将任务标记为 failed 并写审计；此处不 release，
            // 重试由页面/接口对 failed 记录显式发起（受控重试）。
            trace('QuoteDocumentJob failed: document_id=' . $documentId . ' error=' . $e->getMessage(), 'error');
            $job->delete();
        }
    }

    public function failed($data)
    {
        trace('QuoteDocumentJob ultimate failure: ' . json_encode($data, JSON_UNESCAPED_UNICODE), 'error');
    }
}
