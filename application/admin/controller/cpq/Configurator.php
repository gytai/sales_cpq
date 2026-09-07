<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use app\common\service\cpq\ConfigurationService;

/**
 * 产品配置器
 *
 * @icon fa fa-sliders
 */
class Configurator extends Backend
{
    public function index()
    {
        $models = (new ConfigurationSchemaRepository())->getPublishedModels();
        $this->view->assign('models', $models);
        return $this->view->fetch();
    }

    public function schema()
    {
        try {
            $modelId = $this->request->request('model_id/d');
            if (!$modelId) {
                $this->error('请选择产品型号');
            }
            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
        } catch (\think\exception\HttpResponseException $exception) {
            // TP5 的 error/success 以该异常中断执行，必须重抛，不能当业务异常吞掉
            throw $exception;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('', null, $schema);
    }

    public function validateConfiguration()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        try {
            $modelId = $this->request->post('model_id/d');
            $configuration = $this->decodeConfiguration($this->request->post('configuration'));
            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
            $validation = (new ConfigurationService())->validate($schema, $configuration);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('', null, $validation);
    }

    /**
     * BOM 模拟：与公开接口 /api/cpq/v1/configurations/bom 同一套口径，
     * 配置不合法时拒绝生成 BOM（后台页面经会话权限代理，逻辑仍在服务端）。
     */
    public function bom()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $invalidMessage = '';
        try {
            $modelId = $this->request->post('model_id/d');
            $configuration = $this->decodeConfiguration($this->request->post('configuration'));
            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
            $validation = (new ConfigurationService())->validate($schema, $configuration);
            if (!$validation['is_valid']) {
                $first = $validation['errors'][0]['message'] ?? '';
                $invalidMessage = '配置不合法，不能生成 BOM' . ($first !== '' ? '：' . $first : '');
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
        }
        if ($invalidMessage !== '') {
            $this->error($invalidMessage);
        }
        $this->success('', null, [
            'configuration_hash' => $validation['configuration_hash'],
            'bom' => $validation['bom'],
        ]);
    }

    private function decodeConfiguration($configuration)
    {
        if (is_array($configuration)) {
            return $configuration;
        }
        $decoded = json_decode((string)$configuration, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \InvalidArgumentException('产品配置必须是合法 JSON 对象');
        }
        return $decoded;
    }
}
