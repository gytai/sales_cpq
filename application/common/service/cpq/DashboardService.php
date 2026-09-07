<?php

namespace app\common\service\cpq;

use think\Db;

/**
 * P01 销售驾驶舱聚合服务（GYTAI-78）。
 *
 * 首屏一次 AJAX 返回全部区块，所有报价统计均经过
 * ReportFilterService（QuoteDataScopeService 数据范围 + 白名单业务筛选），
 * 与 P90-P94 报表、P94 导出保持同一规范化筛选口径。
 * 金额一律输出 Decimal 字符串，前端不做任何浮点换算。
 * 每个区块携带 drilldown 增量筛选，前端合并规范化筛选后跳转报价列表。
 */
class DashboardService
{
    /** 报价有效期天数：与 SchedulerService 定时过期任务的 30 天口径一致。 */
    const QUOTE_VALID_DAYS = 30;

    /** 漏斗固定六段顺序。 */
    const FUNNEL_STATUSES = ['draft', 'submitted', 'approved', 'sent', 'accepted', 'expired'];

    /** 活跃状态（expected_amount 统计口径）。 */
    const ACTIVE_STATUSES = ['draft', 'submitted', 'approved', 'sent'];

    /** 审批通过率分母：submitted 及之后的全部流转态（即非草稿）。 */
    const SUBMITTED_AND_BEYOND = [
        'submitted', 'approved', 'sent', 'accepted', 'rejected',
        'returned', 'withdrawn', 'cancelled', 'expired', 'revised',
    ];

    /** 审批通过率分子。 */
    const APPROVED_STATUSES = ['approved', 'sent', 'accepted'];

    /** @var int */
    private $adminId;

    /**
     * @param int $adminId
     */
    public function __construct($adminId)
    {
        $this->adminId = (int)$adminId;
    }

    /**
     * 规范化原始筛选入参。
     *
     * @param array $raw
     * @return array [规范化筛选, ReportFilterService]
     */
    public function normalize(array $raw)
    {
        $filterService = new ReportFilterService(QuoteDataScopeService::forAdmin($this->adminId));
        return [$filterService->normalize($raw), $filterService];
    }

    /**
     * 驾驶舱首屏总览：一次返回全部区块。
     *
     * @param array $filters normalize 后的筛选
     * @return array
     */
    public function overview(array $filters)
    {
        $filterService = new ReportFilterService(QuoteDataScopeService::forAdmin($this->adminId));
        return [
            'filters' => $filters,
            'kpis' => $this->kpis($filters, $filterService),
            'funnel' => $this->funnel($filters, $filterService),
            'risks' => $this->risks($filters, $filterService),
            'trend' => $this->trend($filters, $filterService),
            'product_distribution' => $this->productDistribution($filters, $filterService),
            'todos' => $this->todos(),
            'quick_links' => $this->quickLinks(),
        ];
    }

    // ------------------------------------------------------------------
    // KPI
    // ------------------------------------------------------------------

