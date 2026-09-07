<?php

namespace app\api\controller\cpq;

use app\common\controller\Api;
use app\common\repository\cpq\ConfigurationSchemaRepository;
use app\common\service\cpq\ConfigurationService;

/**
 * CPQ产品配置接口
 */
class Configuration extends Api
{
    public function models()
    {
        $this->respond(function () {
            return (new ConfigurationSchemaRepository())->getPublishedModels();
        });
    }

    public function schema($id = null)
    {
        $modelId = $id ?: $this->request->request('model_id/d');
        $this->respond(function () use ($modelId) {
            if (!$modelId) {
                throw new \InvalidArgumentException('请选择产品型号');
            }
            return (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
        });
    }

    public function validateConfiguration()
    {
        $this->respond(function () {
            $payload = $this->requestPayload();
            $modelId = (int)($payload['model_id'] ?? 0);
            $configuration = $payload['configuration'] ?? [];
            if (!$modelId || !is_array($configuration)) {
                throw new \InvalidArgumentException('model_id 和 configuration 必须有效');
            }
            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
            return (new ConfigurationService())->validate($schema, $configuration, $payload['context'] ?? []);
        });
    }

    public function bom()
    {
        $this->respond(function () {
            $payload = $this->requestPayload();
            $modelId = (int)($payload['model_id'] ?? 0);
            $configuration = $payload['configuration'] ?? [];
            if (!$modelId || !is_array($configuration)) {
                throw new \InvalidArgumentException('model_id 和 configuration 必须有效');
            }
            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
            $validation = (new ConfigurationService())->validate($schema, $configuration, $payload['context'] ?? []);
            if (!$validation['is_valid']) {
                throw new \InvalidArgumentException('配置不合法，不能生成 BOM');
            }
            return [
                'configuration_hash' => $validation['configuration_hash'],
                'bom' => $validation['bom'],
            ];
        });
    }

    /**
     * BOM 缺失映射校验（方案 P20）：返回影响 BOM 但未覆盖映射的选项。
     */
    public function bomCheck($id = null)
    {
        $modelId = $id ?: $this->request->request('model_id/d');
        $this->respond(function () use ($modelId) {
            if (!$modelId) {
                throw new \InvalidArgumentException('请选择产品型号');
            }
            $schema = (new ConfigurationSchemaRepository())->getPublishedSchema($modelId);
            $missing = (new ConfigurationService())->findMissingBomMappings($schema);
            return [
                'model_code' => $schema['model']['code'],
                'model_version' => $schema['model']['version'],
                'is_complete' => count($missing) === 0,
                'missing_mappings' => $missing,
            ];
        });
    }

    private function requestPayload()
    {
        $content = file_get_contents('php://input');
        if ($content !== false && trim($content) !== '') {
            $payload = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
                return $payload;
            }
        }
        return $this->request->post();
    }

    private function respond(callable $callback)
    {
        $traceId = bin2hex(random_bytes(12));
        try {
            $payload = $callback();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), [
                'business_code' => 'CPQ_CONFIG_INVALID',
                'trace_id' => $traceId,
            ], 422);
        } catch (\RuntimeException $exception) {
            // 仓储层"型号/系列不存在或未发布"等业务异常，映射为稳定错误码
            $this->error($exception->getMessage(), [
                'business_code' => 'CPQ_SCHEMA_NOT_FOUND',
                'trace_id' => $traceId,
            ], 404);
        } catch (\Throwable $exception) {
            $this->error('产品配置服务异常', [
                'business_code' => 'CPQ_INTERNAL_ERROR',
                'trace_id' => $traceId,
            ], 500);
        }
        $this->success('OK', [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => $payload,
        ]);
    }
}
