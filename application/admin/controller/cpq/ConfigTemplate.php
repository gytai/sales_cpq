<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\ConfigTemplate as ConfigTemplateModel;
use app\common\controller\Backend;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use app\common\service\cpq\ConfigurationService;

/**
 * 推荐配置模板
 *
 * @icon fa fa-clone
 */
class ConfigTemplate extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $relationSearch = true;
    protected $cpqRelations = ['productModel'];
    protected $searchFields = 'code,name,market_scope,customer_level';
    protected $multiFields = 'status';
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'model_id' => '', 'market_scope' => '', 'customer_level' => '',
        'config_json' => '{}', 'description' => '', 'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new ConfigTemplateModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->assignconfig('statusList', $this->model->getStatusList());
    }

    protected function validateBeforePublish($row)
    {
        $configuration = json_decode((string)$row['config_json'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($configuration)) {
            $this->error('产品配置必须是合法 JSON 对象');
        }
        $schema = (new ConfigurationSchemaRepository())->getPublishedSchema((int)$row['model_id']);
        $validation = (new ConfigurationService())->validate($schema, $configuration);
        if (!$validation['is_valid']) {
            $messages = array_column($validation['errors'], 'message');
            $this->error('模板配置不合法：' . implode('；', $messages));
        }
    }
}
