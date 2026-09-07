<?php

namespace app\common\service\cpq;

use app\common\library\cpq\RuleDsl;
use InvalidArgumentException;
use think\Db;

/**
 * CPQ 价格规则发布校验服务（GYTAI-69，方案 §4.3）。
 *
 * 价格规则的条件 JSON 复用配置规则的受控 DSL 结构（all/any/not 嵌套 +
 * field/operator/value 叶子），但字段白名单是价格域专用的客户/渠道维度；
 * 发布前校验时间区间合法性与互斥组冲突（同互斥组同优先级的规则不允许
 * 条件可能同时命中且时间重叠）。
 */
class PriceRuleService
{
    /** 价格规则条件字段白名单（客户/渠道/产品/数量维度） */
    const ALLOWED_FIELDS = [
        'customer_id', 'agent_id', 'customer_level', 'agent_level', 'region_code',
        'business_unit', 'product_line', 'model_id', 'model_code', 'market_scope',
        'currency', 'pricing_currency', 'quantity', 'min_qty', 'max_qty',
    ];

    /** 时间区间重叠判定中 NULL 失效日期的替代值 */
    const MAX_DATE = '9999-12-31';

    /**
     * 校验条件 JSON：合法 JSON + 结构（all/any/not/叶子）+ 字段/比较符白名单。
     *
     * @param string $json
     * @return array 解码后的数组
     * @throws InvalidArgumentException
     */
    public function assertConditionJson($json)
    {
        $decoded = json_decode((string)$json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new InvalidArgumentException('条件必须是合法 JSON 对象');
        }
        $this->assertConditionNode($decoded);
        return $decoded;
    }

    /**
     * 发布校验：条件白名单 + 时间区间合法 + 互斥组冲突检测。
     *
     * @param array $row       cpq_price_rule 行数据
     * @param int   $excludeId 排除自身（发布当前行时传其 ID）
     * @throws InvalidArgumentException
     */
    public function assertPublishable(array $row, $excludeId = 0)
    {
        $condition = $this->assertConditionJson($row['condition_json'] ?? '');

        $effective = (string)($row['effective_date'] ?? '');
        $expiry = $row['expiry_date'] ?? null;
        if ($expiry !== null && $expiry !== '' && $effective !== '' && strcmp($effective, (string)$expiry) > 0) {
            throw new InvalidArgumentException('生效日期不能晚于失效日期');
        }

        $group = trim((string)($row['exclusive_group'] ?? ''));
        if ($group === '') {
            return;
        }

        $candidates = Db::name('cpq_price_rule')
            ->where('exclusive_group', $group)
            ->where('priority', (int)($row['priority'] ?? 0))
            ->where('status', 'in', ['pending', 'published'])
            ->where('id', '<>', (int)$excludeId)
            ->field('id,code,condition_json,effective_date,expiry_date')
            ->select();
        if (!$candidates) {
            return;
        }

        $ownMap = [];
        $this->flattenEqualityLeaves($condition, $ownMap);
        foreach ($candidates as $candidate) {
            if (!$this->timeOverlaps($effective, $expiry, $candidate['effective_date'], $candidate['expiry_date'])) {
                continue;
            }
            $otherMap = [];
            try {
                $this->flattenEqualityLeaves($this->assertConditionJson($candidate['condition_json']), $otherMap);
            } catch (InvalidArgumentException $exception) {
                // 存量数据条件无法解析时按"可能同时命中"保守处理
                $otherMap = [];
            }
            if (self::conditionsMayBothHit($ownMap, $otherMap)) {
                throw new InvalidArgumentException('同一互斥组内存在优先级相同且条件重叠的规则，禁止发布');
            }
        }
    }

    // ------------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------------

