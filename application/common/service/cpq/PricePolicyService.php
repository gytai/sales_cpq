<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/**
 * CPQ 三层价格策略服务（GYTAI-69，方案 §4 / P28）。
 *
 * 职责：
 *  - 金额规范化与三层价格约束（指导价 >= 产线控制价 >= 公司控制价 >= 0）；
 *  - 策略维度哈希（dimension_key）与时间区间重叠冲突检测；
 *  - 价格表适用范围（公司/业务板块/市场/币种 + 优先级）冲突检测；
 *  - 价格表对已发布型号的价格覆盖度报告；
 *  - 报价相对控制价的分级（正常 / 产线审批 / 公司审批 / 禁止）。
 *
 * 金额一律字符串 + bcmath（scale 4），禁止 float 运算。
 */
class PricePolicyService
{
    /** 金额比较/存储精度 */
    const SCALE = 4;

    /** 维度哈希的固定键序（缺失维度一律按空字符串参与哈希） */
    const DIMENSION_KEYS = [
        'company', 'business_unit', 'market_scope', 'region_code',
        'customer_level', 'agent_level', 'customer_id', 'agent_id', 'product_line',
        'target_type', 'target_id', 'currency', 'unit',
    ];

    /** 时间区间重叠判定中 NULL 失效日期的替代值 */
    const MAX_DATE = '9999-12-31';

    /**
     * 金额规范化：拒绝非数值/负数/超过 8 位小数，返回 scale 4 字符串。
     *
     * @param mixed  $value
     * @param string $field 字段中文名（用于报错信息）
     * @return string
     * @throws InvalidArgumentException
     */
    public static function normalizeAmount($value, $field = '金额')
    {
        $text = is_string($value) ? trim($value) : $value;
        if ($text === null || $text === '' || !is_numeric($text)) {
            throw new InvalidArgumentException($field . '必须是数值');
        }
        $text = (string)$text;
        if (bccomp($text, '0', 8) < 0) {
            throw new InvalidArgumentException($field . '不能为负数');
        }
        $dotPosition = strpos($text, '.');
        if ($dotPosition !== false && strlen($text) - $dotPosition - 1 > 8) {
            throw new InvalidArgumentException($field . '最多支持 8 位小数');
        }
        return bcadd($text, '0', self::SCALE);
    }

