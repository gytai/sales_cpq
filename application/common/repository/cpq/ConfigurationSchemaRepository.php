<?php

namespace app\common\repository\cpq;

use RuntimeException;
use think\Db;

class ConfigurationSchemaRepository
{
    public function getPublishedSchema($modelId)
    {
        $model = Db::name('cpq_product_model')
            ->where('id', (int)$modelId)
            ->where('status', 'published')
            ->find();
        if (!$model) {
            throw new RuntimeException('产品型号不存在或尚未发布');
        }

        $series = Db::name('cpq_product_series')->where('id', $model['series_id'])->find();
        if (!$series || $series['status'] !== 'published') {
            throw new RuntimeException('产品系列不存在或尚未发布');
        }

        $mappings = Db::name('cpq_model_option_group')
            ->where('model_id', $model['id'])
            ->where('is_visible', 1)
            ->order('sort asc,id asc')
            ->select();
        $groupIds = array_values(array_unique(array_map(function ($mapping) {
            return (int)$mapping['group_id'];
        }, $mappings)));

        $groupsById = [];
        $optionsByGroupId = [];
        if ($groupIds) {
            $groups = Db::name('cpq_option_group')
                ->where('id', 'in', $groupIds)
                ->where('status', 'normal')
                ->select();
            foreach ($groups as $group) {
                $groupsById[(int)$group['id']] = $group;
            }

            $options = Db::name('cpq_option_value')
                ->where('group_id', 'in', $groupIds)
                ->where('status', 'normal')
                ->order('weigh desc,id asc')
                ->select();
            foreach ($options as $option) {
                $optionsByGroupId[(int)$option['group_id']][] = $option;
            }
        }

        $schemaGroups = [];
        foreach ($mappings as $mapping) {
            $groupId = (int)$mapping['group_id'];
            if (!isset($groupsById[$groupId])) {
                continue;
            }
            $group = $groupsById[$groupId];
            $schemaGroups[] = [
                'id' => (int)$group['id'],
                'code' => $group['code'],
                'name' => $group['name'],
                'name_en' => $group['name_en'],
                'input_type' => $group['input_type'],
                'is_required' => (bool)($mapping['is_required'] || $group['is_required']),
                'min_select' => (int)$group['min_select'],
                'max_select' => (int)$group['max_select'],
                'affects_price' => (bool)$group['affects_price'],
                'affects_bom' => (bool)$group['affects_bom'],
                'help_text' => $group['help_text'],
                'sort' => (int)$mapping['sort'],
                'default_value' => $this->decodeJson($mapping['default_value'], null),
                'options' => array_map(function ($option) {
                    return [
                        'id' => (int)$option['id'],
                        'code' => $option['code'],
                        'name' => $option['name'],
                        'name_en' => $option['name_en'],
                        'material_code' => $option['material_code'],
                        'default_qty' => $option['default_qty'],
                        'min_qty' => $option['min_qty'],
                        'max_qty' => $option['max_qty'],
                        'step' => $option['step'],
                        'price_key' => $option['price_key'],
                        'image' => $option['image'],
                        'parameters' => $this->decodeJson($option['parameter_json'], []),
                    ];
                }, $optionsByGroupId[$groupId] ?? []),
            ];
        }

        $rules = Db::name('cpq_config_rule')
            ->where('status', 'published')
            ->where(function ($query) use ($model, $series) {
                $query->where('model_id', $model['id'])
                    ->whereOr(function ($scopeQuery) use ($series) {
                        $scopeQuery->whereNull('model_id')->where('product_line', $series['product_line']);
                    });
            })
            ->order('priority desc,id asc')
            ->select();

        $today = date('Y-m-d');
        $schemaRules = [];
        foreach ($rules as $rule) {
            if (($rule['effective_date'] && $rule['effective_date'] > $today)
                || ($rule['expiry_date'] && $rule['expiry_date'] < $today)
            ) {
                continue;
            }
            $schemaRules[] = [
                'id' => (int)$rule['id'],
                'code' => $rule['code'],
                'name' => $rule['name'],
                'type' => $rule['type'],
                'priority' => (int)$rule['priority'],
                'severity' => $rule['severity'],
                'message' => $rule['message'],
                'version' => (int)$rule['version'],
                'condition' => $this->decodeJson($rule['condition_json'], []),
                'actions' => $this->decodeJson($rule['action_json'], []),
            ];
        }

        $bomMappings = Db::name('cpq_bom_mapping')
            ->where('model_id', $model['id'])
            ->where('status', 'published')
            ->order('version desc,id desc')
            ->select();
        $schemaBomMappings = [];
        $mappingKeys = [];
        foreach ($bomMappings as $mapping) {
            $mappingKey = (int)($mapping['option_value_id'] ?? 0) . '|' . $mapping['material_code'];
            if (isset($mappingKeys[$mappingKey])) {
                continue;
            }
            $mappingKeys[$mappingKey] = true;
            $schemaBomMappings[] = [
                'id' => (int)$mapping['id'],
                'option_value_id' => $mapping['option_value_id'] === null ? null : (int)$mapping['option_value_id'],
                'material_code' => $mapping['material_code'],
                'qty_formula' => $mapping['qty_formula'],
                'unit' => $mapping['unit'],
                'substitute_material_code' => $mapping['substitute_material_code'],
                'loss_rate' => $mapping['loss_rate'],
                'version' => (int)$mapping['version'],
            ];
        }

        return [
            'model' => [
                'id' => (int)$model['id'],
                'code' => $model['code'],
                'name' => $model['name'],
                'name_en' => $model['name_en'],
                'series_id' => (int)$series['id'],
                'series_code' => $series['code'],
                'series_name' => $series['name'],
                'product_line' => $series['product_line'],
                'category_code' => $model['category_code'],
                'base_item_code' => $model['base_item_code'],
                'unit' => $model['unit'],
                'version' => (int)$model['version'],
            ],
            'groups' => $schemaGroups,
            'rules' => $schemaRules,
            'bom_mappings' => $schemaBomMappings,
        ];
    }

    public function getPublishedModels()
    {
        return Db::name('cpq_product_model')
            ->alias('model')
            ->join('__CPQ_PRODUCT_SERIES__ series', 'series.id = model.series_id')
            ->where('model.status', 'published')
            ->where('series.status', 'published')
            ->field('model.id,model.code,model.name,model.name_en,model.category_code,series.name AS series_name,series.product_line')
            ->order('series.weigh desc,model.weigh desc,model.id asc')
            ->select();
    }

    private function decodeJson($value, $default)
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('数据库中的配置 JSON 无效：' . json_last_error_msg());
        }
        return $decoded;
    }
}
