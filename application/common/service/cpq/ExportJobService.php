<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use RuntimeException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use think\Db;
use think\Request;

/**
 * P94 报价明细导出：服务端数据范围、异步 XLSX、有效期与下载审计。
 *
 * 创建与查询统一复用 ReportFilterService（数据范围 + 白名单业务筛选），
 * XLSX 表头按角色动态构建：无敏感字段权限的角色，表结构中不包含
 * 成本/公司控制价/毛利额/毛利率列；冻结快照中的敏感字段在读取时
 * 经 SensitiveFieldService 递归脱敏。下载令牌只经 process 响应的
 * download_token_delivery 一次性交付，不落公共 result_json。
 */
class ExportJobService
{
    /** 下载令牌有效期（秒）：短时效一次性令牌，降低令牌外泄窗口。 */
    const DOWNLOAD_TOKEN_TTL = 900;

    private $jobs;

    public function __construct(AsyncJobService $jobs = null)
    {
        $this->jobs = $jobs ?: new AsyncJobService();
    }

    public function createQuoteExport(array $filters, $requestedBy, $dispatch = true)
    {
        $idempotencyKey = (string)($filters['idempotency_key'] ?? '');
        unset($filters['idempotency_key']);

        $filterService = new ReportFilterService(QuoteDataScopeService::forAdmin((int)$requestedBy));
        $safe = $filterService->normalize($filters);
        // 创建时即做越权校验：显式越权筛选立即失败，不产生任务。
        $filterService->scope()->assertFilters($safe);

        $rowCount = $this->query($safe, (int)$requestedBy, true, $filterService);
        $job = $this->jobs->create('excel_export', 'quote_detail', '', ['filters' => $safe], (int)$requestedBy, (int)$rowCount, $idempotencyKey);
        // 下载令牌在创建时生成并暂存于 payload_json（公开状态永不回显 payload），
        // 供异步处理写入哈希、以及创建人一次性领取；幂等哈希仍只由筛选条件决定。
        // 幂等命中既有任务时不覆盖其令牌，保持与已写入的令牌哈希一致。
        $raw = $this->jobs->raw($job['job_key']);
        $existingPayload = json_decode((string)$raw['payload_json'], true) ?: [];
        if ($raw['status'] === 'pending' && empty($existingPayload['download_token'])) {
            $existingPayload['download_token'] = bin2hex(random_bytes(24));
            Db::name('cpq_job')->where('id', (int)$raw['id'])->update([
                'payload_json' => json_encode($existingPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updatetime' => time(),
            ]);
        }
        return $dispatch ? $this->jobs->dispatch($job['job_key']) : $job;
    }

    public function process(array $job)
    {
        $payload = json_decode((string)$job['payload_json'], true) ?: [];
        $rows = $this->query((array)($payload['filters'] ?? []), (int)$job['requested_by'], false);
        $roles = SensitiveFieldService::rolesOfAdmin((int)$job['requested_by']);
        // 冻结快照（policy_snapshot_json/price_trace_json）读取后递归脱敏。
        $rows = (new SensitiveFieldService())->maskRows($rows, $roles);

        $canCompany = (bool)array_intersect($roles, SensitiveFieldService::FULL_ACCESS_ROLES);
        $canCost = $canCompany || (bool)array_intersect($roles, SensitiveFieldService::LINE_ACCESS_ROLES);

        $columns = [
            ['报价编号', 'quote_code'], ['报价名称', 'quote_name'], ['版本', 'revision_no'],
            ['产品线', 'product_line'], ['状态', 'quote_status'], ['型号', 'model_code'],
            ['数量', 'quantity'], ['币种', 'quote_currency'], ['指导价', 'guide_price'],
            ['产线控制价', 'line_floor'],
        ];
        if ($canCompany) {
            $columns[] = ['公司控制价', 'company_floor'];
        }
        if ($canCost) {
            $columns[] = ['成本', 'cost'];
            $columns[] = ['毛利额', 'gross_margin_amount'];
            $columns[] = ['毛利率', 'gross_margin_rate'];
        }
        $columns[] = ['未税金额', 'untaxed_amount'];
        $columns[] = ['税额', 'tax_amount'];
        $columns[] = ['总额', 'total_amount'];
        $columns[] = ['审批状态', 'approval_status'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // 全部单元格显式字符串写入：=、+、-、@ 开头的业务文本（报价名称等）
        // 不得被 Excel 当作公式执行（防公式注入），金额保持 Decimal 字符串原样。
        $columnIndex = 1;
        foreach (array_column($columns, 0) as $title) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($columnIndex) . '1', (string)$title, DataType::TYPE_STRING);
            $columnIndex++;
        }
        foreach ($rows as $index => $row) {
            $columnIndex = 1;
            foreach ($columns as $column) {
                $field = $column[1];
                $value = array_key_exists($field, $row) ? (string)$row[$field] : '';
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($columnIndex) . ($index + 2), $value, DataType::TYPE_STRING);
                $columnIndex++;
            }
        }
        $dir = rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'cpq_exports';
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('无法创建导出目录');
        $file = $dir . DIRECTORY_SEPARATOR . 'quote-detail-' . $job['job_key'] . '.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();
        // 令牌优先取创建时暂存的值，保证创建人可领取到同一份令牌；
        // 历史任务载荷缺少令牌时兜底生成。
        $token = isset($payload['download_token']) && is_string($payload['download_token']) && $payload['download_token'] !== ''
            ? $payload['download_token']
            : bin2hex(random_bytes(24));
        $expiresAt = time() + self::DOWNLOAD_TOKEN_TTL;
        if ((new SensitiveFieldService())->requiresAudit($roles)) {
            (new SensitiveFieldService())->recordAccess('export', 'cpq_quote', array_column($rows, 'quote_id'), $roles);
        }
        (new AuditLogService())->record('export', 'cpq_job', (int)$job['id'], ['row_count' => count($rows), 'expires_at' => $expiresAt]);
        $done = $this->jobs->succeed($job['job_key'], [
            'row_count' => count($rows), 'expires_at' => $expiresAt,
        ], [
            'file_path' => $file, 'file_hash' => hash_file('sha256', $file),
            'download_token_hash' => hash('sha256', $token), 'expires_at' => $expiresAt,
        ]);
        // 明文令牌仅随本次处理响应一次性交付，公共任务状态永不包含。
        $done['download_token_delivery'] = $token;
        return $done;
    }

    /**
     * 创建人（或特权角色）一次性领取下载令牌：领取后即从载荷中烧毁，
     * 后续调用不再可得；公开任务状态任何时刻都不包含令牌。
     *
     * @param string $jobKey
     * @param int    $requestedBy
     * @return string
     */
    public function claimDownloadToken($jobKey, $requestedBy)
    {
        $job = $this->jobs->raw($jobKey);
        if ($job['type'] !== 'excel_export' || $job['status'] !== 'succeeded') {
            throw new InvalidArgumentException('导出文件尚不可下载');
        }
        if ((int)$job['requested_by'] !== (int)$requestedBy
            && !array_intersect(SensitiveFieldService::rolesOfAdmin((int)$requestedBy), ['system_admin', 'auditor'])) {
            throw new InvalidArgumentException('无权领取该下载令牌');
        }
        $payload = json_decode((string)$job['payload_json'], true) ?: [];
        $token = isset($payload['download_token']) ? (string)$payload['download_token'] : '';
        if ($token === '') {
            throw new InvalidArgumentException('下载令牌已领取或不存在');
        }
        unset($payload['download_token']);
        Db::name('cpq_job')->where('id', (int)$job['id'])->update([
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updatetime' => time(),
        ]);
        (new AuditLogService())->record('claim_download_token', 'cpq_job', (int)$job['id'], []);
        return $token;
    }

    public function prepareDownload($jobKey, $token, $requestedBy)
    {
        $job = $this->jobs->raw($jobKey);
        $result = 'denied';
        try {
            if ($job['type'] !== 'excel_export' || $job['status'] !== 'succeeded') throw new InvalidArgumentException('导出文件尚不可下载');
            if ((int)$job['requested_by'] !== (int)$requestedBy && !array_intersect(SensitiveFieldService::rolesOfAdmin((int)$requestedBy), ['system_admin','auditor'])) {
                throw new InvalidArgumentException('无权下载该导出文件');
            }
            if ((int)$job['expires_at'] < time()) { $result = 'expired'; throw new InvalidArgumentException('导出文件已过期'); }
            if (!hash_equals((string)$job['download_token_hash'], hash('sha256', (string)$token))) throw new InvalidArgumentException('下载令牌无效');
            if (!is_file((string)$job['file_path'])) throw new RuntimeException('导出文件已丢失');
            if (!hash_equals((string)$job['file_hash'], hash_file('sha256', (string)$job['file_path']))) { $result = 'hash_mismatch'; throw new RuntimeException('导出文件哈希校验失败'); }
            $result = 'allowed';
            // 一次性令牌：首次成功下载后立即烧毁，同一令牌的后续请求一律拒绝。
            Db::name('cpq_job')->where('id', (int)$job['id'])->update(['download_token_hash' => '', 'updatetime' => time()]);
            return ['absolute_path'=>$job['file_path'],'filename'=>basename($job['file_path']),'size'=>filesize($job['file_path'])];
        } finally {
            $this->logDownload((int)$job['id'], (int)$requestedBy, $result);
        }
    }

    /** @return array|int */
    private function query(array $filters, $adminId, $countOnly, ReportFilterService $filterService = null)
    {
        $query = Db::name('cpq_quote_price_snapshot')->alias('p')
            ->join('__CPQ_QUOTE_REVISION__ r', 'r.id=p.revision_id')
            ->join('__CPQ_QUOTE__ q', 'q.id=r.quote_id')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT')
            ->join('__CPQ_APPROVAL_INSTANCE__ a', 'a.quote_id=q.id AND a.revision_no=r.revision_no', 'LEFT');
        $filterService = $filterService ?: new ReportFilterService(QuoteDataScopeService::forAdmin((int)$adminId));
        $query = $filterService->applyToQuoteQuery($query, $filterService->normalize($filters), 'q', 'c');
        if ($countOnly) {
            return (int)$query->count();
        }
        $rows = $query->field('q.id quote_id,q.code quote_code,q.name quote_name,q.product_line,q.status quote_status,r.revision_no,p.model_code,p.quantity,p.quote_currency,p.control_unit_price line_floor,p.untaxed_amount,p.tax_amount,p.total_amount,p.policy_snapshot_json,p.price_trace_json,a.status approval_status')->order('q.id desc,p.id asc')->select();
        foreach ($rows as &$row) {
            $row = array_merge($row, $this->snapshotPricing($row));
            unset($row['policy_snapshot_json'], $row['price_trace_json']);
        }
        unset($row);
        return $rows;
    }

    /**
     * 从冻结快照推导指导价/公司控制价/成本与毛利（Decimal 字符串）。
     * 快照不含相应字段时回退为空字符串，敏感字段由调用方递归脱敏。
     *
     * @param array $row 含 policy_snapshot_json/price_trace_json 的行
     * @return array
     */
    private function snapshotPricing(array $row)
    {
        $policy = json_decode((string)($row['policy_snapshot_json'] ?? ''), true);
        if (!is_array($policy)) {
            $policy = [];
        }
        $pick = function ($key) use ($policy) {
            return isset($policy[$key]) && is_scalar($policy[$key]) ? (string)$policy[$key] : '';
        };
        $guidePrice = $pick('guide_price');
        $companyFloor = $pick('company_floor');
        $cost = $pick('cost');
        if ($cost === '') {
            $trace = json_decode((string)($row['price_trace_json'] ?? ''), true);
            if (is_array($trace) && isset($trace['margin']['cost']) && is_scalar($trace['margin']['cost'])) {
                $cost = (string)$trace['margin']['cost'];
            }
        }

        $marginAmount = '';
        $marginRate = '';
        $untaxed = (string)($row['untaxed_amount'] ?? '');
        $quantity = (string)($row['quantity'] ?? '');
        if ($cost !== '' && $untaxed !== '' && is_numeric($cost) && is_numeric($untaxed)) {
            $costTotal = is_numeric($quantity) && bccomp($quantity, '0', 4) > 0
                ? bcmul($cost, $quantity, 4)
                : $cost;
            $marginAmount = bcsub($untaxed, $costTotal, 4);
            if (bccomp($untaxed, '0', 4) > 0) {
                $marginRate = bcdiv($marginAmount, $untaxed, 4);
            }
        }
        return [
            'guide_price' => $guidePrice,
            'company_floor' => $companyFloor,
            'cost' => $cost,
            'gross_margin_amount' => $marginAmount,
            'gross_margin_rate' => $marginRate,
        ];
    }

    private function logDownload($jobId, $userId, $result)
    {
        $ip = '';
        try { $ip = (string)Request::instance()->ip(); } catch (\Throwable $ignored) {}
        Db::name('cpq_download_log')->insert(['job_id'=>$jobId,'user_id'=>$userId,'result'=>$result,'ip'=>$ip,'createtime'=>time()]);
        (new AuditLogService())->record('download_export', 'cpq_job', $jobId, ['result'=>$result]);
    }
}
