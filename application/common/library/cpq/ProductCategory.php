<?php

namespace app\common\library\cpq;

/**
 * 产品分类统一枚举（代码常量约定）。
 *
 * 产品型号的 category_code 与税率规则的 product_type 共用同一套编码，
 * 保证计价时税率匹配（PricingService::matchTaxRule）能精确命中。
 * 税率匹配对 product_type 做大小写敏感的字符串比较，故编码一律小写。
 */
class ProductCategory
{
    /** 产品分类编码 => 显示名 */
    const CODES = [
        'hardware' => '硬件',
        'software' => '软件',
        'service'  => '服务',
        'license'  => '软件授权',
    ];

    /**
     * 表单下拉选项（不含空选项，空选项语义由调用方按需前置）。
     *
     * @return array
     */
    public static function list()
    {
        return self::CODES;
    }

    /**
     * 是否为合法分类编码。
     *
     * @param string $code
     * @return bool
     */
    public static function isValid($code)
    {
        return array_key_exists((string)$code, self::CODES);
    }
}
