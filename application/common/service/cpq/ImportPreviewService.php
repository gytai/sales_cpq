<?php

namespace app\common\service\cpq;

use InvalidArgumentException;

/**
 * CPQ Excel 导入预览服务（GYTAI-69，方案 §5.3）。
 *
 * 纯函数契约：不写库，只对解析后的行做逐行校验与类型规范化，返回
 * 统一的预览结果（合法行 items + 逐字段错误 errors），供后台确认后再
 * 正式导入。行号为 1 起始的数据行号（不含表头）。
 *
 * 金额一律字符串 + bcmath，禁止 float 运算。
 */
class ImportPreviewService
{
    /** 支持的导入类型 */
    const TYPES = ['customer', 'price_entry', 'price_policy', 'exchange_rate', 'tax_rule', 'fee_rule'];

    /** 错误码：必填缺失 */
    const CODE_REQUIRED = 'CPQ_IMPORT_REQUIRED';
    /** 错误码：单字段格式错误 */
    const CODE_FIELD = 'CPQ_IMPORT_FIELD';
    /** 错误码：文件内编码重复 */
    const CODE_DUPLICATE = 'CPQ_IMPORT_DUPLICATE';
    /** 错误码：三层价格约束不满足 */
    const CODE_HIERARCHY = 'CPQ_PRICE_HIERARCHY';

    /** 价格条目/策略的定价对象类型 */
    const TARGET_TYPES = ['model', 'option', 'accessory_service'];

    /** 费用规则计算类型 */
    const CALCULATION_TYPES = ['fixed', 'per_quantity', 'percentage'];

