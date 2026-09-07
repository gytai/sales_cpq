<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\library\cpq\PricingException;
use app\common\service\cpq\PricingService;
use app\common\service\cpq\SensitiveFieldService;

/**
 * 价格模拟器（P36，GYTAI-68）
 *
 * 后台会话代理的价格试算/解释接口（报价向导与价格模拟器页面共用）：
 *  - calculate：确定性试算，按当前管理员角色脱敏成本/公司控制价/毛利；
 *  - explain：完整价格轨迹（授权范围内）+ 敏感查看审计。
 *
 * 视图与前端 JS 由 M2 前端任务（GYTAI-74）交付。
 *
 * @icon fa fa-calculator
 */
class Pricing extends Backend
{
    /**
     * 价格模拟器页面（P36）：视图与交互由 GYTAI-74 交付。
     * 下发当前管理员角色的敏感字段可见性，前端据此隐藏成本/公司控制价
     * 展示区（服务端 calculate/explain 已按角色脱敏，此处仅控制 DOM 渲染）。
     */
    public function index()
    {
        $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
        $this->view->assign('canViewCost', (bool)array_intersect($roles, array_merge(
            SensitiveFieldService::FULL_ACCESS_ROLES,
            SensitiveFieldService::LINE_ACCESS_ROLES
        )));
        $this->view->assign('canViewCompanyFloor', (bool)array_intersect($roles, SensitiveFieldService::FULL_ACCESS_ROLES));
        return $this->view->fetch();
    }

    public function calculate()
    {
        $this->handle(false);
    }

    public function explain()
    {
        $this->handle(true);
    }

    private function handle($withTrace)
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $service = new PricingService();
        $sensitive = new SensitiveFieldService();
        $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
        try {
            $result = $service->calculate($this->requestPayload());
            if ($withTrace) {
                if ($sensitive->requiresAudit($roles)) {
                    $modelIds = [];
                    foreach ($result['lines'] as $line) {
                        $modelIds[] = (int)$line['model_id'];
                    }
                    $sensitive->recordAccess('view_sensitive', 'cpq_price_trace', $modelIds, $roles);
                }
            } else {
                // 试算只返回金额与分级；命中规则与执行轨迹走 explain
                foreach ($result['lines'] as $index => $line) {
                    unset($result['lines'][$index]['price_trace']);
                }
            }
            $masked = $service->maskForRoles($result, $roles);
        } catch (PricingException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => $exception->getBusinessCode(),
                'details' => $exception->getDetails(),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, [
                'business_code' => PricingException::INVALID_INPUT,
            ]);
        } catch (\Throwable $exception) {
            $this->error('价格服务异常：' . $exception->getMessage());
        }
        $this->success('', null, $masked);
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
}
