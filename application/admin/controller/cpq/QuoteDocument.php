<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\QuoteDocumentService;
use think\Db;

/**
 * 报价中心：报价打印记录（P60，GYTAI-75）
 *
 *  - index     打印任务列表：报价单号、版本、模板、语言、生成时间、生成人、
 *              文件哈希、下载次数、状态；自动轮询刷新排队/处理中任务
 *  - generate  发起 PDF 生成（选择已提交报价 + 语言 + 模板；异步任务）
 *  - download  受控下载（哈希校验 + 计数 + 审计）
 *  - verify    哈希验证
 *  - retry     失败任务受控重试
 *  - quotes    可打印报价候选（已提交/已批准且有冻结版本，产品线数据范围内）
 *
 * @icon fa fa-print
 */
class QuoteDocument extends Backend
{
    /** @var QuoteDocumentService */
    private $service;

    public function _initialize()
    {
        parent::_initialize();
        $this->service = new QuoteDocumentService();
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $where = [];
            $status = trim((string)$this->request->request('status'));
            if ($status !== '') {
                $where['d.status'] = ['=', $status];
            }
            $keyword = trim((string)$this->request->request('keyword'));
            if ($keyword !== '') {
                $where['q.code|q.name'] = ['like', '%' . $keyword . '%'];
            }
            $productLine = trim((string)$this->request->request('product_line'));
            $scope = \app\common\service\cpq\ProductLineScopeService::forAdmin((int)$this->auth->id);
            if ($productLine !== '') {
                $scope->assertLineAllowed($productLine, '无该产品线的数据权限');
                $where['q.product_line'] = ['=', $productLine];
            } elseif (!$scope->isUnrestricted()) {
                $lines = $scope->getAllowedLines() ?: ['__CPQ_NONE__'];
                $where['q.product_line'] = ['in', $lines];
            }

            $base = Db::name('cpq_quote_document')->alias('d')
                ->join('__CPQ_QUOTE__ q', 'q.id = d.quote_id', 'LEFT')
                ->join('__CPQ_QUOTE_TEMPLATE__ t', 't.id = d.template_id', 'LEFT')
                ->join('__ADMIN__ a', 'a.id = d.requested_by', 'LEFT')
                ->where($where);
            $total = (clone $base)->count();
            $rows = (clone $base)
                ->field('d.*, q.code AS quote_code, q.name AS quote_name, q.status AS quote_status,'
                    . ' t.name AS template_name, a.nickname AS requested_by_name')
                ->order('d.id desc')
                ->limit(($page - 1) * $limit, $limit)
                ->select();
            foreach ($rows as &$row) {
                $row['status_text'] = (new \app\admin\model\cpq\QuoteDocument())->getStatusList()[$row['status']] ?? $row['status'];
            }
            unset($row);
            return json(['total' => $total, 'rows' => $rows]);
        }
        $statusList = (new \app\admin\model\cpq\QuoteDocument())->getStatusList();
        $this->view->assign('statusList', $statusList);
        $this->assignconfig('statusList', $statusList);
        $productLineList = \app\common\service\cpq\ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $this->view->assign('productLineList', $productLineList);
        $this->assignconfig('productLineList', $productLineList);
        return $this->view->fetch();
    }

    /**
     * 可打印报价候选：已提交/已批准/已发送且存在冻结版本。
     */
    public function quotes()
    {
        if (!$this->request->isAjax()) {
            $this->error(__('Invalid parameters'));
        }
        $scope = \app\common\service\cpq\ProductLineScopeService::forAdmin((int)$this->auth->id);
        $where = ['q.current_revision_no' => ['>', 0]];
        if (!$scope->isUnrestricted()) {
            $lines = $scope->getAllowedLines() ?: ['__CPQ_NONE__'];
            $where['q.product_line'] = ['in', $lines];
        }
        $rows = Db::name('cpq_quote')->alias('q')
            ->join('__CPQ_CUSTOMER__ c', 'c.id = q.customer_id', 'LEFT')
            ->where($where)
            ->field('q.id, q.code, q.name, q.status, q.currency, q.market_scope, q.current_revision_no, c.name AS customer_name')
            ->order('q.id desc')
            ->limit(200)
            ->select();
        $this->success('OK', null, ['payload' => ['quotes' => $rows]]);
    }

    /**
     * 发起 PDF 生成（异步任务，202 语义由任务状态承载）。
     */
    public function generate()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $quoteId = (int)$this->request->post('quote_id');
        $language = (string)$this->request->post('language', 'zh');
        $templateId = (int)$this->request->post('template_id', 0);
        $traceId = bin2hex(random_bytes(12));
        try {
            $result = $this->service->createJob($quoteId, $language, $templateId, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DOCUMENT_INVALID', 'trace_id' => $traceId]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DOCUMENT_FORBIDDEN', 'trace_id' => $traceId]);
        } catch (\Throwable $exception) {
            $this->error('PDF 任务创建异常：' . $exception->getMessage(), null, ['business_code' => 'CPQ_INTERNAL_ERROR', 'trace_id' => $traceId]);
        }
        $this->success($result['message'] ?? 'PDF 生成任务已受理', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $result,
        ]);
    }

    /**
     * 受控下载：哈希校验 + 计数 + 审计。
     */
    public function download($ids = null)
    {
        $documentId = (int)$ids;
        $traceId = bin2hex(random_bytes(12));
        try {
            $file = $this->service->prepareDownload($documentId, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DOCUMENT_INVALID', 'trace_id' => $traceId]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DOCUMENT_FORBIDDEN', 'trace_id' => $traceId]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($file['filename']) . '"');
        header('Content-Length: ' . $file['size']);
        header('X-Trace-Id: ' . $traceId);
        readfile($file['absolute_path']);
        exit;
    }

    /**
     * 哈希验证。
     */
    public function verify()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $documentId = (int)$this->request->post('ids');
        $traceId = bin2hex(random_bytes(12));
        try {
            $result = $this->service->verifyHash($documentId);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DOCUMENT_INVALID', 'trace_id' => $traceId]);
        }
        $this->success($result['match'] ? '哈希验证通过' : '哈希验证失败：文件与生成时不一致', null, [
            'business_code' => $result['match'] ? 'OK' : 'CPQ_DOCUMENT_HASH_MISMATCH',
            'trace_id' => $traceId,
            'payload' => $result,
        ]);
    }

    /**
     * 失败任务受控重试。
     */
    public function retry()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $documentId = (int)$this->request->post('ids');
        $document = Db::name('cpq_quote_document')->where('id', $documentId)->find();
        if (!$document) {
            $this->error('打印任务不存在');
        }
        if ($document['status'] !== 'failed') {
            $this->error('仅失败任务可以重试（已成功的正式文件不可覆盖）');
        }
        $quote = Db::name('cpq_quote')->where('id', (int)$document['quote_id'])->find();
        try {
            $result = $this->service->createJob((int)$document['quote_id'], (string)$document['language'], (int)$document['template_id'], (int)$this->auth->id);
        } catch (\Throwable $exception) {
            $this->error('重试失败：' . $exception->getMessage());
        }
        $this->success('已重新排队生成', null, ['payload' => $result]);
    }
}
