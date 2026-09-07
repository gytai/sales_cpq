<?php

namespace app\admin\controller\cpq;

use app\admin\model\cpq\Quote as QuoteModel;
use app\common\controller\Backend;
use app\common\library\cpq\PricingException;
use app\common\service\cpq\PricingService;
use app\common\service\cpq\ProductLineScopeService;
use app\common\service\cpq\QuoteRevisionService;
use app\common\service\cpq\QuoteDataScopeService;
use app\common\service\cpq\ReportFilterService;
use app\common\service\cpq\SensitiveFieldService;
use think\Db;

/**
 * 报价管理（P50-P58，GYTAI-70）
 *
 * 后台会话代理的报价页面与动作接口：
 *  - index       报价列表（多视图/筛选，产品线数据范围过滤）
 *  - wizard      六步报价向导（新建草稿 / 编辑草稿与撤回态）
 *  - detail      报价详情（状态化操作，非草稿态整页只读）
 *  - diff        版本差异页
 *  - save        保存草稿（新建/更新，乐观锁）
 *  - recalculate 提交前重算（服务端同版本重算，按角色脱敏）
 *  - submit      提交（幂等键，服务端最终校验 + 冻结快照）
 *  - withdraw    撤回；copy 复制为草稿；revision 创建修订版本
 *  - diffdata    版本差异数据
 *
 * 金额一律为服务端 Decimal 字符串原样展示；保存、试算、提交全部以后端
 * 重算结果为准，前端实时交互仅作提示。
 *
 * @icon fa fa-file-text-o
 */
class Quote extends Backend
{
    /** @var QuoteRevisionService */
    private $service;

    public function _initialize()
    {
        parent::_initialize();
        $this->service = new QuoteRevisionService();
    }

