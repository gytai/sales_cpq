<?php

namespace app\admin\validate\cpq;

use think\Validate;

class BomMapping extends Validate
{
    protected $rule = [
        'model_id' => 'require|integer|gt:0',
        'option_value_id' => 'integer|egt:0',
        'material_code' => 'require|max:64',
        'qty_formula' => 'require|max:500|safeFormula',
        'unit' => 'require|max:32',
        'substitute_material_code' => 'max:64',
        'loss_rate' => 'require|number|lossRate',
        'version' => 'require|integer|gt:0',
        'status' => 'in:draft,published,expired',
    ];

    protected function safeFormula($value)
    {
        return preg_match('/^[A-Za-z0-9_.+\-*\/(){}\s]+$/', (string)$value)
            ? true
            : '数量公式包含不支持的字符';
    }

    protected function lossRate($value)
    {
        return bccomp((string)$value, '0', 6) >= 0 && bccomp((string)$value, '1', 6) <= 0
            ? true
            : '损耗率必须在 0 到 1 之间';
    }
}
