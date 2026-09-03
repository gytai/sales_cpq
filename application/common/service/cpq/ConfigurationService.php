<?php

namespace app\common\service\cpq;

use InvalidArgumentException;

class ConfigurationService
{
    public function validate(array $schema, array $input, array $context = [])
    {
        $groups = $this->indexGroups($schema['groups'] ?? []);
        $configuration = $this->normalizeConfiguration($groups, $input);
        $errors = [];
        $warnings = [];
        $hiddenGroups = [];
        $appliedRules = [];

        $this->applyDefaults($groups, $configuration);

        $rules = $schema['rules'] ?? [];
        usort($rules, function ($leftRule, $rightRule) {
            return (int)($rightRule['priority'] ?? 0) <=> (int)($leftRule['priority'] ?? 0);
        });

        foreach ($rules as $rule) {
            if (!$this->matches($rule['condition'] ?? [], $schema, $configuration, $context)) {
                continue;
            }

            $appliedRules[] = (string)($rule['code'] ?? '');
            $actions = $rule['actions'] ?? [];
            if (isset($actions['action'])) {
                $actions = [$actions];
            }

            foreach ($actions as $action) {
                $this->applyAction(
                    $action,
                    $rule,
                    $groups,
                    $configuration,
                    $errors,
                    $warnings,
                    $hiddenGroups,
                    $schema,
                    $context
                );
            }
        }

        $this->validateGroups($groups, $configuration, $hiddenGroups, $errors);
        $configuration = $this->sortRecursively($configuration);
        $bom = $this->buildBom($schema, $groups, $configuration);
        $hashPayload = [
            'model' => $schema['model']['code'] ?? null,
            'version' => $schema['model']['version'] ?? null,
            'configuration' => $configuration,
        ];

        return [
            'is_valid' => count($errors) === 0,
            'configuration' => $configuration,
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
            'hidden_groups' => array_values(array_unique($hiddenGroups)),
            'applied_rules' => array_values(array_filter(array_unique($appliedRules))),
            'bom' => $bom,
            'configuration_hash' => hash('sha256', $this->encodeJson($this->sortRecursively($hashPayload))),
        ];
    }

    private function indexGroups(array $groups)
    {
        $indexedGroups = [];
        foreach ($groups as $group) {
            $code = trim((string)($group['code'] ?? ''));
            if ($code === '') {
                throw new InvalidArgumentException('配置组编码不能为空');
            }
            if (isset($indexedGroups[$code])) {
                throw new InvalidArgumentException('配置组编码重复：' . $code);
            }

            $options = [];
            foreach ($group['options'] ?? [] as $option) {
                $optionCode = trim((string)($option['code'] ?? ''));
                if ($optionCode === '') {
                    throw new InvalidArgumentException('配置选项编码不能为空');
                }
                $options[$optionCode] = $option;
            }
            $group['options'] = $options;
            $indexedGroups[$code] = $group;
        }
        return $indexedGroups;
    }

    private function normalizeConfiguration(array $groups, array $input)
    {
        $configuration = [];
        foreach ($groups as $code => $group) {
            if (!array_key_exists($code, $input)) {
                continue;
            }
            $value = $input[$code];
            $inputType = $group['input_type'] ?? 'single';
            if ($inputType === 'multiple') {
                $values = is_array($value) ? $value : [$value];
                $values = array_map('strval', $values);
                $configuration[$code] = array_values(array_unique(array_filter($values, function ($selectedValue) {
                    return $selectedValue !== '';
                })));
            } elseif ($inputType === 'number') {
                $configuration[$code] = is_numeric($value) ? $this->normalizeNumber($value) : $value;
            } elseif ($inputType === 'text') {
                $configuration[$code] = trim((string)$value);
            } elseif ($inputType === 'readonly') {
                continue;
            } else {
                $configuration[$code] = is_scalar($value) ? (string)$value : '';
            }
        }
        return $configuration;
    }

    private function applyDefaults(array $groups, array &$configuration)
    {
        foreach ($groups as $code => $group) {
            if (array_key_exists($code, $configuration)) {
                continue;
            }
            if (!array_key_exists('default_value', $group) || $group['default_value'] === null) {
                continue;
            }
            $defaultValue = $group['default_value'];
            if (($group['input_type'] ?? '') === 'multiple' && !is_array($defaultValue)) {
                $defaultValue = [$defaultValue];
            }
            $configuration[$code] = $defaultValue;
        }
    }

