<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\AsyncJobService;
use app\common\service\cpq\ExportJobService;
use app\common\service\cpq\ProductLineScopeService;
use think\Db;

/**
 * P94 报价导出中心（GYTAI-78）。
 *
 * 任务列表（本人 excel_export 任务）、创建导出（统一筛选 + 幂等键）、
 * 轮询状态、一次性领取下载令牌、携带令牌下载 XLSX（含审计）。
 *
 * @icon fa fa-file-excel-o
 */
class ReportExport extends Backend
{
    /** 创建导出时允许的筛选字段白名单（与 ReportFilterService::ALLOWED_KEYS 对齐）。 */
    const CREATE_WHITELIST = [
        'company', 'sales_org_id', 'product_line', 'region_id',
        'owner_id', 'currency', 'created_from', 'created_to', 'status', 'idempotency_key',
    ];

    /** @var ExportJobService */
    private $exportService;

    /** @var AsyncJobService */
    private $jobService;

    public function _initialize()
    {
        parent::_initialize();
        $this->exportService = new ExportJobService();
        $this->jobService = new AsyncJobService();
    }

    /**
     * 任务列表：仅本人的 excel_export 任务，公开字段经 AsyncJobService::publicJob 脱敏。
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $query = Db::name('cpq_job')
                ->where('type', 'excel_export')
                ->where('requested_by', (int)$this->auth->id)
                ->order('id desc');
            $total = (int)$query->count();
            $rows = $query->page($page, $limit)->select();
            $list = [];
            foreach ($rows as $row) {
                $list[] = $this->jobService->publicJob($row);
            }
            return json(['total' => $total, 'rows' => $list]);
        }

        $productLineList = ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $currencyList = Db::name('cpq_quote')->group('currency')->column('currency');
        $currencyList = array_values(array_filter(array_map('strval', $currencyList), function ($item) {
            return $item !== '';
        }));
        $companyList = Db::name('cpq_quote')->group('company')->column('company');
        $companyList = array_values(array_filter(array_map('strval', $companyList), function ($item) {
            return $item !== '';
        }));
        $this->view->assign('productLineList', $productLineList);
        $this->view->assign('currencyList', $currencyList);
        $this->view->assign('companyList', $companyList);
        $this->assignconfig('productLineList', $productLineList);
        $this->assignconfig('currencyList', $currencyList);
        $this->assignconfig('companyList', $companyList);
        return $this->view->fetch();
    }

    /**
     * 创建导出任务（仅 POST）：白名单筛选 + 幂等键。
     */
    public function create()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $filters = [];
        foreach (self::CREATE_WHITELIST as $key) {
            $value = $this->request->post($key, '');
            if ($value === '' || $value === null) {
                continue;
            }
            $filters[$key] = is_scalar($value) ? trim((string)$value) : '';
        }
        try {
            $job = $this->exportService->createQuoteExport($filters, (int)$this->auth->id, true);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_FILTER_INVALID']);
        } catch (\RuntimeException $exception) {
            \think\Log::error('cpq export create failed: ' . get_class($exception)
                . ' [' . $exception->getMessage() . '] @ ' . $exception->getFile() . ':' . $exception->getLine());
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_EXPORT_FAILED']);
        }
        // success 必须在 try 外：TP5 的 success/error 以 HttpResponseException
        //（RuntimeException 子类）中断执行，放在 try 内会被上方 catch 吞掉
        $this->success('导出任务已创建', null, ['job_key' => $job['job_key'], 'job' => $job]);
    }

    /**
     * 轮询任务状态（仅本人或特权角色可见，服务内已校验）。
     */
    public function status()
    {
        $jobKey = trim((string)$this->request->get('job_key', ''));
        if ($jobKey === '') {
            $this->error(__('Invalid parameters'));
        }
        try {
            $this->success('', null, $this->jobService->status($jobKey, (int)$this->auth->id));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_JOB_INVALID']);
        }
    }

    /**
     * 一次性领取下载令牌（仅 POST；领取后即从任务中销毁）。
     */
    public function claim()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $jobKey = trim((string)$this->request->post('job_key', ''));
        if ($jobKey === '') {
            $this->error(__('Invalid parameters'));
        }
        try {
            $token = $this->exportService->claimDownloadToken($jobKey, (int)$this->auth->id);
            $this->success('', null, ['token' => $token]);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_JOB_INVALID']);
        }
    }

    /**
     * 携带一次性令牌下载 XLSX（校验 + 审计在服务内完成）。
     */
    public function download()
    {
        $jobKey = trim((string)$this->request->get('job_key', ''));
        $token = trim((string)$this->request->get('token', ''));
        if ($jobKey === '' || $token === '') {
            $this->error(__('Invalid parameters'));
        }
        try {
            $file = $this->exportService->prepareDownload($jobKey, $token, (int)$this->auth->id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DOWNLOAD_DENIED']);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_DOWNLOAD_FAILED']);
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($file['filename']) . '"');
        header('Content-Length: ' . (int)$file['size']);
        header('Cache-Control: private, no-store');
        readfile($file['absolute_path']);
        exit;
    }
}
