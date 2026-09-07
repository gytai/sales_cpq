<?php

namespace app\common\library\cpq;

use InvalidArgumentException;

/**
 * CPQ Money 值对象（GYTAI-68，方案 §5 / P32）。
 *
 * 金额一律不可变字符串 + bcmath，禁止 float：
 *  - 金额（Money）统一 scale 4（DECIMAL(18,4) 存储精度）；
 *  - 比率/汇率/税率中间运算统一 scale 8（DECIMAL(18,8)/(9,6) 存储精度）；
 *  - 舍入策略固定为四舍五入（正数 HALF_UP，负数对称 HALF_AWAY_FROM_ZERO），
 *    只在"比率乘法"与"最终金额落库/输出"时发生一次，中间加减不放大误差。
 *
 * 每次运算返回新实例，绝不修改自身（不可变值对象）。
 */
final class Money
{
    /** 金额存储精度（与 DECIMAL(18,4) 一致） */
    const SCALE = 4;

    /** 比率/汇率运算精度（与 DECIMAL(18,8) 一致） */
    const RATIO_SCALE = 8;

    /** @var string scale 4 规范化金额字符串 */
    private $amount;

    /** @var string 三字母币种代码 */
    private $currency;

    private function __construct($amount, $currency)
    {
        $this->amount = (string)$amount;
        $this->currency = strtoupper(trim((string)$currency));
    }

    /**
     * 从任意数值输入构造：拒绝非数值/负数/超 8 位小数（与
     * PricePolicyService::normalizeAmount 同一口径），规范化为 scale 4。
     *
     * @param mixed  $value
     * @param string $currency
     * @param string $field 字段中文名（报错信息用）
     * @return self
     * @throws InvalidArgumentException
     */
    public static function fromString($value, $currency, $field = '金额')
    {
        $text = is_string($value) ? trim($value) : $value;
        if ($text === null || $text === '' || !is_numeric($text)) {
            throw new InvalidArgumentException($field . '必须是数值');
        }
        $text = (string)$text;
        if (bccomp($text, '0', self::RATIO_SCALE) < 0) {
            throw new InvalidArgumentException($field . '不能为负数');
        }
        $dotPosition = strpos($text, '.');
        if ($dotPosition !== false && strlen($text) - $dotPosition - 1 > self::RATIO_SCALE) {
            throw new InvalidArgumentException($field . '最多支持 8 位小数');
        }
        return new self(self::round($text, self::SCALE), $currency);
    }

    /**
     * 已验证金额字符串的内部构造（信任调用方，跳过校验）。
     *
     * @param string $amount scale 4 字符串
     * @param string $currency
     * @return self
     */
    public static function of($amount, $currency)
    {
        return new self(self::round((string)$amount, self::SCALE), $currency);
    }

    /** @return string scale 4 金额字符串 */
    public function getAmount()
    {
        return $this->amount;
    }

    /** @return string 币种代码 */
    public function getCurrency()
    {
        return $this->currency;
    }

    /** @return string */
    public function __toString()
    {
        return $this->amount;
    }

    /** @return self */
    public function add(Money $other)
    {
        $this->assertSameCurrency($other, '相加');
        return self::of(bcadd($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    /** @return self（结果可为负，供调整差额内部使用） */
    public function sub(Money $other)
    {
        $this->assertSameCurrency($other, '相减');
        return self::of(bcsub($this->amount, $other->amount, self::SCALE), $this->currency);
    }

    /**
     * 乘以比率（折扣率/系数/税率/汇率）：先按 RATIO_SCALE 全精度相乘，
     * 再一次性四舍五入到金额精度。
     *
     * @param string $ratio 比率字符串（如 0.95000000）
     * @return self
     */
    public function mulByRatio($ratio)
    {
        return self::of(
            self::round(bcmul($this->amount, (string)$ratio, self::RATIO_SCALE), self::SCALE),
            $this->currency
        );
    }

    /**
     * 乘以数量（数量最多 4 位小数，与 BOM 数量口径一致）。
     *
     * @param string $quantity
     * @return self
     */
    public function mulByQty($quantity)
    {
        return self::of(
            self::round(bcmul($this->amount, (string)$quantity, self::RATIO_SCALE), self::SCALE),
            $this->currency
        );
    }

    /** @return bool 是否为 0 */
    public function isZero()
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    /** @return bool 是否为负（调整差额判断用） */
    public function isNegative()
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    /**
     * 金额比较（同币种）。
     *
     * @param Money $other
     * @return int -1/0/1
     */
    public function compareTo(Money $other)
    {
        $this->assertSameCurrency($other, '比较');
        return bccomp($this->amount, $other->amount, self::SCALE);
    }

    /**
     * 四舍五入到指定小数位（正数 HALF_UP；负数对称 HALF_AWAY_FROM_ZERO）。
     * bcmath 自带截断，本方法是全项目唯一的舍入入口。
     *
     * @param string $value
     * @param int    $scale
     * @return string
     */
    public static function round($value, $scale)
    {
        $value = (string)$value;
        $negative = bccomp($value, '0', self::RATIO_SCALE) < 0;
        $absolute = $negative ? bcmul($value, '-1', self::RATIO_SCALE) : $value;
        $halfUnit = '0.' . str_repeat('0', max(0, $scale)) . '5';
        $rounded = bcadd($absolute, $halfUnit, $scale);
        return $negative ? bcmul($rounded, '-1', $scale) : $rounded;
    }

    /**
     * 规范化数量：非负、最多 4 位小数。
     *
     * @param mixed  $value
     * @param string $field
     * @return string scale 4 数量字符串
     * @throws InvalidArgumentException
     */
    public static function normalizeQuantity($value, $field = '数量')
    {
        $text = is_string($value) ? trim($value) : $value;
        if ($text === null || $text === '' || !is_numeric($text)) {
            throw new InvalidArgumentException($field . '必须是数值');
        }
        $text = (string)$text;
        if (bccomp($text, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException($field . '必须大于0');
        }
        $dotPosition = strpos($text, '.');
        if ($dotPosition !== false && strlen($text) - $dotPosition - 1 > self::SCALE) {
            throw new InvalidArgumentException($field . '最多支持 4 位小数');
        }
        return bcadd($text, '0', self::SCALE);
    }

    private function assertSameCurrency(Money $other, $operation)
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('币种不同的金额不能直接' . $operation
                . '：' . $this->currency . ' vs ' . $other->currency);
        }
    }
}