    /**
     * 递归校验条件节点：结构与 RuleDsl 一致，叶子字段须在价格域白名单内。
     *
     * @param array $condition
     * @throws InvalidArgumentException
     */
    private function assertConditionNode(array $condition)
    {
        if ($condition === []) {
            return;
        }
        if (isset($condition['all']) || isset($condition['any'])) {
            $children = $condition['all'] ?? $condition['any'];
            if (!is_array($children) || $children === []) {
                throw new InvalidArgumentException('条件 all/any 必须是非空数组');
            }
            foreach ($children as $child) {
                if (!is_array($child)) {
                    throw new InvalidArgumentException('条件 all/any 的子项必须是对象');
                }
                $this->assertConditionNode($child);
            }
            return;
        }
        if (isset($condition['not'])) {
            if (!is_array($condition['not'])) {
                throw new InvalidArgumentException('条件 not 必须是对象');
            }
            $this->assertConditionNode($condition['not']);
            return;
        }
        $field = (string)($condition['field'] ?? '');
        if ($field === '' || !preg_match(RuleDsl::FIELD_PATTERN, $field)) {
            throw new InvalidArgumentException('条件字段缺失或包含非法字符');
        }
        if (!in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException('条件包含不受支持的字段：' . $field);
        }
        $operator = strtolower((string)($condition['operator'] ?? '='));
        if (!in_array($operator, RuleDsl::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException('条件包含不受支持的比较符：' . $operator);
        }
    }

    /**
     * 两区间时间重叠判定（NULL 失效日期视为 9999-12-31）。
     *
     * @param string      $aEffective
     * @param string|null $aExpiry
     * @param string      $bEffective
     * @param string|null $bExpiry
     * @return bool
     */
    private function timeOverlaps($aEffective, $aExpiry, $bEffective, $bExpiry)
    {
        $aEnd = ($aExpiry === null || $aExpiry === '') ? self::MAX_DATE : (string)$aExpiry;
        $bEnd = ($bExpiry === null || $bExpiry === '') ? self::MAX_DATE : (string)$bExpiry;
        return strcmp((string)$aEffective, $bEnd) <= 0 && strcmp((string)$bEffective, $aEnd) <= 0;
    }

    /**
     * 把条件树中所有 '='/'in' 叶子拍平为 field → 取值集合 的映射，
     * 供互斥组冲突的保守判定使用（not/any 分支同样拍平，宁多报不漏报）。
     *
     * @param array $node
     * @param array $map 输出：field => [valueKey => true]
     */
    private function flattenEqualityLeaves(array $node, array &$map)
    {
        if (isset($node['all']) || isset($node['any'])) {
            $children = $node['all'] ?? $node['any'];
            foreach ((array)$children as $child) {
                if (is_array($child)) {
                    $this->flattenEqualityLeaves($child, $map);
                }
            }
            return;
        }
        if (isset($node['not']) && is_array($node['not'])) {
            $this->flattenEqualityLeaves($node['not'], $map);
            return;
        }
        $field = $node['field'] ?? null;
        if (!$field) {
            return;
        }
        $operator = strtolower((string)($node['operator'] ?? '='));
        if (in_array($operator, ['=', 'eq'], true)) {
            $values = [$node['value'] ?? null];
        } elseif ($operator === 'in') {
            $values = isset($node['value']) && is_array($node['value']) ? $node['value'] : [$node['value'] ?? null];
        } else {
            return;
        }
        foreach ($values as $value) {
            $map[$field][json_encode($value)] = true;
        }
    }

    /**
     * 保守判定两条件是否可能同时命中：只要存在一个共同字段且双方在该
     * 字段上的取值集合不相交，就不可能同时命中；否则按可能命中处理。
     *
     * @param array $a field => 取值集合
     * @param array $b field => 取值集合
     * @return bool
     */
    private static function conditionsMayBothHit(array $a, array $b)
    {
        foreach ($a as $field => $valuesA) {
            if (!isset($b[$field])) {
                continue;
            }
            if (!array_intersect_key($valuesA, $b[$field])) {
                return false;
            }
        }
        return true;
    }
}