    private function validateGroups(array $groups, array $configuration, array $hiddenGroups, array &$errors)
    {
        foreach ($groups as $code => $group) {
            if (in_array($code, $hiddenGroups, true)) {
                continue;
            }

            $value = $configuration[$code] ?? null;
            $inputType = $group['input_type'] ?? 'single';
            $isRequired = !empty($group['is_required']);

            if ($isRequired && $this->isEmptyValue($value)) {
                $this->addIssue($errors, 'CPQ_CONFIG_REQUIRED', $code, $group['name'] . '为必填配置');
                continue;
            }

            if ($this->isEmptyValue($value)) {
                continue;
            }

            if ($inputType === 'single') {
                if (!isset($group['options'][(string)$value])) {
                    $this->addIssue($errors, 'CPQ_CONFIG_OPTION_INVALID', $code, $group['name'] . '包含无效选项');
                }
                continue;
            }

            if ($inputType === 'multiple') {
                if (!is_array($value)) {
                    $this->addIssue($errors, 'CPQ_CONFIG_TYPE_INVALID', $code, $group['name'] . '必须是多选值');
                    continue;
                }
                foreach ($value as $selectedOption) {
                    if (!isset($group['options'][(string)$selectedOption])) {
                        $this->addIssue($errors, 'CPQ_CONFIG_OPTION_INVALID', $code, $group['name'] . '包含无效选项');
                    }
                }
                $selectedCount = count($value);
                $minimum = (int)($group['min_select'] ?? 0);
                $maximum = (int)($group['max_select'] ?? 0);
                if ($selectedCount < $minimum) {
                    $this->addIssue($errors, 'CPQ_CONFIG_MIN_SELECT', $code, $group['name'] . '至少选择' . $minimum . '项');
                }
                if ($maximum > 0 && $selectedCount > $maximum) {
                    $this->addIssue($errors, 'CPQ_CONFIG_MAX_SELECT', $code, $group['name'] . '最多选择' . $maximum . '项');
                }
                continue;
            }

            if ($inputType === 'number' && !is_numeric($value)) {
                $this->addIssue($errors, 'CPQ_CONFIG_NUMBER_INVALID', $code, $group['name'] . '必须是数值');
            }
        }
    }

