<?php

namespace app\admin\validate\cpq;

use think\Validate;

class AccessoryService extends Validate
{
    protected $rule = [
        'code' => 'require|regex:^[A-Za-z0-9][A-Za-z0-9_.-]{1,63}$',
        'type' => 'require|max:32',
        'name' => 'require|max:120',
        'unit' => 'require|max:32',
        'product_line' => 'max:64',
        'tax_category' => 'max:64',
        'is_inventory_item' => 'in:0,1',
        'status' => 'in:normal,hidden',
    ];
}
