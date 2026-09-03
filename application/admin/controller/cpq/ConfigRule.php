<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ConfigRule as ConfigRuleModel;
use app\admin\validate\cpq\ConfigRule as ConfigRuleValidate;
use app\common\controller\Backend;

/**
 * 配置规则
 *
 * @icon fa fa-random
 */
class ConfigRule extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $searchFields = 'code,name,product_line,message';
    protected $multiFields = 'status';
    protected $cpqRelations = ['productModel'];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'description' => '', 'type' => 'REQUIRES', 'model_id' => '',
        'product_line' => '', 'condition_json' => '{}', 'action_json' => '[]', 'priority' => 0,
        'severity' => 'blocking', 'message' => '', 'version' => 1, 'effective_date' => '',
        'expiry_date' => '', 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ConfigRuleModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('severityList', $this->model->getSeverityList());
        $typeList = [
            'REQUIRES' => '依赖',
            'EXCLUDES' => '互斥',
            'ONE_OF' => '至少选择一项',
            'MIN_MAX' => '数值或数量范围',
            'VISIBILITY' => '可见性',
            'DEFAULT' => '默认值',
            'FORMULA' => '计算公式',
            'WARNING' => '警告',
        ];
        $this->view->assign('typeList', $typeList);
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('severityList', $this->model->getSeverityList());
        $this->assignconfig('typeList', $typeList);
    }

    protected function validateBeforePublish($row)
    {
        $validator = new ConfigRuleValidate();
        if (!$validator->check($row->toArray())) {
            $this->error($validator->getError());
        }
    }
}