    private function matches(array $condition, array $schema, array $configuration, array $context)
    {
        if ($condition === []) {
            return true;
        }
        if (isset($condition['all'])) {
            foreach ((array)$condition['all'] as $childCondition) {
                if (!$this->matches((array)$childCondition, $schema, $configuration, $context)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($condition['any'])) {
            foreach ((array)$condition['any'] as $childCondition) {
                if ($this->matches((array)$childCondition, $schema, $configuration, $context)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($condition['not'])) {
            return !$this->matches((array)$condition['not'], $schema, $configuration, $context);
        }

        $field = (string)($condition['field'] ?? '');
        $operator = strtolower((string)($condition['operator'] ?? '='));
        $expected = $condition['value'] ?? null;
        $values = [
            'model' => $schema['model'] ?? [],
            'configuration' => $configuration,
            'context' => $context,
        ];
        $actual = $this->readPath($values, $field);

        switch ($operator) {
            case '=':
            case 'eq':
                return $actual == $expected;
            case '!=':
            case 'neq':
                return $actual != $expected;
            case '>':
                return is_numeric($actual) && is_numeric($expected) && $actual > $expected;
            case '>=':
                return is_numeric($actual) && is_numeric($expected) && $actual >= $expected;
            case '<':
                return is_numeric($actual) && is_numeric($expected) && $actual < $expected;
            case '<=':
                return is_numeric($actual) && is_numeric($expected) && $actual <= $expected;
            case 'in':
                return in_array($actual, (array)$expected, true);
            case 'not_in':
                return !in_array($actual, (array)$expected, true);
            case 'contains':
            case 'selected':
                return is_array($actual) && in_array($expected, $actual, true);
            case 'empty':
                return $this->isEmptyValue($actual);
            case 'not_empty':
                return !$this->isEmptyValue($actual);
            default:
                throw new InvalidArgumentException('不支持的规则比较符：' . $operator);
        }
    }

    private function applyAction(
        array $action,
        array $rule,
        array $groups,
        array &$configuration,
        array &$errors,
        array &$warnings,
        array &$hiddenGroups,
        array $schema,
        array $context
    ) {
        $actionType = strtolower((string)($action['action'] ?? ''));
        $target = (string)($action['target'] ?? '');
        $message = (string)($action['message'] ?? ($rule['message'] ?? '配置规则校验失败'));
        $ruleCode = (string)($rule['code'] ?? '');
        $severity = strtolower((string)($rule['severity'] ?? 'blocking'));

        if ($actionType === 'default' || $actionType === 'set') {
            if ($target !== '' && ($actionType === 'set' || !array_key_exists($target, $configuration))) {
                $configuration[$target] = $action['value'] ?? null;
            }
            return;
        }
        if ($actionType === 'hide') {
            if ($target !== '') {
                $hiddenGroups[] = $target;
                unset($configuration[$target]);
            }
            return;
        }
        if ($actionType === 'show') {
            $hiddenGroups = array_values(array_diff($hiddenGroups, [$target]));
            return;
        }
        if ($actionType === 'formula') {
            if ($target !== '') {
                $configuration[$target] = $this->calculateFormula($action, $schema, $configuration, $context);
            }
            return;
        }
        if ($actionType === 'warning') {
            $this->addIssue($warnings, 'CPQ_CONFIG_WARNING', $target, $message, $ruleCode);
            return;
        }

        $selected = $this->isTargetSelected($target, $action['value'] ?? null, $configuration);
        if ($actionType === 'require' && !$selected) {
            $collection = $severity === 'warning' ? $warnings : $errors;
            $this->addIssue($collection, 'CPQ_CONFIG_REQUIRES', $target, $message, $ruleCode);
            if ($severity === 'warning') {
                $warnings = $collection;
            } else {
                $errors = $collection;
            }
            return;
        }
        if ($actionType === 'require') {
            return;
        }
        if ($actionType === 'exclude' && $selected) {
            $collection = $severity === 'warning' ? $warnings : $errors;
            $this->addIssue($collection, 'CPQ_CONFIG_EXCLUDES', $target, $message, $ruleCode);
            if ($severity === 'warning') {
                $warnings = $collection;
            } else {
                $errors = $collection;
            }
            return;
        }
        if ($actionType === 'exclude') {
            return;
        }
        if ($actionType === 'one_of') {
            $allowedValues = (array)($action['value'] ?? []);
            $actualValues = (array)($configuration[$target] ?? []);
            if (count(array_intersect($allowedValues, $actualValues)) === 0) {
                $this->addIssue($errors, 'CPQ_CONFIG_ONE_OF', $target, $message, $ruleCode);
            }
            return;
        }
        if ($actionType === 'min_max') {
            $actualValue = $configuration[$target] ?? null;
            $minimum = $action['min'] ?? null;
            $maximum = $action['max'] ?? null;
            if (!is_numeric($actualValue)
                || ($minimum !== null && $actualValue < $minimum)
                || ($maximum !== null && $actualValue > $maximum)
            ) {
                $this->addIssue($errors, 'CPQ_CONFIG_MIN_MAX', $target, $message, $ruleCode);
            }
            return;
        }
        if ($actionType !== '') {
            throw new InvalidArgumentException('不支持的规则动作：' . $actionType);
        }
    }

    private function calculateFormula(array $action, array $schema, array $configuration, array $context)
    {
        $operation = strtolower((string)($action['operation'] ?? 'sum'));
        $values = [];
        $source = [
            'model' => $schema['model'] ?? [],
            'configuration' => $configuration,
            'context' => $context,
        ];
        foreach ((array)($action['operands'] ?? []) as $operand) {
            $value = is_array($operand) && isset($operand['field'])
                ? $this->readPath($source, (string)$operand['field'])
                : (is_array($operand) ? ($operand['value'] ?? 0) : $operand);
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('公式操作数必须是数值');
            }
            $values[] = (string)$value;
        }
        if ($values === []) {
            return '0';
        }

        $scale = max(0, min(8, (int)($action['scale'] ?? 4)));
        $calculated = array_shift($values);
        foreach ($values as $value) {
            if ($operation === 'sum') {
                $calculated = bcadd($calculated, $value, $scale);
            } elseif ($operation === 'subtract') {
                $calculated = bcsub($calculated, $value, $scale);
            } elseif ($operation === 'multiply') {
                $calculated = bcmul($calculated, $value, $scale);
            } elseif ($operation === 'divide') {
                if (bccomp($value, '0', $scale) === 0) {
                    throw new InvalidArgumentException('公式除数不能为零');
                }
                $calculated = bcdiv($calculated, $value, $scale);
            } else {
                throw new InvalidArgumentException('不支持的公式操作：' . $operation);
            }
        }
        return $this->normalizeNumber($calculated);
    }

    private function buildBom(array $schema, array $groups, array $configuration)
    {
        if (!empty($schema['bom_mappings'])) {
            return $this->buildMappedBom($schema, $groups, $configuration);
        }

        $bomLines = [];
        $baseItemCode = trim((string)($schema['model']['base_item_code'] ?? ''));
        if ($baseItemCode !== '') {
            $bomLines[$baseItemCode] = [
                'material_code' => $baseItemCode,
                'quantity' => '1',
                'unit' => (string)($schema['model']['unit'] ?? 'item'),
                'source' => 'model',
            ];
        }

        foreach ($configuration as $groupCode => $selectedValue) {
            if (!isset($groups[$groupCode])) {
                continue;
            }
            $selectedOptions = is_array($selectedValue) ? $selectedValue : [$selectedValue];
            foreach ($selectedOptions as $optionCode) {
                $option = $groups[$groupCode]['options'][(string)$optionCode] ?? null;
                if (!$option) {
                    continue;
                }
                $materialCode = trim((string)($option['material_code'] ?? ''));
                if ($materialCode === '') {
                    continue;
                }
                $quantity = $this->normalizeNumber($option['default_qty'] ?? 1);
                if (isset($bomLines[$materialCode])) {
                    $bomLines[$materialCode]['quantity'] = $this->normalizeNumber(
                        bcadd($bomLines[$materialCode]['quantity'], $quantity, 4)
                    );
                } else {
                    $bomLines[$materialCode] = [
                        'material_code' => $materialCode,
                        'quantity' => $quantity,
                        'unit' => (string)($option['unit'] ?? 'item'),
                        'source' => 'option:' . $groupCode . '.' . $optionCode,
                    ];
                }
            }
        }
        ksort($bomLines);
        return array_values($bomLines);
    }

    private function buildMappedBom(array $schema, array $groups, array $configuration)
    {
        $selectedOptionIds = [];
        foreach ($configuration as $groupCode => $selectedValue) {
            if (!isset($groups[$groupCode])) {
                continue;
            }
            foreach (is_array($selectedValue) ? $selectedValue : [$selectedValue] as $optionCode) {
                $option = $groups[$groupCode]['options'][(string)$optionCode] ?? null;
                if ($option && !empty($option['id'])) {
                    $selectedOptionIds[(int)$option['id']] = true;
                }
            }
        }

        $source = [
            'model' => $schema['model'] ?? [],
            'configuration' => $configuration,
        ];
        $bomLines = [];
        foreach ($schema['bom_mappings'] as $mapping) {
            $optionValueId = $mapping['option_value_id'] ?? null;
            if ($optionValueId !== null && !isset($selectedOptionIds[(int)$optionValueId])) {
                continue;
            }

            $quantity = $this->evaluateBomQuantity((string)($mapping['qty_formula'] ?? '1'), $source);
            $lossRate = $this->normalizeNumber($mapping['loss_rate'] ?? '0');
            if (bccomp($lossRate, '0', 8) > 0) {
                $quantity = bcmul($quantity, bcadd('1', $lossRate, 8), 8);
            }
            $quantity = $this->normalizeNumber($quantity);
            $materialCode = trim((string)($mapping['material_code'] ?? ''));
            if ($materialCode === '' || bccomp($quantity, '0', 8) <= 0) {
                continue;
            }

            $lineKey = $materialCode . '|' . ($mapping['unit'] ?? 'item');
            if (isset($bomLines[$lineKey])) {
                $bomLines[$lineKey]['quantity'] = $this->normalizeNumber(
                    bcadd($bomLines[$lineKey]['quantity'], $quantity, 8)
                );
                continue;
            }
            $bomLines[$lineKey] = [
                'material_code' => $materialCode,
                'quantity' => $quantity,
                'unit' => (string)($mapping['unit'] ?? 'item'),
                'substitute_material_code' => (string)($mapping['substitute_material_code'] ?? ''),
                'source' => 'mapping:' . ($mapping['id'] ?? ''),
            ];
        }
        ksort($bomLines);
        return array_values($bomLines);
    }

    private function evaluateBomQuantity($formula, array $source)
    {
        $expression = preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/', function ($matches) use ($source) {
            $value = $this->readPath($source, $matches[1]);
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('BOM 数量公式引用的值不是数值：' . $matches[1]);
            }
            return $this->normalizeNumber($value);
        }, trim($formula));
        if ($expression === null || $expression === '') {
            throw new InvalidArgumentException('BOM 数量公式不能为空');
        }
        if (!preg_match_all('/\G\s*(\d+(?:\.\d+)?|[()+\-*\/])\s*/A', $expression, $matches)
            || implode('', $matches[1]) !== preg_replace('/\s+/', '', $expression)
        ) {
            throw new InvalidArgumentException('BOM 数量公式格式不合法');
        }

        $tokens = $matches[1];
        $position = 0;
        $quantity = $this->parseBomExpression($tokens, $position);
        if ($position !== count($tokens)) {
            throw new InvalidArgumentException('BOM 数量公式存在多余内容');
        }
        return $this->normalizeNumber($quantity);
    }