    /**
     * 报价列表：多视图（状态分组）+ 关键字/产品线/币种筛选 + 产品线数据范围。
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $page = max(1, (int)$this->request->request('page', 1));
            $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
            $offset = max(0, (int)$this->request->request('offset', ($page - 1) * $limit));

            // 数据范围（产品线 ∩ 组织 ∩ 区域 ∩ owner）统一由下方
            // QuoteDataScopeService 叠加，这里只收集本地业务筛选。
            $where = [];
            $status = trim((string)$this->request->request('status'));
            if ($status !== '') {
                $allowed = array_keys((new QuoteModel())->getStatusList());
                $statuses = array_values(array_intersect(array_map('trim', explode(',', $status)), $allowed));
                if ($statuses === []) {
                    $this->error('非法的状态筛选');
                }
                $where['q.status'] = ['in', $statuses];
            }
            $keyword = trim((string)$this->request->request('keyword'));
            if ($keyword === '') {
                $keyword = trim((string)$this->request->request('search'));
            }
            if ($keyword !== '') {
                $where['q.name|q.code'] = ['like', '%' . $keyword . '%'];
            }
            $productLine = trim((string)$this->request->request('product_line'));
            if ($productLine !== '') {
                $this->scope()->assertLineAllowed($productLine, '无该产品线的数据权限');
                $where['q.product_line'] = ['=', $productLine];
            }
            $currency = strtoupper(trim((string)$this->request->request('currency')));
            if ($currency !== '') {
                $where['q.currency'] = ['=', $currency];
            }

            // 驾驶舱/报表下钻：携带统一筛选键时改走 ReportFilterService 统一路径
            //（与 P01/P90-P94 同一规范化筛选 + 数据范围），否则保持原有本地筛选路径。
            $drilldownKeys = ['company', 'sales_org_id', 'region_id', 'owner_id', 'created_from', 'created_to'];
            $rawFilters = [];
            foreach ($drilldownKeys as $drilldownKey) {
                $drilldownValue = trim((string)$this->request->request($drilldownKey));
                if ($drilldownValue !== '') {
                    $rawFilters[$drilldownKey] = $drilldownValue;
                }
            }
            if ($rawFilters !== []) {
                if ($status !== '') {
                    $rawFilters['status'] = $status;
                }
                if ($productLine !== '') {
                    $rawFilters['product_line'] = $productLine;
                }
                if ($currency !== '') {
                    $rawFilters['currency'] = $currency;
                }
                $filterService = new ReportFilterService(QuoteDataScopeService::forAdmin((int)$this->auth->id));
                try {
                    $filters = $filterService->normalize($rawFilters);
                } catch (\InvalidArgumentException $exception) {
                    $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_FILTER_INVALID']);
                }
                // 计数与取数分别建查询（clone+count 会丢绑定参数）
                $makeUnifiedQuery = function () use ($filterService, $filters) {
                    $query = Db::name('cpq_quote')->alias('q')
                        ->join(config('database.prefix') . 'cpq_customer c', 'c.id = q.customer_id', 'LEFT')
                        ->join(config('database.prefix') . 'cpq_agent a', 'a.id = q.agent_id', 'LEFT')
                        ->join(config('database.prefix') . 'cpq_sales_org o', 'o.id = q.sales_org_id', 'LEFT');
                    return $filterService->applyToQuoteQuery($query, $filters, 'q', 'c');
                };
                $total = (int)$makeUnifiedQuery()->count();
                $rowsQuery = $makeUnifiedQuery();
                if ($keyword !== '') {
                    $rowsQuery->where('q.name|q.code', 'like', '%' . $keyword . '%');
                }
                $rows = $rowsQuery
                    ->field('q.*, c.name AS customer_name, a.code AS agent_name, o.name AS sales_org_name')
                    ->order('q.id desc')
                    ->limit($offset, $limit)
                    ->select();
            } else {
                // 默认列表同样走统一数据范围（产品线 ∩ 组织 ∩ 区域 ∩ owner）：
                // 与下钻路径同一 QuoteDataScopeService 口径，未授权维度 fail-closed。
                $dataScope = QuoteDataScopeService::forAdmin((int)$this->auth->id);
                $makeDefaultQuery = function () use ($where, $dataScope) {
                    $query = Db::name('cpq_quote')->alias('q')
                        ->join(config('database.prefix') . 'cpq_customer c', 'c.id = q.customer_id', 'LEFT');
                    if ($where !== []) {
                        $query->where($where);
                    }
                    return $dataScope->applyToQuoteQuery($query, 'q', 'c');
                };
                $total = (int)$makeDefaultQuery()->count();
                $rows = $makeDefaultQuery()
                    ->join(config('database.prefix') . 'cpq_agent a', 'a.id = q.agent_id', 'LEFT')
                    ->join(config('database.prefix') . 'cpq_sales_org o', 'o.id = q.sales_org_id', 'LEFT')
                    ->field('q.*, c.name AS customer_name, a.code AS agent_name, o.name AS sales_org_name')
                    ->order('q.id desc')
                    ->limit($offset, $limit)
                    ->select();
            }
            foreach ($rows as &$row) {
                $row['status_text'] = (new QuoteModel())->getStatusList()[$row['status']] ?? $row['status'];
            }
            unset($row);
            return json(['total' => $total, 'rows' => $rows]);
        }

        $this->view->assign('statusList', (new QuoteModel())->getStatusList());
        $this->view->assign('productLineList', $this->productLineOptions());
        $this->assignconfig('statusList', (new QuoteModel())->getStatusList());
        $this->assignconfig('productLineList', $this->productLineOptions());
        return $this->view->fetch();
    }

    /**
     * 六步报价向导：无 ids 为新建；带 ids 编辑草稿/撤回态（其余状态只读，跳详情）。
     */
    public function wizard($ids = null)
    {
        $quoteId = (int)$ids;
        $detail = null;
        if ($quoteId > 0) {
            $this->assertScoped($quoteId);
            $detail = $this->quoteDetail($quoteId);
            if (!in_array($detail['status'], QuoteModel::editableStatuses(), true)) {
                $this->error('当前报价状态（' . $detail['status'] . '）不可编辑，请在详情页查看', 'cpq/quote/detail/ids/' . $quoteId);
            }
        }
        $this->view->assign('quoteJson', $this->safeJson($detail ?: new \stdClass()));
        $this->view->assign('row', $detail ?: []);
        $this->view->assign('productLineList', $this->productLineOptions());
        $this->view->assign('companyList', $this->companyOptions($detail ? (string)($detail['company'] ?? '') : ''));
        return $this->view->fetch();
    }

