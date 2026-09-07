<?php

namespace app\api\controller\cpq;

use app\common\controller\Api;
use app\common\library\cpq\PricingException;
use app\common\service\cpq\PricingService;

/**
 * CPQ 价格试算与解释接口
 *
 * - POST /api/cpq/v1/prices/calculate 确定性价格试算（不含价格轨迹明细）；
 * - POST /api/cpq/v1/prices/explain   授权范围内的价格轨迹。
 *
 * 开放 API 面向集成/前端，一律返回安全脱敏结果（不含成本、公司控制价与
 * 毛利）；需要按角色查看完整轨迹的场景走后台 cpq/pricing 接口（管理员
 * 会话 + SensitiveFieldService 角色脱敏 + 敏感查看审计）。
 */
class Price extends Api
{
    public function calculate()
    {
        $this->respond(function () {
            $service = new PricingService();
            $result = $service->calculate($this->requestPayload());
            // 试算返回金额与审批分级；命中规则与执行轨迹走 explain
            foreach ($result['lines'] as $index => $line) {
                unset($result['lines'][$index]['price_trace']);
            }
            return $service->maskForRoles($result, []);
        });
    }

    public function explain()
    {
        $this->respond(function () {
            $service = new PricingService();
            // API 侧无管理员身份，统一按最低权限脱敏（不信任客户端传入的角色）
            return $service->maskForRoles($service->calculate($this->requestPayload()), []);
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
        } catch (PricingException $exception) {
            $this->error($exception->getMessage(), [
                'business_code' => $exception->getBusinessCode(),
                'trace_id' => $traceId,
                'payload' => $exception->getDetails(),
            ], 422);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), [
                'business_code' => PricingException::INVALID_INPUT,
                'trace_id' => $traceId,
            ], 422);
        } catch (\Throwable $exception) {
            $this->error('价格服务异常', [
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
