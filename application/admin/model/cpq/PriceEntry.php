<?php

namespace app\admin\model\cpq;

use think\Db;
use think\Model;

class PriceEntry extends Model
{
    protected $name = 'cpq_price_entry';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['target_type_text', 'target_name'];

    /** target_type 到对象表的映射（表名 => 字段）。 */
    const TARGET_TABLES = [
        'model'             => 'cpq_product_model',
        'option'            => 'cpq_option_value',
        'accessory_service' => 'cpq_accessory_service',
    ];

    public function getTargetTypeList()
    {
        return [
            'model' => '产品型号',
            'option' => '配置选项',
            'accessory_service' => '配件服务',
        ];
    }

    public function getTargetTypeTextAttr($value, $data)
    {
        $type = $data['target_type'] ?? '';
        return $this->getTargetTypeList()[$type] ?? $type;
    }

    /**
     * 对象名称：按 target_type + target_id 解析对应对象的 name（name 为空回退 code）。
     * 列表展示用，让对象ID不再只是不可读的数字。
     */
    public function getTargetNameAttr($value, $data)
    {
        $type = $data['target_type'] ?? '';
        $id = (int)($data['target_id'] ?? 0);
        if ($id <= 0 || !isset(self::TARGET_TABLES[$type])) {
            return '';
        }
        $row = Db::name(self::TARGET_TABLES[$type])->where('id', $id)->field('code,name')->find();
        if (!$row) {
            return '';
        }
        $name = trim((string)$row['name']);
        return $name !== '' ? $name : trim((string)$row['code']);
    }
}
