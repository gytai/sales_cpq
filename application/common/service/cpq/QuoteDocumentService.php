<?php

namespace app\common\service\cpq;

use app\common\job\QuoteDocumentJob;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use think\Queue;

/**
 * CPQ 报价 PDF 服务（M3，P60，GYTAI-72）。
 *
 * - 异步生成：任务入队（Redis 队列 worker / Sync 驱动同步执行），状态 pending → processing → succeeded/failed；
 * - 成功文件不可覆盖：同一报价版本+模板+语言生成成功后不再重新生成，修订产生新文件；
 * - 文件哈希：生成时记录 SHA-256，下载前复算校验，不匹配拒绝下载并写审计；
 * - 失败可重试：仅 failed 状态允许重试（retry_count 累加），不覆盖已成功文件；
 * - 下载审计：下载与哈希校验动作写入 cpq_audit_log。
 */
class QuoteDocumentService
{
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED = 'failed';

    /** @var QuoteTemplateService */
    private $templateService;

    public function __construct(QuoteTemplateService $templateService = null)
    {
        $this->templateService = $templateService ?: new QuoteTemplateService();
    }

    // ------------------------------------------------------------------
    // 任务创建
    // ------------------------------------------------------------------

    /**
     * 发起 PDF 生成任务（幂等）。
     *
     * @param int    $quoteId
     * @param string $language  zh/en
     * @param int    $templateId 0=自动匹配市场默认模板
     * @param int    $adminId
     * @return array 任务行（已存在时返回既有任务）
     */
    public function createJob($quoteId, $language, $templateId, $adminId)
    {
        $quoteId = (int)$quoteId;
        $language = in_array($language, ['zh', 'en'], true) ? $language : 'zh';
        $quote = Db::name('cpq_quote')->where('id', $quoteId)->find();
        if (!$quote) {
            throw new InvalidArgumentException('报价不存在');
        }
        if ((int)$quote['current_revision_no'] < 1) {
            throw new InvalidArgumentException('报价尚未提交，没有可打印的冻结版本');
        }
        ProductLineScopeService::forAdmin((int)$adminId)->assertLineAllowed((string)$quote['product_line'], '无该产品线的数据权限');
        // 组织/区域/负责人维度单条兜底，与报价详情同一口径
        QuoteDataScopeService::forAdmin((int)$adminId)->assertQuoteAccess($quote);

        $template = null;
        if ((int)$templateId > 0) {
            $template = Db::name('cpq_quote_template')->where('id', (int)$templateId)->find();
            if (!$template) {
                throw new InvalidArgumentException('报价模板不存在');
            }
            if ($template['status'] !== 'published') {
                throw new InvalidArgumentException('仅已发布模板可用于生成 PDF');
            }
            if ((string)$template['language'] !== $language) {
                throw new InvalidArgumentException('模板语言与生成语言不一致');
            }
        } else {
            $template = $this->templateService->resolveDefaultTemplate($language, (string)$quote['market_scope'] ?: 'all');
            if (!$template) {
                throw new InvalidArgumentException('未找到该语言的默认报价模板，请先发布模板或显式指定模板');
            }
        }

        // 幂等：成功不重生成；排队/处理中直接返回；失败允许重试
        $existing = Db::name('cpq_quote_document')
            ->where('quote_id', $quoteId)
            ->where('revision_no', (int)$quote['current_revision_no'])
            ->where('template_id', (int)$template['id'])
            ->where('language', $language)
            ->find();
        if ($existing) {
            $asyncJob = $this->ensureUnifiedJob($existing, $quote, $adminId);
            if ($existing['status'] === self::STATUS_SUCCEEDED) {
                if (($asyncJob['status'] ?? '') === 'pending') {
                    $jobs = new AsyncJobService();
                    $jobs->claim($asyncJob['job_key']);
                    $asyncJob = $jobs->succeed($asyncJob['job_key'], ['document_id'=>(int)$existing['id'],'file_hash'=>(string)$existing['file_hash']]);
                }
                return ['document' => $existing, 'job' => $asyncJob, 'created' => false, 'message' => '该版本已有正式文件，正式 PDF 不可覆盖；如需更新请创建修订版本'];
            }
            if (in_array($existing['status'], [self::STATUS_PENDING, self::STATUS_PROCESSING], true)) {
                return ['document' => $existing, 'job' => $asyncJob, 'created' => false, 'message' => '该版本 PDF 正在生成中'];
            }
            // failed → 重试：复用记录，重置状态并重新入队
            Db::name('cpq_quote_document')->where('id', (int)$existing['id'])->update([
                'status' => self::STATUS_PENDING,
                'error_message' => '',
                'retry_count' => Db::name('cpq_quote_document')->where('id', (int)$existing['id'])->value('retry_count') + 1,
                'updatetime' => time(),
            ]);
            if (($asyncJob['status'] ?? '') === AsyncJobService::STATUS_FAILED) {
                $asyncJob = (new AsyncJobService())->retry($asyncJob['job_key'], $adminId, false);
            }
            $this->pushJob((int)$existing['id']);
            $fresh = Db::name('cpq_quote_document')->where('id', (int)$existing['id'])->find();
            return ['document' => $fresh, 'job' => $asyncJob, 'created' => false, 'retried' => true, 'message' => '已重新排队生成'];
        }

        $now = time();
        $documentId = Db::name('cpq_quote_document')->insertGetId([
            'quote_id' => $quoteId,
            'revision_no' => (int)$quote['current_revision_no'],
            'template_id' => (int)$template['id'],
            'language' => $language,
            'currency' => (string)$quote['currency'],
            'status' => self::STATUS_PENDING,
            'requested_by' => (int)$adminId,
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        $asyncJob = $this->ensureUnifiedJob(['id' => $documentId], $quote, $adminId);
        $this->pushJob($documentId);
        (new AuditLogService())->record('request_pdf', 'cpq_quote_document', (int)$documentId, [
            'quote_id' => $quoteId,
            'revision_no' => (int)$quote['current_revision_no'],
            'language' => $language,
            'template_id' => (int)$template['id'],
        ], (string)$quote['code']);

        return [
            'document' => Db::name('cpq_quote_document')->where('id', (int)$documentId)->find(),
            'job' => $asyncJob,
            'created' => true,
        ];
    }

    // ------------------------------------------------------------------
    // 队列处理
    // ------------------------------------------------------------------

    /**
     * 处理 PDF 生成任务（队列 worker / 测试直接调用）。
     *
     * @param int $documentId
     * @return array 更新后的任务行
     */
    public function process($documentId)
    {
        $document = Db::name('cpq_quote_document')->where('id', (int)$documentId)->find();
        if (!$document) {
            throw new InvalidArgumentException('打印任务不存在');
        }
        $jobs = new AsyncJobService();
        $unified = Db::name('cpq_job')->where('type', 'pdf')->where('business_type', 'quote_document')->where('business_id', (string)$documentId)->find();
        if ($unified && $unified['status'] === 'pending') {
            $jobs->claim($unified['job_key']);
        }
        if ($document['status'] === self::STATUS_SUCCEEDED) {
            return $document; // 正式文件不可覆盖
        }
        if ($document['status'] === self::STATUS_PROCESSING) {
            // 另一 worker 正在处理
            return $document;
        }

        // 抢占：仅 pending → processing 成功者执行
        $claimed = Db::name('cpq_quote_document')
            ->where('id', (int)$documentId)
            ->where('status', self::STATUS_PENDING)
            ->update(['status' => self::STATUS_PROCESSING, 'updatetime' => time()]);
        if (!$claimed) {
            return Db::name('cpq_quote_document')->where('id', (int)$documentId)->find();
        }

        try {
            $file = $this->renderToFile($document);
            $hash = hash_file('sha256', $file);
            Db::name('cpq_quote_document')->where('id', (int)$documentId)->update([
                'status' => self::STATUS_SUCCEEDED,
                'file_path' => $this->relativePath($file),
                'file_hash' => $hash,
                'file_size' => filesize($file),
                'generated_at' => time(),
                'error_message' => '',
                'updatetime' => time(),
            ]);
            (new AuditLogService())->record('generate_pdf', 'cpq_quote_document', (int)$documentId, [
                'quote_id' => (int)$document['quote_id'],
                'revision_no' => (int)$document['revision_no'],
                'file_hash' => $hash,
                'file_size' => filesize($file),
            ]);
            if ($unified) {
                $jobs->succeed($unified['job_key'], ['document_id'=>(int)$documentId,'file_hash'=>$hash]);
            }
            return Db::name('cpq_quote_document')->where('id', (int)$documentId)->find();
        } catch (\Throwable $e) {
            Db::name('cpq_quote_document')->where('id', (int)$documentId)->update([
                'status' => self::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'updatetime' => time(),
            ]);
            (new AuditLogService())->record('pdf_failed', 'cpq_quote_document', (int)$documentId, [
                'quote_id' => (int)$document['quote_id'],
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            if ($unified) {
                $jobs->fail($unified['job_key'], 'CPQ_PDF_FAILED', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * 渲染 PDF 到文件（mpdf，中文字体嵌入）。
     *
     * @return string 绝对路径
     */
    private function renderToFile(array $document)
    {
        $quote = Db::name('cpq_quote')->where('id', (int)$document['quote_id'])->find();
        if (!$quote) {
            throw new RuntimeException('报价不存在，PDF 生成失败');
        }
        $revision = Db::name('cpq_quote_revision')
            ->where('quote_id', (int)$document['quote_id'])
            ->where('revision_no', (int)$document['revision_no'])
            ->find();
        if (!$revision) {
            throw new RuntimeException('报价冻结版本不存在，PDF 生成失败');
        }
        $template = Db::name('cpq_quote_template')->where('id', (int)$document['template_id'])->find();
        if (!$template || $template['status'] !== 'published') {
            throw new RuntimeException('报价模板不可用（未发布或已删除）');
        }

        $vars = $this->buildVariables($quote, $revision);
        $lines = $this->buildLines((int)$revision['id']);
        $html = $this->templateService->renderHtml($template, $vars, $lines, (string)$document['language']);

        $dir = ROOT_PATH . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cpq' . DIRECTORY_SEPARATOR . 'documents';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // 文件名含任务 ID：每个任务独立文件，修订/重试互不覆盖
        $filename = sprintf('%s-r%d-%s-d%d.pdf', $quote['code'], (int)$document['revision_no'], $document['language'], (int)$document['id']);
        $file = $dir . DIRECTORY_SEPARATOR . $filename;

        $mpdf = $this->createMpdf((string)$document['language'], (string)$template['paper_size']);
        $mpdf->SetTitle(($document['language'] === 'en' ? 'Quotation ' : '报价单 ') . $quote['code'] . ' r' . $document['revision_no']);
        $mpdf->WriteHTML($html);
        $mpdf->Output($file, \Mpdf\Output\Destination::FILE);

        if (!is_file($file) || filesize($file) < 512) {
            throw new RuntimeException('PDF 文件生成异常（体积过小或未落盘）');
        }
        return $file;
    }

    /**
     * mpdf 实例：中文字体真实嵌入（容器 fonts-wqy-zenhei；本机可提取到 runtime/temp/fonts）。
     */
    private function createMpdf($language, $paperSize)
    {
        if (!class_exists('Mpdf\\Mpdf')) {
            throw new RuntimeException('mpdf 未安装，无法生成 PDF');
        }
        $tmpDir = rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'temp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }
        $cjkFonts = [
            ['dir' => '/usr/share/fonts/truetype/wqy', 'file' => 'wqy-zenhei.ttc'],
            ['dir' => $tmpDir . DIRECTORY_SEPARATOR . 'fonts', 'file' => 'wqy-zenhei.ttc'],
        ];
        $font = null;
        foreach ($cjkFonts as $candidate) {
            if (is_file($candidate['dir'] . DIRECTORY_SEPARATOR . $candidate['file'])) {
                $font = $candidate + ['name' => 'wqyzh'];
                break;
            }
        }
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $options = [
            'mode' => 'utf-8',
            'format' => $paperSize === 'Letter' ? 'Letter' : 'A4',
            'tempDir' => $tmpDir,
            'default_font' => 'helvetica',
        ];
        if ($font) {
            $options['fontDir'] = array_merge($defaultConfig['fontDir'], [$font['dir']]);
            $options['fontdata'] = $defaultFontConfig['fontdata'] + [
                $font['name'] => ['R' => $font['file'], 'TTCfontID' => ['R' => 0]],
            ];
            $options['default_font'] = $font['name'];
        } elseif ($language === 'zh') {
            throw new RuntimeException('环境中没有可用中文字体 wqy-zenhei.ttc（容器需安装 fonts-wqy-zenhei）');
        }
        return new \Mpdf\Mpdf($options);
    }

    // ------------------------------------------------------------------
    // 下载 / 哈希校验
    // ------------------------------------------------------------------

    /**
     * 下载前校验哈希并计数（返回文件信息，由控制器负责流式输出）。
     */
    public function prepareDownload($documentId, $adminId)
    {
        $document = Db::name('cpq_quote_document')->where('id', (int)$documentId)->find();
        if (!$document || $document['status'] !== self::STATUS_SUCCEEDED) {
            throw new InvalidArgumentException('文件尚未成功生成，不能下载');
        }
        $quote = Db::name('cpq_quote')->where('id', (int)$document['quote_id'])->find();
        if ($quote) {
            ProductLineScopeService::forAdmin((int)$adminId)->assertLineAllowed((string)$quote['product_line'], '无该产品线的数据权限');
            // 组织/区域/负责人维度单条兜底，防止跨区域下载正式 PDF
            QuoteDataScopeService::forAdmin((int)$adminId)->assertQuoteAccess($quote);
        }
        $absolute = ROOT_PATH . $document['file_path'];
        if (!is_file($absolute)) {
            throw new RuntimeException('文件已丢失，请联系管理员');
        }
        $actual = hash_file('sha256', $absolute);
        if ($actual !== (string)$document['file_hash']) {
            (new AuditLogService())->record('hash_mismatch', 'cpq_quote_document', (int)$documentId, [
                'expected' => (string)$document['file_hash'],
                'actual' => $actual,
            ]);
            throw new RuntimeException('文件哈希校验失败，文件可能被篡改，已记录审计');
        }
        Db::name('cpq_quote_document')->where('id', (int)$documentId)->update([
            'download_count' => (int)$document['download_count'] + 1,
            'updatetime' => time(),
        ]);
        (new AuditLogService())->record('download', 'cpq_quote_document', (int)$documentId, [
            'quote_id' => (int)$document['quote_id'],
            'revision_no' => (int)$document['revision_no'],
            'file_hash' => (string)$document['file_hash'],
        ], $quote ? (string)$quote['code'] : '');
        return [
            'absolute_path' => $absolute,
            'filename' => basename($absolute),
            'size' => (int)$document['file_size'],
        ];
    }

    /**
     * 哈希验证（不计数）。
     */
    public function verifyHash($documentId)
    {
        $document = Db::name('cpq_quote_document')->where('id', (int)$documentId)->find();
        if (!$document || $document['status'] !== self::STATUS_SUCCEEDED) {
            throw new InvalidArgumentException('仅已生成文件可验证哈希');
        }
        $absolute = ROOT_PATH . $document['file_path'];
        if (!is_file($absolute)) {
            return ['match' => false, 'expected' => (string)$document['file_hash'], 'actual' => '', 'reason' => '文件缺失'];
        }
        $actual = hash_file('sha256', $absolute);
        return [
            'match' => $actual === (string)$document['file_hash'],
            'expected' => (string)$document['file_hash'],
            'actual' => $actual,
        ];
    }

    // ------------------------------------------------------------------
    // 数据组装
    // ------------------------------------------------------------------

    private function buildVariables(array $quote, array $revision)
    {
        $customer = $quote['customer_id'] ? Db::name('cpq_customer')->where('id', (int)$quote['customer_id'])->find() : null;
        $agent = $quote['agent_id'] ? Db::name('cpq_agent')->where('id', (int)$quote['agent_id'])->find() : null;
        $salesOrg = $quote['sales_org_id'] ? Db::name('cpq_sales_org')->where('id', (int)$quote['sales_org_id'])->find() : null;
        $result = json_decode((string)$revision['pricing_result_json'], true) ?: [];
        $totals = isset($result['totals']) && is_array($result['totals']) ? $result['totals'] : [];

        $terms = Db::name('cpq_quote_term')->where('revision_id', (int)$revision['id'])->order('sort asc,id asc')->select();
        $termTypeText = ['payment' => '付款', 'trade' => '贸易', 'warranty' => '质保', 'delivery' => '交付', 'other' => '其他'];
        $termsHtml = '';
        if ($terms) {
            $termsHtml .= '<table><tr><th style="width:80px">类型</th><th>内容</th></tr>';
            foreach ($terms as $term) {
                $termsHtml .= '<tr><td>' . ($termTypeText[$term['term_type']] ?? $term['term_type']) . '</td>'
                    . '<td>' . htmlspecialchars((string)$term['content'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $termsHtml .= '</table>';
        } else {
            $termsHtml .= '<p class="muted">（无条款）</p>';
        }

        // 技术参数：型号参数快照（可配置/展示参数）
        $paramsHtml = '';
        $lines = Db::name('cpq_quote_line')->where('quote_id', (int)$quote['id'])->select();
        foreach ($lines as $line) {
            $paramsHtml .= '<tr><td>' . htmlspecialchars((string)$line['model_id'], ENT_QUOTES, 'UTF-8') . '</td><td>'
                . htmlspecialchars((string)$line['configuration_hash'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        return [
            'quote.code' => (string)$quote['code'],
            'quote.name' => (string)$quote['name'],
            'quote.customer_name' => $customer ? (string)$customer['name'] : '—',
            'quote.agent_name' => $agent ? (string)$agent['code'] : '—',
            'quote.sales_org_name' => $salesOrg ? (string)$salesOrg['name'] : '—',
            'quote.currency' => (string)$quote['currency'],
            'quote.date' => date('Y-m-d', (int)($quote['submitted_at'] ?: time())),
            'quote.valid_until' => date('Y-m-d', ((int)($quote['submitted_at'] ?: time())) + 30 * 86400),
            'quote.owner_name' => (string)(Db::name('admin')->where('id', (int)$quote['owner_id'])->value('nickname') ?: ''),
            'quote.untaxed_amount' => isset($totals['untaxed']) ? (string)$totals['untaxed'] : '0.0000',
            'quote.tax_amount' => isset($totals['tax']) ? (string)$totals['tax'] : '0.0000',
            'quote.total_amount' => isset($totals['total']) ? (string)$totals['total'] : '0.0000',
            'quote.line_count' => (string)count($lines),
            'company.name' => 'CPQ 演示制造有限公司',
            'company.name_en' => 'CPQ Demo Manufacturing Co., Ltd.',
            'company.address' => '演示省演示市演示路 1 号',
            'company.contact' => '+86 000 0000 0000',
            '__terms_html' => $termsHtml,
            '__params_html' => $paramsHtml,
        ];
    }

    private function buildLines($revisionId)
    {
        $rows = Db::name('cpq_quote_price_snapshot')
            ->where('revision_id', (int)$revisionId)
            ->order('id asc')
            ->select();
        $lines = [];
        foreach ($rows as $index => $row) {
            $lines[] = [
                'line_no' => $index + 1,
                'model_code' => (string)$row['model_code'],
                'model_name' => (string)(Db::name('cpq_product_model')->where('id', (int)$row['model_id'])->value('name') ?: ''),
                'quantity' => (string)$row['quantity'],
                'unit_subtotal' => (string)$row['unit_subtotal'],
                'total_amount' => (string)$row['total_amount'],
            ];
        }
        return $lines;
    }

    private function pushJob($documentId)
    {
        Queue::push(QuoteDocumentJob::class, ['document_id' => (int)$documentId], 'default');
    }

    private function ensureUnifiedJob(array $document, array $quote, $adminId)
    {
        return (new AsyncJobService())->create('pdf', 'quote_document', (string)$document['id'], [
            'document_id'=>(int)$document['id'], 'quote_id'=>(int)$quote['id'],
        ], (int)$adminId, 0, 'pdf-document:' . (int)$document['id']);
    }

    private function relativePath($absolute)
    {
        $root = str_replace('\\', '/', ROOT_PATH);
        $path = str_replace('\\', '/', $absolute);
        return '/' . ltrim(substr($path, strlen($root)), '/');
    }
}