    /**
     * 报价详情：整页只读；操作按钮由前端按状态渲染，服务端逐项重复校验。
     */
    public function detail($ids = null)
    {
        $quoteId = (int)$ids;
        $this->assertScoped($quoteId);
        $detail = $this->quoteDetail($quoteId);
        $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
        $this->view->assign('canViewCost', (bool)array_intersect($roles, array_merge(
            SensitiveFieldService::FULL_ACCESS_ROLES,
            SensitiveFieldService::LINE_ACCESS_ROLES
        )));
        $this->view->assign('canViewCompanyFloor', (bool)array_intersect($roles, SensitiveFieldService::FULL_ACCESS_ROLES));
        $this->view->assign('quoteJson', $this->safeJson($detail));
        return $this->view->fetch();
    }

    /**
     * 版本差异页：选择同一报价的两个已冻结版本进行对比。
     */
    public function diff($ids = null)
    {
        $quoteId = (int)$ids;
        $this->assertScoped($quoteId);
        $quote = Db::name('cpq_quote')->where('id', $quoteId)->find();
        $revisions = Db::name('cpq_quote_revision')
            ->where('quote_id', $quoteId)
            ->order('revision_no asc')
            ->field('id,revision_no,status,price_hash,approval_level,submittable,frozen_at')
            ->select();
        $this->view->assign('quote', $quote);
        $this->view->assign('revisionsJson', $this->safeJson($revisions));
        return $this->view->fetch();
    }

    // ------------------------------------------------------------------
    // 草稿保存（新建 / 乐观锁更新）
    // ------------------------------------------------------------------

