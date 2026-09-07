<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\ProductLineScopeService;
use app\common\service\cpq\ReportService;
use app\common\service\cpq\SensitiveFieldService;
use think\Db;

/**
 * P91 折扣与毛利分析报表（GYTAI-78）。
 *
 * 产品线→型号聚合折扣/金额/毛利，返回行经 SensitiveFieldService 按角色
 * 递归脱敏（无权角色不含成本/毛利键，前端按键存在与否渲染列）；
 * 非法筛选返回 business_code=CPQ_FILTER_INVALID。
 *
 * @icon fa fa-percent
 */
class ReportPricing extends Backend
{
    public function index()
    {
        if ($this->request->isAjax()) {
            $service = new ReportService((int)$this->auth->id);
            try {
                list($filters) = $service->normalize((array)$this->request->get());
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_FILTER_INVALID']);
            }
            $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
            // 资源级审计：可见成本/毛利的角色查看 P91 必须留痕（不含敏感值）
            $sensitive = new SensitiveFieldService();
            if ($sensitive->requiresAudit($roles)) {
                $sensitive->recordAccess('view_sensitive', 'cpq_report_pricing', [0], $roles);
            }
            $this->success('', null, $service->discountMargin($filters, $roles));
        }
        $this->assignFilterOptions();
        return $this->view->fetch();
    }

    /**
     * 下发筛选项（产品线/币种/事业部）。
     */
    private function assignFilterOptions()
    {
        $productLineList = ProductLineScopeService::productLineOptionsFor((int)$this->auth->id);
        $currencyList = Db::name('cpq_quote')->group('currency')->column('currency');
        $currencyList = array_values(array_filter(array_map('strval', $currencyList), function ($item) {
            return $item !== '';
        }));
        $companyList = Db::name('cpq_quote')->group('company')->column('company');
        $companyList = array_values(array_filter(array_map('strval', $companyList), function ($item) {
            return $item !== '';
        }));
        $this->view->assign('productLineList', $productLineList);
        $this->view->assign('currencyList', $currencyList);
        $this->view->assign('companyList', $companyList);
        $this->assignconfig('productLineList', $productLineList);
        $this->assignconfig('currencyList', $currencyList);
        $this->assignconfig('companyList', $companyList);
    }
}
