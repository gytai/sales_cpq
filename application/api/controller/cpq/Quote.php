<?php

namespace app\api\controller\cpq;

use app\common\controller\Api;
use app\common\library\cpq\PricingException;
use app\common\service\cpq\PricingService;
use app\common\service\cpq\ProductLineScopeService;
use app\common\service\cpq\QuoteDataScopeService;
use app\common\service\cpq\QuoteRevisionService;
use app\common\service\cpq\SensitiveFieldService;
use think\Db;

/**
 * CPQ 报价接口（草稿/修订/快照/提交/撤回）
 *
 * - GET  /api/cpq/v1/quotes          报价列表（分页）
 * - POST /api/cpq/v1/quotes          新建草稿
 * - GET  /api/cpq/v1/quotes/:id      报价详情
 * - POST /api/cpq/v1/quotes/:id      草稿更新（乐观锁）
 * - POST /api/cpq/v1/quotes/:id/copy             复制为草稿
 * - POST /api/cpq/v1/quotes/:id/revision         创建修订版本
 * - POST /api/cpq/v1/quotes/:id/recalculate      提交前重算（不冻结）
 * - POST /api/cpq/v1/quotes/:id/submit           提交（冻结快照）
 * - POST /api/cpq/v1/quotes/:id/withdraw         撤回
 * - GET  /api/cpq/v1/quotes/:id/diff?from=&to=   版本差异
 *
 * 所有操作受产品线数据权限作用域约束（ProductLineScopeService，服务端强校验）。
 * 报价数据范围 = ''（未指定产品线）仅不受限管理员可访问，与主数据口径一致。
 */
class Quote extends Api
{
    /** @var array 无需产品线范围约束即可访问的方法（仍走登录） */
    protected $noNeedRight = [];

    /** @var QuoteRevisionService */
    private $service;

    protected function _initialize()
    {
        parent::_initialize();
        $this->service = new QuoteRevisionService();
    }

    public function index()
    {
        $this->respond(function () {
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $status = trim((string)$this->request->request('status'));
            $keyword = trim((string)$this->request->request('keyword'));

            // 统一数据范围（产品线 ∩ 销售组织 ∩ 区域 ∩ owner，AND 收窄）：
            // 与后台列表/驾驶舱/报表同一 QuoteDataScopeService 口径。
            $dataScope = QuoteDataScopeService::forAdmin($this->adminId());
            $makeQuery = function () use ($dataScope, $status, $keyword) {
                $query = Db::name('cpq_quote')->alias('q')
                    ->join('__CPQ_CUSTOMER__ c', 'c.id = q.customer_id', 'LEFT');
                $query = $dataScope->applyToQuoteQuery($query, 'q', 'c');
                if ($status !== '') {
                    $query->where('q.status', $status);
                }
                if ($keyword !== '') {
                    $query->where('q.name|q.code', 'like', '%' . $keyword . '%');
                }
                return $query;
            };

            return [
                'list' => $makeQuery()->field('q.*')->order('q.id desc')->page($page, $limit)->select(),
                'total' => $makeQuery()->count(),
                'page' => $page,
                'limit' => $limit,
            ];
        });
    }

    public function create()
    {
        $this->respond(function () {
            $payload = $this->applyScopeToPayload($this->requestPayload());
            return $this->service->createDraft($payload, $this->adminId());
        });
    }

