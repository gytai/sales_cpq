<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ConfigRule as ConfigRuleModel;
use app\common\controller\Backend;
use app\common\library\cpq\RuleDsl;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use app\common\service\cpq\ConfigurationService;
use app\common\service\cpq\MasterDataLifecycleService;

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
    protected $cpqScopeType = 'rule';
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

    /**
     * 单规则测试（方案 P18）：用表单中的条件/动作在指定型号的已发布
     * 配置结构上试跑，返回是否命中、命中的错误/警告与试算后配置。
     * 只读操作，不写库；规则解释完全由后端 ConfigurationService 执行。
     */
    public function testrule()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        try {
            $modelId = $this->request->post('model_id/d');
            if (!$modelId) {
                throw new \InvalidArgumentException('单规则测试必须选择适用型号');
            }
            $condition = RuleDsl::assertConditionJson($this->request->post('condition_json', ''));
            $actions = RuleDsl::assertActionJson($this->request->post('action_json', ''));
            $configuration = $this->request->post('configuration', '');
            $configuration = json_decode((string)$configuration, true);
            if (!is_array($configuration)) {
                throw new \InvalidArgumentException('测试配置必须是合法 JSON 对象');
            }

            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
            $candidate = [
                'code' => '__RULE_TEST__',
                'severity' => (string)$this->request->post('severity', 'blocking'),
                'priority' => (int)$this->request->post('priority', 0),
                'message' => (string)$this->request->post('message', ''),
                'condition' => $condition,
                'actions' => $actions,
            ];
            // 只跑候选规则，隔离其他已发布规则的影响
            $schema['rules'] = [$candidate];
            $result = (new ConfigurationService())->validate($schema, $configuration);
            $result['matched'] = in_array('__RULE_TEST__', $result['applied_rules'], true);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('', null, $result);
    }

    /**
     * 冲突检测（方案 P18）：把表单中的候选规则放进其生效范围内的
     * 已发布规则全集，返回循环依赖/永真冲突/不可达选项的结构化结果。
     * 与发布门禁（MasterDataLifecycleService::assertRuleSetConsistent）
     * 共用同一套分析逻辑，只读不写库。
     */
    public function analyze()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        try {
            $data = [
                'code' => (string)$this->request->post('code', ''),
                'model_id' => $this->request->post('model_id/d'),
                'product_line' => (string)$this->request->post('product_line', ''),
                'severity' => (string)$this->request->post('severity', 'blocking'),
                'condition_json' => $this->request->post('condition_json', ''),
                'action_json' => $this->request->post('action_json', ''),
            ];
            if ($data['code'] === '') {
                throw new \InvalidArgumentException('请先填写规则编码');
            }
            $issues = (new MasterDataLifecycleService())->analyzeRuleSet($data);
            $issues['is_clean'] = empty($issues['cycles']) && empty($issues['conflicts']) && empty($issues['unreachable']);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('', null, $issues);
    }
}
