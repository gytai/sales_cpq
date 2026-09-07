<?php

namespace app\common\library\cpq;

use RuntimeException;

/**
 * CPQ 定价引擎业务异常（GYTAI-68）。
 *
 * 携带稳定业务错误码（CPQ_PRICE_*）与结构化明细（缺失维度、冲突策略等），
 * 由 API/后台控制器映射为响应 data.business_code；报错信息必须指出具体
 * 型号、配置项或价格维度（方案 §11.3）。
 */
class PricingException extends RuntimeException
{
    /** 输入不合法（数量/折扣率/配置等） */
    const INVALID_INPUT = 'CPQ_PRICE_INVALID_INPUT';

    /** 试算日期无适用价格表（指出公司/板块/市场/币种维度） */
    const BOOK_MISSING = 'CPQ_PRICE_BOOK_MISSING';

    /** 价格表缺少型号/配件的价格条目（指出价格表与对象编码） */
    const ENTRY_MISSING = 'CPQ_PRICE_ENTRY_MISSING';

    /** 缺少三层价格策略（Q-006：指出缺失维度组合） */
    const POLICY_MISSING = 'CPQ_PRICE_POLICY_MISSING';

    /** 策略匹配冲突：相同具体度且相同优先级命中多条（§7.3，不猜测、阻止提交） */
    const POLICY_CONFLICT = 'CPQ_PRICE_POLICY_CONFLICT';

    /** 价格规则运行期冲突：同互斥组同优先级同时命中 */
    const RULE_CONFLICT = 'CPQ_PRICE_RULE_CONFLICT';

    /** 报价低于公司控制价（Q-004：提交硬阻断） */
    const BELOW_COMPANY_FLOOR = 'CPQ_PRICE_BELOW_COMPANY_FLOOR';

    /** 缺少币种换算的有效汇率（指出币种对与试算日期） */
    const RATE_MISSING = 'CPQ_EXCHANGE_RATE_MISSING';

    /** 配置不合法，不能计价 */
    const CONFIG_INVALID = 'CPQ_CONFIG_INVALID';

    /** @var string 业务错误码 */
    private $businessCode;

    /** @var array 结构化明细（缺失维度/冲突对象等，可直接放入响应） */
    private $details;

    /**
     * @param string $message      中文报错信息（含具体对象与维度）
     * @param string $businessCode 业务错误码
     * @param array  $details      结构化明细
     */
    public function __construct($message, $businessCode, array $details = [])
    {
        parent::__construct($message);
        $this->businessCode = (string)$businessCode;
        $this->details = $details;
    }

    /** @return string */
    public function getBusinessCode()
    {
        return $this->businessCode;
    }

    /** @return array */
    public function getDetails()
    {
        return $this->details;
    }
}