    public function detail($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            return $this->serviceDetail($quoteId);
        });
    }

    public function update($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            $payload = $this->applyScopeToPayload($this->requestPayload());
            $lock = (int)($payload['optimistic_lock_version'] ?? 0);
            if ($lock <= 0) {
                throw new \InvalidArgumentException('缺少乐观锁版本号');
            }
            return $this->service->updateDraft($quoteId, $lock, $payload, $this->adminId());
        });
    }

    public function copy($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            return $this->service->copy($quoteId, $this->adminId());
        });
    }

    public function revision($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            $payload = $this->requestPayload();
            $note = $payload['note'] ?? [];
            return $this->service->createRevision($quoteId, $this->adminId(), is_array($note) ? $note : [$note]);
        });
    }

    public function recalculate($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            $result = $this->service->recalcBeforeSubmit($quoteId);
            // 试算不返回完整执行轨迹（轨迹属敏感解释数据），仅保留金额与分级
            foreach ($result['lines'] as $index => $line) {
                unset($result['lines'][$index]['price_trace']);
            }
            $roles = SensitiveFieldService::rolesOfAdmin($this->adminId());
            $sensitive = new SensitiveFieldService();
            if ($sensitive->requiresAudit($roles)) {
                $sensitive->recordAccess('view_sensitive', 'cpq_quote', [$quoteId], $roles);
            }
            return (new PricingService())->maskForRoles($result, $roles);
        });
    }

    public function submit($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            $payload = $this->requestPayload();
            $idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
            return $this->service->submit($quoteId, $this->adminId(), $idempotencyKey);
        });
    }

    public function withdraw($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            return $this->service->withdraw($quoteId, $this->adminId());
        });
    }

    public function diff($id = null)
    {
        $this->respond(function () use ($id) {
            $quoteId = $id ?: (int)$this->request->request('id');
            $this->assertScoped($quoteId);
            $from = (int)$this->request->request('from', 0);
            $to = (int)$this->request->request('to');
            if ($to <= 0 || $to < $from) {
                throw new \InvalidArgumentException('diff 需要有效的 from/to 版本号');
            }
            $roles = SensitiveFieldService::rolesOfAdmin($this->adminId());
            return $this->service->diffForRoles($quoteId, $from, $to, $roles);
        });
    }

    // ------------------------------------------------------------------
    // 数据权限作用域
    // ------------------------------------------------------------------

    private function scope()
    {
        return ProductLineScopeService::forAdmin($this->adminId());
    }

    /**
     * 数据范围统一由 QuoteDataScopeService 在查询层叠加
     * （产品线 ∩ 销售组织 ∩ 区域 ∩ owner，AND 收窄），不再保留本地 where 片段。
     */
    private function assertScoped($quoteId)
    {
        $quote = Db::name('cpq_quote')->where('id', (int)$quoteId)->find();
        if (!$quote) {
            throw new \InvalidArgumentException('报价不存在');
        }
        $this->scope()->assertLineAllowed((string)$quote['product_line'], '无该产品线的数据权限');
        // 组织/区域/负责人维度的单条兜底，与列表查询层同一口径
        QuoteDataScopeService::forAdmin($this->adminId())->assertQuoteAccess($quote);
    }

    /**
     * 注入当前管理员产品线作用域：受限用户新建/更新报价时强制以授权产品线为准，
     * 防止越权指定他产品线。
     */
    private function applyScopeToPayload(array $payload)
    {
        $scope = $this->scope();
        if ($scope->isUnrestricted()) {
            return $payload;
        }
        $lines = $scope->getAllowedLines();
        $line = trim((string)($payload['product_line'] ?? ''));
        if ($line !== '') {
            $scope->assertLineAllowed($line);
        } elseif ($lines && count($lines) === 1) {
            $payload['product_line'] = $lines[0];
        } else {
            throw new \InvalidArgumentException('缺少产品线且当前账号涉及多个产品线，请显式指定');
        }
        return $payload;
    }

    // ------------------------------------------------------------------
    // 详情组装
    // ------------------------------------------------------------------

    private function serviceDetail($quoteId)
    {
        $quote = Db::name('cpq_quote')->where('id', (int)$quoteId)->find();
        if (!$quote) {
            throw new \InvalidArgumentException('报价不存在');
        }
        $lines = Db::name('cpq_quote_line')->where('quote_id', $quoteId)->order('line_no asc')->select();
        $terms = Db::name('cpq_quote_term')->where('quote_id', $quoteId)->order('sort asc,id asc')->select();

        $quote['lines'] = array_map(function ($line) {
            return [
                'line_no' => (int)$line['line_no'],
                'model_id' => (int)$line['model_id'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'configuration' => $line['configuration_json'] ? (json_decode($line['configuration_json'], true) ?: []) : [],
                'configuration_hash' => $line['configuration_hash'],
                'bom' => $line['bom_json'] ? (json_decode($line['bom_json'], true) ?: []) : [],
                'manual_discount' => $line['manual_discount'],
                'discount_reason' => $line['discount_reason'],
                'accessories' => $line['accessories_json'] ? (json_decode($line['accessories_json'], true) ?: []) : [],
            ];
        }, $lines);
        $quote['terms'] = $terms;

        $quote['revisions'] = Db::name('cpq_quote_revision')
            ->where('quote_id', $quoteId)
            ->order('revision_no asc')
            ->field('id,revision_no,status,price_hash,approval_level,submittable,frozen_at,created_by')
            ->select();

        return $quote;
    }

    private function adminId()
    {
        return (int)$this->auth->id;
    }

    private function requestPayload()
    {
        $content = file_get_contents('php://input');
        if ($content !== false && trim($content) !== '') {
            $payload = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
                return $payload;
            }
        }
        return $this->request->post();
    }

    private function respond(callable $callback)
    {
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = $callback();
        } catch (PricingException $exception) {
            $this->error($exception->getMessage(), [
                'business_code' => $exception->getBusinessCode(),
                'trace_id' => $traceId,
                'payload' => $exception->getDetails(),
            ], 422);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), [
                'business_code' => 'CPQ_QUOTE_INVALID',
                'trace_id' => $traceId,
            ], 422);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), [
                'business_code' => 'CPQ_QUOTE_FORBIDDEN',
                'trace_id' => $traceId,
            ], 403);
        } catch (\Throwable $exception) {
            $this->error('报价服务异常', [
                'business_code' => 'CPQ_INTERNAL_ERROR',
                'trace_id' => $traceId,
            ], 500);
        }
        $this->success('OK', [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }
}
