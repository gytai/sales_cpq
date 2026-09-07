<?php

namespace app\admin\validate\cpq;

use think\Validate;

class PriceEntry extends Validate
{
    protected $rule = [
        'price_book_id' => 'require|integer|gt:0',
        'target_type' => 'require|in:model,option,accessory_service',
        'target_id' => 'require|integer|gt:0',
        'amount' => 'require|checkAmount',
        'min_qty' => 'checkAmount',
        'max_qty' => 'checkAmount',
    ];

    protected $message = [
        'price_book_id.require' => '价格表不能为空',
        'target_id.require' => '定价对象不能为空',
        'amount.require' => '价格不能为空',
    ];

    protected $scene = [
        'add' => ['price_book_id', 'target_type', 'target_id', 'amount', 'min_qty', 'max_qty'],
        'edit' => ['price_book_id', 'target_type', 'target_id', 'amount', 'min_qty', 'max_qty'],
    ];

    protected function checkAmount($value)
    {
        if ($value === '' || $value === null) {
            return true;
        }
        return preg_match('/^\d+(\.\d{1,8})?$/', (string)$value) ? true : '金额必须是非负数字';
    }
}