    /**
     * 三层价格约束：指导价 >= 产线控制价 >= 公司控制价 >= 0。
     *
     * @param string $guidePrice   指导价
     * @param string $lineFloor    产线控制价
     * @param string $companyFloor 公司控制价
     * @throws InvalidArgumentException
     */
    public static function assertPriceHierarchy($guidePrice, $lineFloor, $companyFloor)
    {
        if (bccomp((string)$companyFloor, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('公司控制价不能小于0');
        }
        if (bccomp((string)$lineFloor, (string)$companyFloor, self::SCALE) < 0) {
            throw new InvalidArgumentException('产线控制价不能低于公司控制价');
        }
        if (bccomp((string)$guidePrice, (string)$lineFloor, self::SCALE) < 0) {
            throw new InvalidArgumentException('指导价不能低于产线控制价');
        }
    }

    /**
     * 策略维度哈希：固定键序规范化后 json_encode + sha256。
     *
     * @param array $dims 含维度字段的行/数组
     * @return string
     */
    public static function dimensionKey(array $dims)
    {
        $normalized = [];
        foreach (self::DIMENSION_KEYS as $key) {
            $value = $dims[$key] ?? '';
            // agent_id 是后续新增维度：空值时省略以保持既有策略哈希兼容，
            // 指定代理商时才写入哈希并与其他维度组合形成唯一范围。
            if ($key === 'agent_id' && (int)$value <= 0) {
                continue;
            }
            if (in_array($key, ['customer_id', 'agent_id', 'target_id'], true)) {
                $normalized[$key] = (string)(int)$value;
            } else {
                $normalized[$key] = trim((string)$value);
            }
        }
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 同维度价格策略冲突检测：同 dimension_key 且 待审批/已发布 的策略
     * 不允许时间区间重叠（expiry_date 为 NULL 视为 9999-12-31）。
     *
     * @param array $row       cpq_price_policy 行数据（含维度字段与时间区间）
     * @param int   $excludeId 排除自身（更新/发布校验时传当前行 ID）
     * @throws InvalidArgumentException
     */
    public function assertNoDimensionOverlap(array $row, $excludeId = 0)
    {
        $dimensionKey = trim((string)($row['dimension_key'] ?? ''));
        if ($dimensionKey === '') {
            $dimensionKey = self::dimensionKey($row);
        }
        list($effective, $expiry) = $this->timeRangeOf($row);

        $conflicts = Db::name('cpq_price_policy')
            ->where('dimension_key', $dimensionKey)
            ->where('status', 'in', ['pending', 'published'])
            ->where('id', '<>', (int)$excludeId)
            ->where('effective_date', '<=', $expiry)
            ->where(function ($query) use ($effective) {
                $query->whereNull('expiry_date')->whereOr('expiry_date', '>=', $effective);
            })
            ->count();
        if ($conflicts > 0) {
            throw new InvalidArgumentException('同一维度已存在时间区间重叠的价格策略');
        }
    }

    /** 无物理外键的客户/代理商策略维度在发布前做等价完整性校验。 */
    public function assertReferencedDimensionsExist(array $row)
    {
        foreach ([
            'customer_id' => ['cpq_customer', '客户'],
            'agent_id' => ['cpq_agent', '代理商'],
        ] as $field => $definition) {
            $id = (int)($row[$field] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!Db::name($definition[0])->where('id', $id)->where('status', 'normal')->count()) {
                throw new InvalidArgumentException($definition[1] . '不存在或已停用：' . $id);
            }
        }
    }

    /**
     * 价格表适用范围冲突检测：同 (公司, 业务板块, 市场范围, 币种) 下不允许
     * 存在优先级相同且时间区间重叠的已发布价格表。
     *
     * @param array $row       cpq_price_book 行数据
     * @param int   $excludeId 排除自身
     * @throws InvalidArgumentException
     */
    public function assertBookScopeNoOverlap(array $row, $excludeId = 0)
    {
        list($effective, $expiry) = $this->timeRangeOf($row);

        $conflicts = Db::name('cpq_price_book')
            ->where('company', trim((string)($row['company'] ?? '')))
            ->where('business_unit', trim((string)($row['business_unit'] ?? '')))
            ->where('market_scope', (string)($row['market_scope'] ?? 'all'))
            ->where('currency', (string)($row['currency'] ?? 'CNY'))
            ->where('priority', (int)($row['priority'] ?? 0))
            ->where('status', 'published')
            ->where('id', '<>', (int)$excludeId)
            ->where('effective_date', '<=', $expiry)
            ->where(function ($query) use ($effective) {
                $query->whereNull('expiry_date')->whereOr('expiry_date', '>=', $effective);
            })
            ->count();
        if ($conflicts > 0) {
            throw new InvalidArgumentException('同一适用范围内不允许存在优先级相同且时间重叠的已发布价格表');
        }
    }

    /**
     * 价格表覆盖度报告：全部已发布型号中，哪些在该价格表内缺少
     * target_type='model' 的价格条目。
     *
     * @param int $priceBookId
     * @return array ['total'=>n,'covered'=>m,'missing'=>[['model_id'=>,'code'=>,'name'=>],...]]
     */
    public function coverageReport($priceBookId)
    {
        $models = Db::name('cpq_product_model')
            ->where('status', 'published')
            ->field('id,code,name')
            ->select();
        $covered = Db::name('cpq_price_entry')
            ->where('price_book_id', (int)$priceBookId)
            ->where('target_type', 'model')
            ->column('target_id');
        $covered = array_map('intval', $covered);

        $missing = [];
        foreach ($models as $model) {
            if (in_array((int)$model['id'], $covered, true)) {
                continue;
            }
            $missing[] = [
                'model_id' => (int)$model['id'],
                'code' => (string)$model['code'],
                'name' => (string)$model['name'],
            ];
        }
        return [
            'total' => count($models),
            'covered' => count($models) - count($missing),
            'missing' => $missing,
        ];
    }

    /**
     * 报价相对三层控制价的分级。
     *
     * @param string $unitPrice 报价单价（金额字符串）
     * @param array  $policy    含 guide_price/line_floor/company_floor 的策略行
     * @return string normal|line_approval|company_approval|forbidden
     */
    public static function classifyAgainstFloors($unitPrice, array $policy)
    {
        $unitPrice = (string)$unitPrice;
        if (bccomp($unitPrice, (string)($policy['guide_price'] ?? '0'), self::SCALE) >= 0) {
            return 'normal';
        }
        if (bccomp($unitPrice, (string)($policy['line_floor'] ?? '0'), self::SCALE) >= 0) {
            return 'line_approval';
        }
        if (bccomp($unitPrice, (string)($policy['company_floor'] ?? '0'), self::SCALE) >= 0) {
            return 'company_approval';
        }
        return 'forbidden';
    }

    /**
     * 行数据 → [生效日期, 失效日期(空值转 9999-12-31)]。
     *
     * @param array $row
     * @return array
     */
    private function timeRangeOf(array $row)
    {
        $effective = (string)($row['effective_date'] ?? '');
        $expiry = $row['expiry_date'] ?? null;
        $expiry = ($expiry === null || $expiry === '') ? self::MAX_DATE : (string)$expiry;
        return [$effective, $expiry];
    }
}
