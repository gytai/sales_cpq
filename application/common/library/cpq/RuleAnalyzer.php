<?php

namespace app\common\library\cpq;

use InvalidArgumentException;

/**
 * CPQ 配置规则静态分析（GYTAI-66，方案 P18）。
 *
 * 在规则发布前对"同一生效范围内的规则全集"做确定性静态分析：
 *  - 循环依赖：规则条件读取的配置字段被另一条规则的赋值动作写入，
 *    形成闭环（A 读 x 写 y，B 读 y 写 x）；
 *  - 永真冲突：无条件（条件为空）的规则之间互相矛盾，或动作自身
 *    永假（min>max、one_of 候选为空）；
 *  - 不可达选项：永真阻断规则使某个已发布选项/配置组无论如何选择
 *    都无法通过校验。
 *
 * 本类为纯函数实现，不触库、不执行任何动态代码，可直接单测；
 * 数据库装载由 MasterDataLifecycleService 负责。
 */
class RuleAnalyzer
{
    /** 会改写配置值的动作（只有它们参与依赖图） */
    const MUTATING_ACTIONS = ['default', 'set', 'formula'];

    /**
     * 对规则全集做完整静态分析。
     *
     * @param array $rules  [['code'=>, 'condition'=>array, 'actions'=>array], ...]
     * @param array $groups 可选，配置组（list 或 code=>group），用于不可达选项检测
     * @return array ['cycles'=>[...], 'conflicts'=>[...], 'unreachable'=>[...]]
     */
    public static function analyze(array $rules, array $groups = [])
    {
        $normalized = self::normalizeRules($rules);
        return [
            'cycles' => self::findCycles($normalized),
            'conflicts' => self::findEverTrueConflicts($normalized),
            'unreachable' => self::findUnreachableOptions($normalized, $groups),
        ];
    }

    /**
     * 发布前断言：存在任何静态问题时抛出 InvalidArgumentException。
     *
     * @param array $rules
     * @param array $groups
     * @throws InvalidArgumentException
     */
    public static function assertPublishable(array $rules, array $groups = [])
    {
        $issues = self::analyze($rules, $groups);
        $messages = [];
        foreach ($issues['cycles'] as $cycle) {
            $messages[] = '配置规则存在循环依赖：' . implode(' → ', $cycle['path'])
                . '（涉及规则 ' . implode('、', $cycle['rule_codes']) . '）';
        }
        foreach ($issues['conflicts'] as $conflict) {
            $messages[] = '配置规则存在永真冲突：' . $conflict;
        }
        foreach ($issues['unreachable'] as $unreachable) {
            $messages[] = '配置规则导致选项不可达：' . $unreachable;
        }
        if ($messages) {
            throw new InvalidArgumentException(implode('；', $messages));
        }
    }

    // ------------------------------------------------------------------
    // 循环依赖
    // ------------------------------------------------------------------

    /**
     * 依赖图检测环：条件读取的配置字段 → 赋值动作写入的目标字段。
     *
     * @param array $rules 规范化规则
     * @return array 每个环 ['path'=>[字段链], 'rule_codes'=>[...]]
     */
    public static function findCycles(array $rules)
    {
        // edges[source][] = ['target'=>, 'rule'=>]
        $edges = [];
        foreach ($rules as $rule) {
            $sources = self::conditionFields($rule['condition']);
            $targets = [];
            foreach ($rule['actions'] as $action) {
                $actionType = strtolower((string)($action['action'] ?? ''));
                if (!in_array($actionType, self::MUTATING_ACTIONS, true)) {
                    continue;
                }
                $target = trim((string)($action['target'] ?? ''));
                if ($target !== '') {
                    $targets[$target] = true;
                }
            }
            foreach ($sources as $source) {
                foreach (array_keys($targets) as $target) {
                    if ($source !== $target) {
                        $edges[$source][] = ['target' => $target, 'rule' => $rule['code']];
                    }
                }
            }
        }

        return self::detectCyclesWithReference($edges);
    }

