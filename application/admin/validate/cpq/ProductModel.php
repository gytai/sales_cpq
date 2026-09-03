<?php

namespace app\admin\validate\cpq;

use think\Validate;

class ProductModel extends Validate
{
    protected $rule = [
        'series_id' => 'require|integer|gt:0',
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$|unique:cpq_product_model',
        'name' => 'require|max:120',
        'unit' => 'require|max:32',
        'allow_custom' => 'in:0,1',
        'allow_overseas' => 'in:0,1',
        'version' => 'require|integer|gt:0',
        'effective_date' => 'date',
        'expiry_date' => 'date|checkDateRange',
    ];

    protected $message = [
        'code.regex' => '型号编码仅允许字母、数字、点、横线和下划线',
    ];

    protected $scene = [
        'add' => ['series_id', 'code', 'name', 'unit', 'allow_custom', 'allow_overseas', 'version', 'effective_date', 'expiry_date'],
        'edit' => ['series_id', 'code', 'name', 'unit', 'allow_custom', 'allow_overseas', 'version', 'effective_date', 'expiry_date'],
    ];

    protected function checkDateRange($value, $rule, $data)
    {
        return empty($value) || empty($data['effective_date']) || $value >= $data['effective_date']
            ? true
            : '失效日期不能早于生效日期';
    }
}