    private function kpis(array $filters, ReportFilterService $filterService)
    {
        // month_new：用户传入 created_from/created_to 则用传入区间，否则默认本自然月
        $periodFrom = isset($filters['created_from']) ? $filters['created_from'] : date('Y-m-01');
        $periodTo = isset($filters['created_to']) ? $filters['created_to'] : date('Y-m-t');
        $monthFilters = $filters;
        $monthFilters['created_from'] = $periodFrom;
        $monthFilters['created_to'] = $periodTo;
        $monthNew = (int)$this->quoteQuery($monthFilters, $filterService)->count();

        // 待审批：status=submitted，或存在 active 审批实例（主状态非 submitted 的部分单独计数避免重复）
        $submitted = (int)$this->quoteQuery($filters, $filterService)
            ->where('q.status', 'submitted')->count();
        $activeInstance = (int)$this->quoteQuery($filters, $filterService)
            ->join('__CPQ_APPROVAL_INSTANCE__ ai', "ai.quote_id=q.id AND ai.status='active'")
            ->where('q.status', '<>', 'submitted')
            ->count('DISTINCT q.id');

        $approved = (int)$this->quoteQuery($filters, $filterService)
            ->where('q.status', 'approved')->count();
        $rejected = (int)$this->quoteQuery($filters, $filterService)
            ->where('q.status', 'rejected')->count();

        // 即将到期：approved/sent 且 submitted_at + 30 天（与 SchedulerService 过期口径一致）
        // 落在未来 7 天内，即 submitted_at ∈ (now-30d, now-23d]
        $now = time();
        $validSeconds = self::QUOTE_VALID_DAYS * 86400;
        $expiringSoon = (int)$this->quoteQuery($filters, $filterService)
            ->where('q.status', 'in', ['approved', 'sent'])
            ->where('q.submitted_at', '>', $now - $validSeconds)
            ->where('q.submitted_at', '<=', $now + 7 * 86400 - $validSeconds)
            ->count();

        // 预期金额：活跃状态报价当前版本价格快照 total_amount 合计（按币种分组），
        // 无当前版本快照的报价不计
        $expectedRows = $this->withCurrentSnapshot($this->quoteQuery($filters, $filterService))
            ->where('q.status', 'in', self::ACTIVE_STATUSES)
            ->field('q.currency, SUM(p.total_amount) AS amount, COUNT(DISTINCT q.id) AS quote_count')
            ->group('q.currency')
            ->order('q.currency asc')
            ->select();
        $expectedItems = [];
        foreach ($expectedRows as $row) {
            $expectedItems[] = [
                'currency' => (string)$row['currency'],
                'amount' => (string)$row['amount'],
                'quote_count' => (int)$row['quote_count'],
            ];
        }

        return [
            'month_new' => [
                'count' => $monthNew,
                'period' => ['from' => $periodFrom, 'to' => $periodTo],
                'drilldown' => ['created_from' => $periodFrom, 'created_to' => $periodTo],
            ],
            'pending_approval' => [
                'count' => $submitted + $activeInstance,
                'drilldown' => ['status' => 'submitted'],
            ],
            'approved' => [
                'count' => $approved,
                'drilldown' => ['status' => 'approved'],
            ],
            'rejected' => [
                'count' => $rejected,
                'drilldown' => ['status' => 'rejected'],
            ],
            'expiring_soon' => [
                'count' => $expiringSoon,
                'drilldown' => ['status' => 'approved,sent'],
            ],
            'expected_amount' => [
                'items' => $expectedItems,
                'drilldown' => ['status' => implode(',', self::ACTIVE_STATUSES)],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 漏斗
    // ------------------------------------------------------------------

    private function funnel(array $filters, ReportFilterService $filterService)
    {
        $counts = [];
        $countRows = $this->quoteQuery($filters, $filterService)
            ->field('q.status, COUNT(*) AS cnt')
            ->group('q.status')
            ->select();
        foreach ($countRows as $row) {
            $counts[$row['status']] = (int)$row['cnt'];
        }

        // 金额按当前版本快照合计，无快照的报价金额为 0（不影响计数）
        $amounts = [];
        $amountRows = $this->withCurrentSnapshot($this->quoteQuery($filters, $filterService))
            ->field('q.status, q.currency, SUM(p.total_amount) AS amount')
            ->group('q.status, q.currency')
            ->select();
        foreach ($amountRows as $row) {
            $status = (string)$row['status'];
            if (!isset($amounts[$status])) {
                $amounts[$status] = [];
            }
            $amounts[$status][(string)$row['currency']] = (string)$row['amount'];
        }

        $segments = [];
        foreach (self::FUNNEL_STATUSES as $status) {
            $segments[] = [
                'status' => $status,
                'count' => isset($counts[$status]) ? $counts[$status] : 0,
                'amounts' => isset($amounts[$status]) ? (object)$amounts[$status] : new \stdClass(),
                'drilldown' => ['status' => $status],
            ];
        }
        return ['segments' => $segments];
    }

    // ------------------------------------------------------------------
    // 价格风险（当前版本快照 classification）
    // ------------------------------------------------------------------

    private function risks(array $filters, ReportFilterService $filterService)
    {
        $countByClassification = function ($classification) use ($filters, $filterService) {
            return (int)$this->withCurrentSnapshot($this->quoteQuery($filters, $filterService))
                ->where('p.classification', $classification)
                ->count('DISTINCT q.id');
        };
        $missingPolicy = (int)$this->withCurrentSnapshot($this->quoteQuery($filters, $filterService))
            ->whereRaw("(p.policy_snapshot_json IS NULL OR p.policy_snapshot_json IN ('', '{}', 'null'))")
            ->count('DISTINCT q.id');

        return [
            'need_line_approval' => [
                'count' => $countByClassification('line_approval'),
                'drilldown' => ['status' => 'submitted'],
            ],
            'need_company_approval' => [
                'count' => $countByClassification('company_approval'),
                'drilldown' => ['status' => 'submitted'],
            ],
            'below_company_floor' => [
                'count' => $countByClassification('forbidden'),
                'drilldown' => [],
            ],
            'missing_price_policy' => [
                'count' => $missingPolicy,
                'drilldown' => [],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // 近 12 个月趋势
    // ------------------------------------------------------------------

    /**
     * 趋势忽略 created_from/created_to（自身按月分桶），其余筛选照常叠加。
     */
    private function trend(array $filters, ReportFilterService $filterService)
    {
        $trendFilters = $filters;
        unset($trendFilters['created_from'], $trendFilters['created_to']);

        $buckets = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' months'));
            $buckets[$month] = [
                'month' => $month,
                'quote_count' => 0,
                'approved_count' => 0,
                '_denominator' => 0,
                'approval_rate' => '0.000000',
                'avg_discount' => '1.000000',
                'amounts' => [],
            ];
        }

        $statusRows = $this->quoteQuery($trendFilters, $filterService)
            ->field("FROM_UNIXTIME(q.createtime,'%Y-%m') AS month, q.status, COUNT(*) AS cnt")
            ->group('month, q.status')
            ->select();
        foreach ($statusRows as $row) {
            $month = (string)$row['month'];
            if (!isset($buckets[$month])) {
                continue;
            }
            $count = (int)$row['cnt'];
            $buckets[$month]['quote_count'] += $count;
            if (in_array($row['status'], self::APPROVED_STATUSES, true)) {
                $buckets[$month]['approved_count'] += $count;
            }
            if (in_array($row['status'], self::SUBMITTED_AND_BEYOND, true)) {
                $buckets[$month]['_denominator'] += $count;
            }
        }

        $amountRows = $this->withCurrentSnapshot($this->quoteQuery($trendFilters, $filterService))
            ->field("FROM_UNIXTIME(q.createtime,'%Y-%m') AS month, q.currency, SUM(p.total_amount) AS amount")
            ->group('month, q.currency')
            ->select();
        foreach ($amountRows as $row) {
            $month = (string)$row['month'];
            if (isset($buckets[$month])) {
                $buckets[$month]['amounts'][(string)$row['currency']] = (string)$row['amount'];
            }
        }

        $discountRows = $this->withCurrentSnapshot($this->quoteQuery($trendFilters, $filterService))
            ->field("FROM_UNIXTIME(q.createtime,'%Y-%m') AS month, AVG(p.manual_discount) AS avg_discount")
            ->group('month')
            ->select();
        foreach ($discountRows as $row) {
            $month = (string)$row['month'];
            if (isset($buckets[$month]) && $row['avg_discount'] !== null && $row['avg_discount'] !== '') {
                $buckets[$month]['avg_discount'] = number_format((float)$row['avg_discount'], 6, '.', '');
            }
        }

        $months = [];
        foreach ($buckets as $month => $bucket) {
            $denominator = $bucket['_denominator'];
            $bucket['approval_rate'] = $denominator > 0
                ? bcdiv((string)$bucket['approved_count'], (string)$denominator, 6)
                : '0.000000';
            unset($bucket['_denominator']);
            $bucket['amounts'] = (object)$bucket['amounts'];
            $bucket['drilldown'] = [
                'created_from' => $month . '-01',
                'created_to' => date('Y-m-t', strtotime($month . '-01')),
            ];
            $months[] = $bucket;
        }
        return ['months' => $months];
    }

    // ------------------------------------------------------------------
    // 产品分布（四级，各 top10 + 其他）
    // ------------------------------------------------------------------

    private function productDistribution(array $filters, ReportFilterService $filterService)
    {
        $lineBase = function () use ($filters, $filterService) {
            return $this->quoteQuery($filters, $filterService)
                ->join('__CPQ_QUOTE_LINE__ l', 'l.quote_id=q.id')
                ->join('__CPQ_PRODUCT_MODEL__ m', 'm.id=l.model_id')
                ->join('__CPQ_PRODUCT_SERIES__ s', 's.id=m.series_id');
        };

        $businessUnits = $lineBase()
            ->field("s.business_unit AS name, COUNT(DISTINCT q.id) AS quote_count, COUNT(l.id) AS line_count")
            ->group('s.business_unit')
            ->order('quote_count desc')
            ->select();
        $productLines = $lineBase()
            ->field("q.product_line AS name, COUNT(DISTINCT q.id) AS quote_count, COUNT(l.id) AS line_count")
            ->group('q.product_line')
            ->order('quote_count desc')
            ->select();
        $series = $lineBase()
            ->field("CONCAT(s.code, ' ', s.name) AS name, s.code AS line_code, COUNT(DISTINCT q.id) AS quote_count, COUNT(l.id) AS line_count")
            ->group('s.code, s.name')
            ->order('quote_count desc')
            ->select();
        $models = $lineBase()
            ->field("CONCAT(m.code, ' ', m.name) AS name, COUNT(DISTINCT q.id) AS quote_count, COUNT(l.id) AS line_count")
            ->group('m.code, m.name')
            ->order('quote_count desc')
            ->select();

        return [
            'business_units' => $this->topWithOther($businessUnits),
            'product_lines' => $this->topWithOther($productLines, function ($row) {
                return ['product_line' => (string)$row['name']];
            }),
            'series' => $this->topWithOther($series),
            'models' => $this->topWithOther($models),
        ];
    }

    /**
     * top10 + 其他合计。
     *
     * @param array         $rows
     * @param callable|null $drilldownFor
     * @return array
     */
    private function topWithOther(array $rows, callable $drilldownFor = null)
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'name' => (string)$row['name'],
                'quote_count' => (int)$row['quote_count'],
                'line_count' => (int)$row['line_count'],
                'drilldown' => $drilldownFor ? $drilldownFor($row) : [],
            ];
        }
        $top = array_slice($normalized, 0, 10);
        $rest = array_slice($normalized, 10);
        if ($rest) {
            $quoteCount = 0;
            $lineCount = 0;
            foreach ($rest as $row) {
                $quoteCount += $row['quote_count'];
                $lineCount += $row['line_count'];
            }
            $top[] = [
                'name' => '其他',
                'quote_count' => $quoteCount,
                'line_count' => $lineCount,
                'drilldown' => [],
            ];
        }
        return $top;
    }