    /**
     * 导入预览：逐行校验 + 规范化，不写库。
     *
     * @param string $type 导入类型（见 TYPES）
     * @param array  $rows 数据行（不含表头）
     * @return array ['type','total','valid_count','error_count','errors','items']
     * @throws InvalidArgumentException 不支持的导入类型
     */
    public function preview($type, array $rows)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('不支持的导入类型：' . (string)$type);
        }
        $errors = [];
        $items = [];
        $seenCodes = [];
        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 1;
            $row = is_array($row) ? $row : [];
            $before = count($errors);
            $item = $this->validateRow($type, $row, $rowNumber, $errors);

            // 客户导入：文件内编码重复检测（仅对本行无其他错误的行判定）
            if ($type === 'customer' && $item !== null) {
                $code = $item['code'];
                if (isset($seenCodes[$code])) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'field' => 'code',
                        'code' => self::CODE_DUPLICATE,
                        'message' => '客户编码在文件内重复：' . $code,
                    ];
                    $item = null;
                } else {
                    $seenCodes[$code] = true;
                }
            }
            if ($item !== null && count($errors) === $before) {
                $items[] = $item;
            }
        }
        return [
            'type' => $type,
            'total' => count($rows),
            'valid_count' => count($items),
            'error_count' => count($errors),
            'errors' => $errors,
            'items' => $items,
        ];
    }

    // ------------------------------------------------------------------
    // 内部实现：分类型行校验（返回规范化行，任何错误则返回 null）
    // ------------------------------------------------------------------

    /**
     * @param string $type
     * @param array  $row
     * @param int    $rowNumber
     * @param array  $errors 输出
     * @return array|null
     */
    private function validateRow($type, array $row, $rowNumber, array &$errors)
    {
        switch ($type) {
            case 'customer':
                return $this->validateCustomer($row, $rowNumber, $errors);
            case 'price_entry':
                return $this->validatePriceEntry($row, $rowNumber, $errors);
            case 'price_policy':
                return $this->validatePricePolicy($row, $rowNumber, $errors);
            case 'exchange_rate':
                return $this->validateExchangeRate($row, $rowNumber, $errors);
            case 'tax_rule':
                return $this->validateTaxRule($row, $rowNumber, $errors);
            case 'fee_rule':
                return $this->validateFeeRule($row, $rowNumber, $errors);
        }
        return null;
    }

    /**
     * 客户：code/name 必填；default_currency 若给须 3 位大写字母。
     */
    private function validateCustomer(array $row, $rowNumber, array &$errors)
    {
        $code = $this->required($row, 'code', $rowNumber, $errors);
        $name = $this->required($row, 'name', $rowNumber, $errors);

        $currency = trim((string)($row['default_currency'] ?? ''));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->addFieldError($errors, $rowNumber, 'default_currency', '默认币种必须是 3 位大写字母');
        }
        if ($this->rowHasError($errors, $rowNumber)) {
            return null;
        }
        $item = $row;
        $item['code'] = $code;
        $item['name'] = $name;
        $item['default_currency'] = $currency;
        return $item;
    }

    /**
     * 价格条目：price_book_code（或 price_book_id）、target_type、target_id、
     * amount 必填；amount 按金额规范校验。
     */
    private function validatePriceEntry(array $row, $rowNumber, array &$errors)
    {
        $bookCode = trim((string)($row['price_book_code'] ?? ''));
        $bookId = (int)($row['price_book_id'] ?? 0);
        if ($bookCode === '' && $bookId <= 0) {
            $this->addRequiredError($errors, $rowNumber, 'price_book_code');
        }

        $targetType = $this->required($row, 'target_type', $rowNumber, $errors);
        if ($targetType !== null && !in_array($targetType, self::TARGET_TYPES, true)) {
            $this->addFieldError($errors, $rowNumber, 'target_type', '定价对象类型必须是 model/option/accessory_service');
        }

        $targetIdRaw = $this->required($row, 'target_id', $rowNumber, $errors);
        $targetId = 0;
        if ($targetIdRaw !== null) {
            if (!is_numeric($targetIdRaw) || (int)$targetIdRaw <= 0) {
                $this->addFieldError($errors, $rowNumber, 'target_id', '定价对象ID必须是正整数');
            } else {
                $targetId = (int)$targetIdRaw;
            }
        }

        $amountRaw = $this->required($row, 'amount', $rowNumber, $errors);
        $amount = $amountRaw === null
            ? null
            : $this->normalizeAmountField($amountRaw, '价格', 'amount', $rowNumber, $errors);

        if ($this->rowHasError($errors, $rowNumber)) {
            return null;
        }
        $item = $row;
        $item['price_book_code'] = $bookCode;
        $item['price_book_id'] = $bookId;
        $item['target_type'] = $targetType;
        $item['target_id'] = $targetId;
        $item['amount'] = $amount;
        return $item;
    }

    /**
     * 价格策略：code/target_type/target_id/三层价格必填；三层价格约束校验。
     */
    private function validatePricePolicy(array $row, $rowNumber, array &$errors)
    {
        $code = $this->required($row, 'code', $rowNumber, $errors);
        $targetType = $this->required($row, 'target_type', $rowNumber, $errors);
        if ($targetType !== null && !in_array($targetType, self::TARGET_TYPES, true)) {
            $this->addFieldError($errors, $rowNumber, 'target_type', '定价对象类型必须是 model/option/accessory_service');
        }

        $targetIdRaw = $this->required($row, 'target_id', $rowNumber, $errors);
        $targetId = 0;
        if ($targetIdRaw !== null) {
            if (!is_numeric($targetIdRaw) || (int)$targetIdRaw <= 0) {
                $this->addFieldError($errors, $rowNumber, 'target_id', '定价对象ID必须是正整数');
            } else {
                $targetId = (int)$targetIdRaw;
            }
        }

        $amounts = [];
        foreach (['guide_price' => '指导价', 'line_floor' => '产线控制价', 'company_floor' => '公司控制价'] as $field => $label) {
            $raw = $this->required($row, $field, $rowNumber, $errors);
            $amounts[$field] = $raw === null
                ? null
                : $this->normalizeAmountField($raw, $label, $field, $rowNumber, $errors);
        }

        // 三层价格关系：任一价格非法时跳过（已各自报错）
        if ($amounts['guide_price'] !== null && $amounts['line_floor'] !== null && $amounts['company_floor'] !== null) {
            try {
                PricePolicyService::assertPriceHierarchy(
                    $amounts['guide_price'],
                    $amounts['line_floor'],
                    $amounts['company_floor']
                );
            } catch (InvalidArgumentException $exception) {
                $errors[] = [
                    'row' => $rowNumber,
                    'field' => 'guide_price',
                    'code' => self::CODE_HIERARCHY,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($this->rowHasError($errors, $rowNumber)) {
            return null;
        }
        $item = $row;
        $item['code'] = $code;
        $item['target_type'] = $targetType;
        $item['target_id'] = $targetId;
        $item['guide_price'] = $amounts['guide_price'];
        $item['line_floor'] = $amounts['line_floor'];
        $item['company_floor'] = $amounts['company_floor'];
        return $item;
    }

    /**
     * 汇率：源/目标币种、汇率、生效日期必填；rate>0；日期 Y-m-d。
     */
    private function validateExchangeRate(array $row, $rowNumber, array &$errors)
    {
        $source = $this->required($row, 'source_currency', $rowNumber, $errors);
        $target = $this->required($row, 'target_currency', $rowNumber, $errors);

        $rateRaw = $this->required($row, 'rate', $rowNumber, $errors);
        $rate = null;
        if ($rateRaw !== null) {
            if (!is_numeric($rateRaw)) {
                $this->addFieldError($errors, $rowNumber, 'rate', '汇率必须是数值');
            } elseif (bccomp(trim((string)$rateRaw), '0', 8) <= 0) {
                $this->addFieldError($errors, $rowNumber, 'rate', '汇率必须大于0');
            } else {
                $rate = bcadd(trim((string)$rateRaw), '0', 8);
            }
        }

        $effective = $this->required($row, 'effective_date', $rowNumber, $errors);
        if ($effective !== null && !self::isDate($effective)) {
            $this->addFieldError($errors, $rowNumber, 'effective_date', '生效日期必须是 Y-m-d 格式');
        }

        if ($this->rowHasError($errors, $rowNumber)) {
            return null;
        }
        $item = $row;
        $item['source_currency'] = strtoupper($source);
        $item['target_currency'] = strtoupper($target);
        $item['rate'] = $rate;
        $item['effective_date'] = $effective;
        return $item;
    }

    /**
     * 税率规则：code/rate/effective_date 必填；0<=rate<=1（scale 6）。
     */
    private function validateTaxRule(array $row, $rowNumber, array &$errors)
    {
        $code = $this->required($row, 'code', $rowNumber, $errors);

        $rateRaw = $this->required($row, 'rate', $rowNumber, $errors);
        $rate = null;
        if ($rateRaw !== null) {
            if (!is_numeric($rateRaw)) {
                $this->addFieldError($errors, $rowNumber, 'rate', '税率必须是数值');
            } elseif (bccomp(trim((string)$rateRaw), '0', 6) < 0 || bccomp(trim((string)$rateRaw), '1', 6) > 0) {
                $this->addFieldError($errors, $rowNumber, 'rate', '税率必须在 0 到 1 之间');
            } else {
                $rate = bcadd(trim((string)$rateRaw), '0', 6);
            }
        }

        $effective = $this->required($row, 'effective_date', $rowNumber, $errors);
        if ($effective !== null && !self::isDate($effective)) {
            $this->addFieldError($errors, $rowNumber, 'effective_date', '生效日期必须是 Y-m-d 格式');
        }

        if ($this->rowHasError($errors, $rowNumber)) {
            return null;
        }
        $item = $row;
        $item['code'] = $code;
        $item['rate'] = $rate;
        $item['effective_date'] = $effective;
        return $item;
    }

    /**
     * 费用规则：code/name/fee_type/calculation_type/value 必填；
     * calculation_type 枚举校验；value>=0。
     */
    private function validateFeeRule(array $row, $rowNumber, array &$errors)
    {
        $code = $this->required($row, 'code', $rowNumber, $errors);
        $name = $this->required($row, 'name', $rowNumber, $errors);
        $feeType = $this->required($row, 'fee_type', $rowNumber, $errors);

        $calculationType = $this->required($row, 'calculation_type', $rowNumber, $errors);
        if ($calculationType !== null && !in_array($calculationType, self::CALCULATION_TYPES, true)) {
            $this->addFieldError($errors, $rowNumber, 'calculation_type', '计算类型必须是 fixed/per_quantity/percentage');
        }

        $valueRaw = $this->required($row, 'value', $rowNumber, $errors);
        $value = null;
        if ($valueRaw !== null) {
            if (!is_numeric($valueRaw)) {
                $this->addFieldError($errors, $rowNumber, 'value', '计算值必须是数值');
            } elseif (bccomp(trim((string)$valueRaw), '0', 8) < 0) {
                $this->addFieldError($errors, $rowNumber, 'value', '计算值不能小于0');
            } else {
                $value = bcadd(trim((string)$valueRaw), '0', 8);
            }
        }

        if ($this->rowHasError($errors, $rowNumber)) {
            return null;
        }
        $item = $row;
        $item['code'] = $code;
        $item['name'] = $name;
        $item['fee_type'] = $feeType;
        $item['calculation_type'] = $calculationType;
        $item['value'] = $value;
        return $item;
    }

    // ------------------------------------------------------------------
    // 内部实现：通用工具
    // ------------------------------------------------------------------

    /**
     * 必填校验：缺失/空白时记录 CPQ_IMPORT_REQUIRED 并返回 null。
     *
     * @return string|null trim 后的字符串
     */
    private function required(array $row, $field, $rowNumber, array &$errors)
    {
        $value = $row[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '') || $value === '') {
            $this->addRequiredError($errors, $rowNumber, $field);
            return null;
        }
        return is_string($value) ? trim($value) : (string)$value;
    }

    private function addRequiredError(array &$errors, $rowNumber, $field)
    {
        $errors[] = [
            'row' => $rowNumber,
            'field' => $field,
            'code' => self::CODE_REQUIRED,
            'message' => $field . ' 为必填项',
        ];
    }

    private function addFieldError(array &$errors, $rowNumber, $field, $message)
    {
        $errors[] = [
            'row' => $rowNumber,
            'field' => $field,
            'code' => self::CODE_FIELD,
            'message' => $message,
        ];
    }

    /**
     * 金额字段校验（复用 PricePolicyService 的规范化逻辑）。
     *
     * @return string|null 规范化金额字符串
     */
    private function normalizeAmountField($value, $label, $field, $rowNumber, array &$errors)
    {
        try {
            return PricePolicyService::normalizeAmount($value, $label);
        } catch (InvalidArgumentException $exception) {
            $this->addFieldError($errors, $rowNumber, $field, $exception->getMessage());
            return null;
        }
    }

    /**
     * @param array $errors
     * @param int   $rowNumber
     * @return bool 当前行是否已产生错误
     */
    private function rowHasError(array $errors, $rowNumber)
    {
        foreach ($errors as $error) {
            if ($error['row'] === $rowNumber) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $value
     * @return bool 是否合法 Y-m-d 日期
     */
    private static function isDate($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        list($year, $month, $day) = explode('-', $value);
        return checkdate((int)$month, (int)$day, (int)$year);
    }
}
