<?php

namespace app\common\library\cpq;

use InvalidArgumentException;

/**
 * CPQ 配置规则受控 DSL 的结构校验。
 *
 * 规则只允许保存白名单内的受控 JSON，发布前由本类做最终校验；
 * 输入校验（后台表单）与发布校验（生命周期服务）共用同一份白名单，
 * 避免两处维护漂移。运行期解释由 ConfigurationService 负责。
 */
class RuleDsl
{
    /** 允许的动作类型白名单（与 ConfigurationService 解释器一致） */
    const ALLOWED_ACTIONS = [
        'require', 'exclude', 'one_of', 'min_max', 'hide', 'show',
        'default', 'set', 'formula', 'warning',
    ];

    /** 允许的条件比较符白名单（与 ConfigurationService::matches 一致） */
    const ALLOWED_OPERATORS = [
        '=', '!=', 'eq', 'neq', '>', '>=', '<', '<=',
        'in', 'not_in', 'contains', 'selected', 'empty', 'not_empty',
    ];

    /** 允许的受控公式操作白名单（与 ConfigurationService::calculateFormula 一致） */
    const ALLOWED_FORMULA_OPERATIONS = ['sum', 'subtract', 'multiply', 'divide'];

    /** 条件/动作中字段与目标的合法字符（禁止注入式内容） */
    const FIELD_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.]{0,127}$/';

    /**
     * 校验条件 JSON：必须是合法 JSON 对象（空对象表示恒真），
     * 且递归校验比较符白名单与字段格式，拒绝任何脚本式内容。
     *
     * @param string $json
     * @return array 解码后的数组
     * @throws InvalidArgumentException
     */
    public static function assertConditionJson($json)
    {
        $decoded = json_decode((string)$json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new InvalidArgumentException('条件必须是合法 JSON 对象');
        }
        self::assertConditionNode($decoded);
        return $decoded;
    }

    /**
     * 递归校验条件节点：all/any/not 组合或 field+operator 叶子。
     *
     * @param array $condition
     * @throws InvalidArgumentException
     */
    private static function assertConditionNode(array $condition)
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
                self::assertConditionNode($child);
            }
            return;
        }
        if (isset($condition['not'])) {
            if (!is_array($condition['not'])) {
                throw new InvalidArgumentException('条件 not 必须是对象');
            }
            self::assertConditionNode($condition['not']);
            return;
        }
        $field = (string)($condition['field'] ?? '');
        if ($field === '' || !preg_match(self::FIELD_PATTERN, $field)) {
            throw new InvalidArgumentException('条件字段缺失或包含非法字符');
        }
        $operator = strtolower((string)($condition['operator'] ?? '='));
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException('条件包含不受支持的比较符：' . $operator);
        }
    }

    /**
     * 校验动作 JSON：必须是合法 JSON 数组，每个动作都在白名单内，
     * 且按动作类型校验必填结构（目标、取值范围、公式操作等）。
     *
     * @param string $json
     * @return array 规范化后的动作列表
     * @throws InvalidArgumentException
     */
    public static function assertActionJson($json)
    {
        $actions = json_decode((string)$json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($actions)) {
            throw new InvalidArgumentException('动作必须是合法 JSON 数组');
        }
        // 允许保存单个动作对象（自动规范化为数组）
        if (isset($actions['action'])) {
            $actions = [$actions];
        }
        foreach ($actions as $action) {
            if (!is_array($action)) {
                throw new InvalidArgumentException('动作必须是 JSON 对象');
            }
            $actionType = strtolower((string)($action['action'] ?? ''));
            if (!in_array($actionType, self::ALLOWED_ACTIONS, true)) {
                throw new InvalidArgumentException('动作包含不受支持的 action');
            }
            self::assertActionStructure($actionType, $action);
        }
        return $actions;
    }

    /**
     * 按动作类型做结构校验（发布期拦截解释器运行期才会发现的错误）。
     *
     * @param string $actionType
     * @param array  $action
     * @throws InvalidArgumentException
     */
    private static function assertActionStructure($actionType, array $action)
    {
        $target = trim((string)($action['target'] ?? ''));
        if ($target === '' || !preg_match(self::FIELD_PATTERN, $target)) {
            throw new InvalidArgumentException('动作 ' . $actionType . ' 缺少合法的目标配置项');
        }

        switch ($actionType) {
            case 'min_max':
                $min = $action['min'] ?? null;
                $max = $action['max'] ?? null;
                if ($min === null && $max === null) {
                    throw new InvalidArgumentException('动作 min_max 至少需要 min 或 max');
                }
                if (($min !== null && !is_numeric($min)) || ($max !== null && !is_numeric($max))) {
                    throw new InvalidArgumentException('动作 min_max 的 min/max 必须是数值');
                }
                if ($min !== null && $max !== null && bccomp((string)$min, (string)$max, 8) > 0) {
                    throw new InvalidArgumentException('动作 min_max 的 min 不能大于 max');
                }
                break;
            case 'one_of':
                if (!isset($action['value']) || !is_array($action['value']) || $action['value'] === []) {
                    throw new InvalidArgumentException('动作 one_of 的 value 必须是非空数组');
                }
                break;
            case 'formula':
                $operation = strtolower((string)($action['operation'] ?? 'sum'));
                if (!in_array($operation, self::ALLOWED_FORMULA_OPERATIONS, true)) {
                    throw new InvalidArgumentException('动作 formula 包含不受支持的公式操作：' . $operation);
                }
                $operands = $action['operands'] ?? null;
                if (!is_array($operands) || $operands === []) {
                    throw new InvalidArgumentException('动作 formula 的 operands 必须是非空数组');
                }
                foreach ($operands as $operand) {
                    if (is_array($operand)) {
                        if (isset($operand['field'])) {
                            if (!preg_match(self::FIELD_PATTERN, (string)$operand['field'])) {
                                throw new InvalidArgumentException('动作 formula 的操作数字段包含非法字符');
                            }
                        } elseif (!isset($operand['value']) || !is_numeric($operand['value'])) {
                            throw new InvalidArgumentException('动作 formula 的操作数必须是字段引用或数值');
                        }
                    } elseif (!is_numeric($operand)) {
                        throw new InvalidArgumentException('动作 formula 的操作数必须是字段引用或数值');
                    }
                }
                break;
            case 'default':
            case 'set':
                if (!array_key_exists('value', $action)) {
                    throw new InvalidArgumentException('动作 ' . $actionType . ' 缺少 value');
                }
                break;
        }
    }

    /**
     * 校验规则适用范围：适用型号与适用产品线至少填写一项。
     *
     * @param int|null $modelId
     * @param string $productLine
     * @throws InvalidArgumentException
     */
    public static function assertScope($modelId, $productLine)
    {
        if ((int)$modelId <= 0 && trim((string)$productLine) === '') {
            throw new InvalidArgumentException('适用型号和适用产品线至少填写一项');
        }
    }
}