    // ------------------------------------------------------------------
    // 待办与快捷入口
    // ------------------------------------------------------------------

    private function todos()
    {
        $result = (new ApprovalService())->myTaskList($this->adminId, 'pending', [], 0, 10);
        return isset($result['rows']) ? $result['rows'] : [];
    }

    private function quickLinks()
    {
        return [
            ['title' => '新建报价', 'url' => 'cpq/quote/wizard'],
            ['title' => '报价管理', 'url' => 'cpq/quote/index'],
            ['title' => '我的待审批', 'url' => 'cpq/approval_task/index'],
            ['title' => '报价导出', 'url' => 'cpq/report_export/index'],
        ];
    }

    // ------------------------------------------------------------------
    // 查询构造
    // ------------------------------------------------------------------

    /**
     * 统一筛选基查询：q=cpq_quote，c=cpq_customer（LEFT JOIN）。
     *
     * @param array               $filters 规范化筛选
     * @param ReportFilterService $filterService
     * @return ReportQuery
     */
    private function quoteQuery(array $filters, ReportFilterService $filterService)
    {
        $query = Db::name('cpq_quote')->alias('q')
            ->join('__CPQ_CUSTOMER__ c', 'c.id=q.customer_id', 'LEFT');
        return $filterService->applyToQuoteQuery($query, $filters, 'q', 'c');
    }

    /**
     * 追加当前版本价格快照 JOIN（r=当前版本，p=价格快照行）。
     *
     * @param object $query
     * @return object
     */
    private function withCurrentSnapshot($query)
    {
        return $query
            ->join('__CPQ_QUOTE_REVISION__ r', 'r.quote_id=q.id AND r.revision_no=q.current_revision_no')
            ->join('__CPQ_QUOTE_PRICE_SNAPSHOT__ p', 'p.revision_id=r.id');
    }
}