    private function parseBomExpression(array $tokens, &$position)
    {
        $value = $this->parseBomTerm($tokens, $position);
        while (isset($tokens[$position]) && in_array($tokens[$position], ['+', '-'], true)) {
            $operator = $tokens[$position++];
            $right = $this->parseBomTerm($tokens, $position);
            $value = $operator === '+' ? bcadd($value, $right, 8) : bcsub($value, $right, 8);
        }
        return $value;
    }

    private function parseBomTerm(array $tokens, &$position)
    {
        $value = $this->parseBomFactor($tokens, $position);
        while (isset($tokens[$position]) && in_array($tokens[$position], ['*', '/'], true)) {
            $operator = $tokens[$position++];
            $right = $this->parseBomFactor($tokens, $position);
            if ($operator === '/' && bccomp($right, '0', 8) === 0) {
                throw new InvalidArgumentException('BOM 数量公式除数不能为零');
            }
            $value = $operator === '*' ? bcmul($value, $right, 8) : bcdiv($value, $right, 8);
        }
        return $value;
    }

    private function parseBomFactor(array $tokens, &$position)
    {
        if (!isset($tokens[$position])) {
            throw new InvalidArgumentException('BOM 数量公式不完整');
        }
        if (in_array($tokens[$position], ['+', '-'], true)) {
            $operator = $tokens[$position++];
            $value = $this->parseBomFactor($tokens, $position);
            return $operator === '-' ? bcsub('0', $value, 8) : $value;
        }
        if ($tokens[$position] === '(') {
            $position++;
            $value = $this->parseBomExpression($tokens, $position);
            if (!isset($tokens[$position]) || $tokens[$position] !== ')') {
                throw new InvalidArgumentException('BOM 数量公式括号不匹配');
            }
            $position++;
            return $value;
        }
        $value = $tokens[$position++];
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('BOM 数量公式缺少数值');
        }
        return $value;
    }

    private function isTargetSelected($target, $expected, array $configuration)
    {
        if ($target === '' || !array_key_exists($target, $configuration)) {
            return false;
        }
        $actual = $configuration[$target];
        if ($expected === null) {
            return !$this->isEmptyValue($actual);
        }
        if (is_array($actual)) {
            return in_array($expected, $actual, true);
        }
        return $actual == $expected;
    }

    private function readPath(array $values, $path)
    {
        if ($path === '') {
            return null;
        }
        $current = $values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    private function addIssue(array &$issues, $code, $path, $message, $ruleCode = '')
    {
        $key = $code . '|' . $path . '|' . $ruleCode;
        $issues[$key] = [
            'code' => $code,
            'path' => $path,
            'message' => $message,
            'rule_code' => $ruleCode,
        ];
    }

    private function isEmptyValue($value)
    {
        return $value === null || $value === '' || $value === [];
    }

    private function normalizeNumber($value)
    {
        $number = strtolower(trim((string)$value));
        $isNegative = substr($number, 0, 1) === '-';
        $number = ltrim($number, '+-');
        $parts = explode('e', $number, 2);
        $mantissa = $parts[0];
        $exponent = isset($parts[1]) ? (int)$parts[1] : 0;
        $decimalParts = explode('.', $mantissa, 2);
        $integer = $decimalParts[0] === '' ? '0' : $decimalParts[0];
        $fraction = $decimalParts[1] ?? '';
        $digits = ltrim($integer . $fraction, '0');

        if ($digits === '') {
            return '0';
        }

        $decimalPosition = strlen($integer) + $exponent;
        $leadingZeroCount = strlen($integer . $fraction) - strlen(ltrim($integer . $fraction, '0'));
        $decimalPosition -= $leadingZeroCount;
        if ($decimalPosition <= 0) {
            $normalized = '0.' . str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $normalized = $digits . str_repeat('0', $decimalPosition - strlen($digits));
        } else {
            $normalized = substr($digits, 0, $decimalPosition) . '.' . substr($digits, $decimalPosition);
        }

        if (strpos($normalized, '.') !== false) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }
        if ($normalized === '') {
            return '0';
        }
        return $isNegative ? '-' . $normalized : $normalized;
    }

    private function sortRecursively($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $childValue) {
            $value[$key] = $this->sortRecursively($childValue);
        }
        if ($this->isAssociativeArray($value)) {
            ksort($value);
        } else {
            sort($value);
        }
        return $value;
    }

    private function isAssociativeArray(array $value)
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function encodeJson($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new InvalidArgumentException('配置数据无法序列化');
        }
        return $json;
    }
}
