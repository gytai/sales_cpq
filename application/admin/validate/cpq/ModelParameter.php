<?php

namespace app\admin\validate\cpq;

use think\Validate;

class ModelParameter extends Validate
{
    protected $rule = [
        'model_id' => 'require|integer|gt:0',
        'parameter_id' => 'require|integer|gt:0',
        'is_configurable' => 'in:0,1',
        'is_required' => 'in:0,1',
        'sort' => 'integer',
    ];
}