    /**
     * 基于引用栈的 DFS 环检测（PHP 闭包内数组栈必须引用传递，否则
     * 递归分支之间会丢失栈状态）。
     *
     * @param array $edges
     * @return array
     */
    private static function detectCyclesWithReference(array $edges)
    {
        $cycles = [];
        $seen = [];
        $state = [];
        $stack = [];
        $visit = null;
        $visit = function ($node) use (&$visit, &$edges, &$cycles, &$seen, &$state, &$stack) {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($edges[$node] ?? [] as $edge) {
                $next = $edge['target'];
                if (($state[$next] ?? 0) === 1) {
                    $start = array_search($next, $stack, true);
                    $path = array_slice($stack, $start);
                    $path[] = $next;
                    $key = self::cycleKey($path);
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $ruleCodes = [];
                        $length = count($path);
                        for ($i = 0; $i < $length - 1; $i++) {
                            foreach ($edges[$path[$i]] ?? [] as $candidate) {
                                if ($candidate['target'] === $path[$i + 1]) {
                                    $ruleCodes[$candidate['rule']] = true;
                                }
                            }
                        }
                        $cycles[] = ['path' => $path, 'rule_codes' => array_keys($ruleCodes)];
                    }
                    continue;
                }
                if (($state[$next] ?? 0) === 0) {
                    $visit($next);
                }
            }
            array_pop($stack);
            $state[$node] = 2;
        };
        foreach (array_keys($edges) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit($node);
            }
        }
        return $cycles;
    }

    /**
     * 环的规范化键（旋转到字典序最小起点），避免同一环被多次报告。
     *
     * @param array $path 首尾相同的字段链
     * @return string
     */
    private static function cycleKey(array $path)
    {
        array_pop($path); // 去掉重复的首节点
        $count = count($path);
        $best = null;
        for ($i = 0; $i < $count; $i++) {
            $rotated = array_merge(array_slice($path, $i), array_slice($path, 0, $i));
            $candidate = implode('>', $rotated);
            if ($best === null || $candidate < $best) {
                $best = $candidate;
            }
        }
        return (string)$best;
    }

    /**
     * 提取条件中引用的配置字段（去掉 configuration. 前缀）。
     *
     * @param array $condition
     * @return array 字段列表（去重）
     */
    public static function conditionFields(array $condition)
    {
        $fields = [];
        if (isset($condition['all']) || isset($condition['any'])) {
            foreach ((array)($condition['all'] ?? $condition['any']) as $child) {
                foreach (self::conditionFields((array)$child) as $field) {
                    $fields[$field] = true;
                }
            }
            return array_keys($fields);
        }
        if (isset($condition['not'])) {
            return self::conditionFields((array)$condition['not']);
        }
        $field = (string)($condition['field'] ?? '');
        if (strpos($field, 'configuration.') === 0) {
            $fields[substr($field, strlen('configuration.'))] = true;
        }
        return array_keys($fields);
    }

    // ------------------------------------------------------------------
    // 永真冲突
    // ------------------------------------------------------------------

    /**
     * 永真冲突检测：
     *  - 多条永真规则强制同一目标为不同结果（set/formula）；
     *  - 永真 require 与永真 exclude 指向同一目标同一取值；
     *  - 永真赋值（set/default）与永真 exclude 指向同一目标同一取值；
     *  - min_max 动作 min > max（永假，无论条件是否为空）。
     *
     * @param array $rules 规范化规则
     * @return array 冲突描述列表
     */
    public static function findEverTrueConflicts(array $rules)
    {
        $conflicts = [];
        $forced = [];   // target => [ ['rule'=>, 'value'=>, 'kind'=>set|formula|default] ]
        $requires = []; // target|valueKey => rule
        $excludes = []; // target|valueKey => rule

        foreach ($rules as $rule) {
            $everTrue = $rule['condition'] === [];
            foreach ($rule['actions'] as $action) {
                $actionType = strtolower((string)($action['action'] ?? ''));
                $target = trim((string)($action['target'] ?? ''));

                if ($actionType === 'min_max') {
                    $min = $action['min'] ?? null;
                    $max = $action['max'] ?? null;
                    if ($min !== null && $max !== null && is_numeric($min) && is_numeric($max)
                        && bccomp((string)$min, (string)$max, 8) > 0
                    ) {
                        $conflicts[] = sprintf(
                            '规则 %s 的取值范围永假（min=%s 大于 max=%s，目标 %s）',
                            $rule['code'], $min, $max, $target
                        );
                    }
                    continue;
                }

                if (!$everTrue || $target === '') {
                    continue;
                }

                if (in_array($actionType, self::MUTATING_ACTIONS, true)) {
                    $forced[$target][] = [
                        'rule' => $rule['code'],
                        'value' => array_key_exists('value', $action) ? json_encode($action['value']) : null,
                        'kind' => $actionType,
                    ];
                    continue;
                }

                if ($actionType === 'require' || $actionType === 'exclude') {
                    $valueKey = array_key_exists('value', $action) ? json_encode($action['value']) : '*';
                    $bucket = $actionType === 'require' ? 'requires' : 'excludes';
                    if ($bucket === 'requires') {
                        $requires[$target . '|' . $valueKey][] = $rule['code'];
                    } else {
                        $excludes[$target . '|' . $valueKey][] = $rule['code'];
                    }
                }
            }
        }

        // 同一目标被多条永真规则强制为不同结果
        foreach ($forced as $target => $entries) {
            $distinct = [];
            foreach ($entries as $entry) {
                // default 仅在目标缺省时生效，不参与强制冲突
                if ($entry['kind'] === 'default') {
                    continue;
                }
                $distinct[$entry['rule'] . '=' . $entry['value']] = $entry;
            }
            $values = [];
            foreach ($distinct as $entry) {
                $values[$entry['value'] === null ? 'formula' : $entry['value']][] = $entry['rule'];
            }
            if (count($values) > 1) {
                $parts = [];
                foreach ($values as $value => $ruleCodes) {
                    $parts[] = implode('、', $ruleCodes) . ' 强制为 ' . $value;
                }
                $conflicts[] = sprintf('目标 %s 被多条永真规则强制为不同结果（%s）', $target, implode('，', $parts));
            }
        }

        // require vs exclude / 强制赋值 vs exclude
        foreach ($requires as $key => $requireRules) {
            if (isset($excludes[$key])) {
                list($target, $valueKey) = explode('|', $key, 2);
                $conflicts[] = sprintf(
                    '规则 %s 要求 %s 取值 %s，但规则 %s 永真排除该取值',
                    implode('、', $requireRules), $target, $valueKey, implode('、', $excludes[$key])
                );
            }
        }
        foreach ($forced as $target => $entries) {
            foreach ($entries as $entry) {
                if ($entry['value'] === null) {
                    continue;
                }
                $key = $target . '|' . $entry['value'];
                if (isset($excludes[$key])) {
                    $conflicts[] = sprintf(
                        '规则 %s 永真将 %s 设置为 %s，但规则 %s 永真排除该取值',
                        $entry['rule'], $target, $entry['value'], implode('、', $excludes[$key])
                    );
                }
            }
        }

        return $conflicts;
    }

    // ------------------------------------------------------------------
    // 不可达选项
    // ------------------------------------------------------------------

    /**
     * 不可达选项检测：永真阻断规则（hide 组 / exclude 选项）使选项
     * 无论如何选择都无法通过校验。
     *
     * @param array $rules  规范化规则
     * @param array $groups 配置组（list 或 code=>group，含 options）
     * @return array 不可达描述列表
     */
    public static function findUnreachableOptions(array $rules, array $groups)
    {
        $groups = self::normalizeGroups($groups);
        if (!$groups) {
            return [];
        }

        $hiddenGroups = [];   // groupCode => [ruleCode,...]
        $excludedValues = []; // groupCode => ['*' or optionCode => [ruleCode,...]]
        foreach ($rules as $rule) {
            if ($rule['condition'] !== [] || strtolower((string)($rule['severity'] ?? 'blocking')) !== 'blocking') {
                continue;
            }
            foreach ($rule['actions'] as $action) {
                $actionType = strtolower((string)($action['action'] ?? ''));
                $target = trim((string)($action['target'] ?? ''));
                if ($target === '' || !isset($groups[$target])) {
                    continue;
                }
                if ($actionType === 'hide') {
                    $hiddenGroups[$target][] = $rule['code'];
                } elseif ($actionType === 'exclude') {
                    $valueKey = array_key_exists('value', $action) && $action['value'] !== null
                        ? (string)$action['value'] : '*';
                    $excludedValues[$target][$valueKey][] = $rule['code'];
                }
            }
        }

        $unreachable = [];
        foreach ($hiddenGroups as $groupCode => $ruleCodes) {
            $unreachable[] = sprintf(
                '配置组 %s 被规则 %s 永真隐藏，组内所有选项不可达',
                $groupCode, implode('、', array_unique($ruleCodes))
            );
        }
        foreach ($excludedValues as $groupCode => $values) {
            if (isset($values['*'])) {
                $unreachable[] = sprintf(
                    '配置组 %s 被规则 %s 永真排除任意取值，组内所有选项不可达',
                    $groupCode, implode('、', array_unique($values['*']))
                );
                continue;
            }
            foreach ($values as $optionCode => $ruleCodes) {
                if (isset($groups[$groupCode]['options'][$optionCode])) {
                    $unreachable[] = sprintf(
                        '选项 %s.%s 被规则 %s 永真排除，该选项不可达',
                        $groupCode, $optionCode, implode('、', array_unique($ruleCodes))
                    );
                }
            }
        }
        return $unreachable;
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    /**
     * 规范化规则：解码动作列表、统一字段。
     *
     * @param array $rules
     * @return array
     */
    private static function normalizeRules(array $rules)
    {
        $normalized = [];
        foreach ($rules as $index => $rule) {
            $actions = $rule['actions'] ?? [];
            if (isset($actions['action'])) {
                $actions = [$actions];
            }
            $normalized[] = [
                'code' => (string)($rule['code'] ?? ('#' . $index)),
                'condition' => is_array($rule['condition'] ?? null) ? $rule['condition'] : [],
                'actions' => is_array($actions) ? $actions : [],
                'severity' => (string)($rule['severity'] ?? 'blocking'),
            ];
        }
        return $normalized;
    }

    /**
     * 规范化配置组为 code=>group，options 归一为 code=>option。
     *
     * @param array $groups
     * @return array
     */
    private static function normalizeGroups(array $groups)
    {
        $normalized = [];
        foreach ($groups as $key => $group) {
            $code = trim((string)($group['code'] ?? (is_string($key) ? $key : '')));
            if ($code === '') {
                continue;
            }
            $options = [];
            foreach ($group['options'] ?? [] as $optionKey => $option) {
                $optionCode = trim((string)($option['code'] ?? (is_string($optionKey) ? $optionKey : '')));
                if ($optionCode !== '') {
                    $options[$optionCode] = $option;
                }
            }
            $group['options'] = $options;
            $normalized[$code] = $group;
        }
        return $normalized;
    }
}
