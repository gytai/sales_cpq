<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/** 已被业务引用的字典值只能停用，禁止改码、改分类或删除。 */
class DictionaryService
{
    public function reference($valueId, $businessType, $businessId)
    {
        if (!Db::name('cpq_dictionary_value')->where('id', (int)$valueId)->find()) {
            throw new InvalidArgumentException('字典值不存在');
        }
        $row = [
            'dictionary_value_id' => (int)$valueId,
            'business_type' => trim((string)$businessType),
            'business_id' => trim((string)$businessId),
            'createtime' => time(),
        ];
        if ($row['business_type'] === '' || $row['business_id'] === '') {
            throw new InvalidArgumentException('业务引用不能为空');
        }
        $existing = Db::name('cpq_dictionary_reference')
            ->where('dictionary_value_id', $row['dictionary_value_id'])
            ->where('business_type', $row['business_type'])
            ->where('business_id', $row['business_id'])
            ->find();
        return $existing ? (int)$existing['id'] : (int)Db::name('cpq_dictionary_reference')->insertGetId($row);
    }

    public function updateValue($id, array $changes)
    {
        $value = Db::name('cpq_dictionary_value')->where('id', (int)$id)->find();
        if (!$value) {
            throw new InvalidArgumentException('字典值不存在');
        }
        $referenced = Db::name('cpq_dictionary_reference')->where('dictionary_value_id', (int)$id)->count() > 0;
        if ($referenced) {
            $allowed = array_intersect_key($changes, ['status' => true]);
            if (array_diff_key($changes, ['status' => true]) || ($allowed && ($allowed['status'] ?? '') !== 'disabled')) {
                throw new InvalidArgumentException('已被业务引用的字典值只能停用');
            }
        }
        if (isset($changes['status']) && !in_array($changes['status'], ['enabled', 'disabled'], true)) {
            throw new InvalidArgumentException('字典状态无效');
        }
        $changes['updatetime'] = time();
        Db::name('cpq_dictionary_value')->where('id', (int)$id)->update($changes);
        return Db::name('cpq_dictionary_value')->where('id', (int)$id)->find();
    }

    public function deleteValue($id)
    {
        if (Db::name('cpq_dictionary_reference')->where('dictionary_value_id', (int)$id)->count()) {
            throw new InvalidArgumentException('已被业务引用的字典值不能删除，只能停用');
        }
        return Db::name('cpq_dictionary_value')->where('id', (int)$id)->delete();
    }
}