    public function save()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $payload = $this->requestPayload();
        $this->respond(function () use ($payload) {
            $payload = $this->applyScopeToPayload($payload);
            $quoteId = (int)($payload['id'] ?? 0);
            unset($payload['id']);
            if ($quoteId > 0) {
                $this->assertScoped($quoteId);
                $lock = (int)($payload['optimistic_lock_version'] ?? 0);
                if ($lock <= 0) {
                    throw new \InvalidArgumentException('缺少乐观锁版本号');
                }
                return $this->service->updateDraft($quoteId, $lock, $payload, (int)$this->auth->id);
            }
            unset($payload['optimistic_lock_version']);
            return $this->service->createDraft($payload, (int)$this->auth->id);
        });
    }

    // ------------------------------------------------------------------
    // 试算 / 提交 / 状态动作
    // ------------------------------------------------------------------

    /**
     * 提交前重算：服务端按当前草稿行重算价格（不冻结），按角色脱敏后返回。
     */
    public function recalculate()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $quoteId = (int)$this->request->post('id');
        $this->respond(function () use ($quoteId) {
            $this->assertScoped($quoteId);
            $result = $this->service->recalcBeforeSubmit($quoteId);
            // 试算不返回完整执行轨迹（轨迹属敏感解释数据），仅保留金额与分级
            foreach ($result['lines'] as $index => $line) {
                unset($result['lines'][$index]['price_trace']);
            }
            $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
            $sensitive = new SensitiveFieldService();
            if ($sensitive->requiresAudit($roles)) {
                $sensitive->recordAccess('view_sensitive', 'cpq_quote', [$quoteId], $roles);
            }
            return (new PricingService())->maskForRoles($result, $roles);
        });
    }

    /**
     * 提交：幂等键防重复；服务端最终配置校验 + 价格重算 + 底线/条款校验 + 冻结快照。
     */
    public function submit()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $payload = $this->requestPayload();
        $quoteId = (int)($payload['id'] ?? 0);
        $idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
        $this->respond(function () use ($quoteId, $idempotencyKey) {
            $this->assertScoped($quoteId);
            return $this->service->submit($quoteId, (int)$this->auth->id, $idempotencyKey);
        });
    }

    public function withdraw()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $quoteId = (int)$this->request->post('id');
        $this->respond(function () use ($quoteId) {
            $this->assertScoped($quoteId);
            return $this->service->withdraw($quoteId, (int)$this->auth->id);
        });
    }

    /**
     * 复制历史报价为新草稿。
     */
    public function copy()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $quoteId = (int)$this->request->post('id');
        $this->respond(function () use ($quoteId) {
            $this->assertScoped($quoteId);
            return $this->service->copy($quoteId, (int)$this->auth->id);
        });
    }

    /**
     * 创建修订版本：原报价置为已修订（只读），新版本为新草稿。
     */
    public function revision()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $payload = $this->requestPayload();
        $quoteId = (int)($payload['id'] ?? 0);
        $note = $payload['note'] ?? [];
        $this->respond(function () use ($quoteId, $note) {
            $this->assertScoped($quoteId);
            return $this->service->createRevision($quoteId, (int)$this->auth->id, is_array($note) ? $note : [$note]);
        });
    }

    /**
     * 版本差异数据。
     */
    public function diffdata()
    {
        $quoteId = (int)$this->request->request('id');
        $from = (int)$this->request->request('from', 0);
        $to = (int)$this->request->request('to', 0);
        $this->respond(function () use ($quoteId, $from, $to) {
            $this->assertScoped($quoteId);
            if ($to <= 0 || $to < $from) {
                throw new \InvalidArgumentException('diff 需要有效的 from/to 版本号');
            }
            $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
            $sensitive = new SensitiveFieldService();
            if ($sensitive->requiresAudit($roles)) {
                $sensitive->recordAccess('view_sensitive', 'cpq_quote', [$quoteId], $roles);
            }
            return $this->service->diffForRoles($quoteId, $from, $to, $roles);
        });
    }

    // ------------------------------------------------------------------
    // 数据权限作用域（与 api/cpq/Quote 同一口径）
    // ------------------------------------------------------------------

    private function scope()
    {
        return ProductLineScopeService::forAdmin((int)$this->auth->id);
    }

    private function assertScoped($quoteId)
    {
        $quote = Db::name('cpq_quote')->where('id', (int)$quoteId)->find();
        if (!$quote) {
            throw new \InvalidArgumentException('报价不存在');
        }
        $this->scope()->assertLineAllowed((string)$quote['product_line'], '无该产品线的数据权限');
        // 组织/区域/负责人维度的单条兜底，与列表查询层同一口径
        QuoteDataScopeService::forAdmin((int)$this->auth->id)->assertQuoteAccess($quote);
    }

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

    /**
     * 可选产品线：已发布产品系列的产品线编码，受限账号按授权范围过滤。
     *
     * @return array
     */
    private function productLineOptions()
    {
        $lines = Db::name('cpq_product_series')
            ->where('status', 'published')
            ->group('product_line')
            ->column('product_line');
        $scope = $this->scope();
        if (!$scope->isUnrestricted()) {
            $allowed = $scope->getAllowedLines() ?: [];
            $lines = array_values(array_intersect($lines, $allowed));
        }
        return array_values(array_filter(array_map('strval', $lines), function ($line) {
            return $line !== '';
        }));
    }

    /**
     * 我方公司下拉选项：取已发布价格表的 company 去重，保证所选公司必然能
     * 命中价格表（与 PricingService::scopeMatches 的 company 维度对齐）。
     * 若当前报价的 company 不在价格表列表中（历史遗留不一致），保留该值供人工纠正。
     */
    private function companyOptions($current = '')
    {
        $companies = Db::name('cpq_price_book')
            ->where('status', 'published')
            ->group('company')
            ->column('company');
        $companies = array_values(array_filter(array_map('strval', $companies), function ($company) {
            return $company !== '';
        }));
        if ($current !== '' && !in_array($current, $companies, true)) {
            $companies[] = $current;
        }
        return $companies;
    }

    // ------------------------------------------------------------------
    // 详情组装（行、条款、版本历史；历史版本只读快照由冻结 JSON 承载）
    // ------------------------------------------------------------------

    private function quoteDetail($quoteId)
    {
        $quote = Db::name('cpq_quote')->alias('q')
            ->join(config('database.prefix') . 'cpq_customer c', 'c.id = q.customer_id', 'LEFT')
            ->join(config('database.prefix') . 'cpq_agent a', 'a.id = q.agent_id', 'LEFT')
            ->join(config('database.prefix') . 'cpq_sales_org o', 'o.id = q.sales_org_id', 'LEFT')
            ->field('q.*, c.name AS customer_name, a.code AS agent_name, o.name AS sales_org_name')
            ->where('q.id', (int)$quoteId)
            ->find();
        if (!$quote) {
            throw new \InvalidArgumentException('报价不存在');
        }
        $quote['status_text'] = (new QuoteModel())->getStatusList()[$quote['status']] ?? $quote['status'];

        $lines = Db::name('cpq_quote_line')->where('quote_id', $quoteId)->order('line_no asc')->select();
        $modelIds = array_filter(array_map(function ($line) {
            return (int)$line['model_id'];
        }, $lines));
        $models = $modelIds
            ? Db::name('cpq_product_model')->where('id', 'in', $modelIds)->column('code,name', 'id')
            : [];
        $quote['lines'] = array_map(function ($line) use ($models) {
            $model = $models[(int)$line['model_id']] ?? null;
            return [
                'line_no' => (int)$line['line_no'],
                'model_id' => (int)$line['model_id'],
                'model_code' => $model ? (string)$model['code'] : '',
                'model_name' => $model ? (string)$model['name'] : '',
                'quantity' => (string)$line['quantity'],
                'unit' => (string)$line['unit'],
                'configuration' => $line['configuration_json'] ? (json_decode($line['configuration_json'], true) ?: []) : [],
                'configuration_hash' => (string)$line['configuration_hash'],
                'bom' => $line['bom_json'] ? (json_decode($line['bom_json'], true) ?: []) : [],
                'manual_discount' => $line['manual_discount'] === null ? null : (string)$line['manual_discount'],
                'discount_reason' => (string)$line['discount_reason'],
                'accessories' => $line['accessories_json'] ? (json_decode($line['accessories_json'], true) ?: []) : [],
            ];
        }, $lines);

        $quote['terms'] = Db::name('cpq_quote_term')
            ->where('quote_id', $quoteId)
            ->whereNull('revision_id')
            ->order('sort asc,id asc')
            ->select();

        $quote['revisions'] = Db::name('cpq_quote_revision')
            ->where('quote_id', $quoteId)
            ->order('revision_no asc')
            ->field('id,revision_no,status,price_hash,approval_level,submittable,block_reasons_json,frozen_at,created_by')
            ->select();

        $quote['price_snapshot'] = $this->priceSnapshot($quoteId, (int)($quote['current_revision_no'] ?? 0));

        return $quote;
    }

    /**
     * 当前版本冻结价格快照（GYTAI-77：详情页展示冻结金额，不随主数据变化）。
     *
     * 快照是提交时冻结的计价事实：金额按定价币种存放，报价币种金额在
     * converted_amounts_json；成本/公司控制价等敏感字段按角色脱敏——
     * 仅公司级定价角色可见控制单价，成本与毛利不随详情下发。
     *
     * @param int $quoteId           报价 ID
     * @param int $currentRevisionNo 当前版本号（草稿为 0，无快照）
     * @return array|null
     */
    private function priceSnapshot($quoteId, $currentRevisionNo)
    {
        if ($currentRevisionNo <= 0) {
            return null;
        }
        $revision = Db::name('cpq_quote_revision')
            ->where('quote_id', $quoteId)
            ->where('revision_no', $currentRevisionNo)
            ->field('id,revision_no,frozen_at,approval_level')
            ->find();
        if (!$revision) {
            return null;
        }
        $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
        $canViewCompanyFloor = (bool)array_intersect($roles, SensitiveFieldService::FULL_ACCESS_ROLES);

        $lines = Db::name('cpq_quote_line')->where('quote_id', $quoteId)->order('line_no asc')->column('line_no', 'id');
        $rows = Db::name('cpq_quote_price_snapshot')
            ->where('revision_id', (int)$revision['id'])
            ->order('id asc')
            ->select();
        $snapshotLines = [];
        $totals = ['untaxed' => '0', 'tax' => '0', 'total' => '0'];
        $quoteCurrency = '';
        $exchangeRate = null;
        foreach ($rows as $row) {
            $converted = $row['converted_amounts_json'] ? (json_decode($row['converted_amounts_json'], true) ?: []) : [];
            $quoteCurrency = $quoteCurrency !== '' ? $quoteCurrency : (string)$row['quote_currency'];
            $exchangeRate = $exchangeRate !== null ? $exchangeRate
                : ($row['exchange_rate_snapshot_json'] ? (json_decode($row['exchange_rate_snapshot_json'], true) ?: null) : null);
            $line = [
                'line_no' => (int)($lines[(int)$row['quote_line_id']] ?? 0),
                'model_code' => (string)$row['model_code'],
                'quantity' => (string)$row['quantity'],
                'pricing_currency' => (string)$row['pricing_currency'],
                'quote_currency' => (string)$row['quote_currency'],
                'manual_discount' => $row['manual_discount'] === null ? null : (string)$row['manual_discount'],
                'unit_subtotal' => (string)$row['unit_subtotal'],
                'fees_amount' => (string)$row['fees_amount'],
                'untaxed' => (string)$row['untaxed_amount'],
                'tax' => (string)$row['tax_amount'],
                'total' => (string)$row['total_amount'],
                'converted' => $converted,
                'classification' => (string)$row['classification'],
                'approval_level' => (string)$row['approval_level'],
            ];
            if ($canViewCompanyFloor) {
                $line['control_unit_price'] = (string)$row['control_unit_price'];
            }
            $snapshotLines[] = $line;
            // 跨币种时整单合计必须按报价币种（converted）口径求和；同币种 converted 为空，直接用定价币种列
            foreach (['untaxed', 'tax', 'total'] as $key) {
                $amount = isset($converted[$key]) ? (string)$converted[$key] : (string)$row[$key . '_amount'];
                $totals[$key] = bcadd($totals[$key], $amount, 4);
            }
        }
        return [
            'revision_no' => (int)$revision['revision_no'],
            'frozen_at' => $revision['frozen_at'] ? date('Y-m-d H:i:s', (int)$revision['frozen_at']) : '',
            'pricing_currency' => $rows ? (string)$rows[0]['pricing_currency'] : '',
            'quote_currency' => $quoteCurrency,
            'exchange_rate' => $exchangeRate,
            'totals' => $totals,
            'lines' => $snapshotLines,
        ];
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

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

    /**
     * 输出给模板的 JSON：防 XSS 转义，供 <script> 内嵌使用。
     */
    private function safeJson($value)
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function respond(callable $callback)
    {
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = $callback();
        } catch (PricingException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => $exception->getBusinessCode(),
                'trace_id' => $traceId,
                'payload' => $exception->getDetails(),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => 'CPQ_QUOTE_INVALID',
                'trace_id' => $traceId,
            ]);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => 'CPQ_QUOTE_FORBIDDEN',
                'trace_id' => $traceId,
            ]);
        } catch (\Throwable $exception) {
            $this->error('报价服务异常', null, [
                'business_code' => 'CPQ_INTERNAL_ERROR',
                'trace_id' => $traceId,
            ]);
        }
        $this->success('OK', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }
}
