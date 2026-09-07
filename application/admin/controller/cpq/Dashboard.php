<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\DashboardService;
use app\common\service\cpq\ProductLineScopeService;
use think\Db;

/**
 * P01 销售驾驶舱（GYTAI-78）。
 *
 * 首屏一次 AJAX 返回全部区块（KPI/漏斗/风险/趋势/产品分布/待办/快捷入口），
 * 统一筛选经 ReportFilterService 规范化与数据范围校验；非法筛选返回
 * business_code=CPQ_FILTER_INVALID。
 *
 * @icon fa fa-dashboard
 */
class Dashboard extends Backend
{
    /**
     * 驾驶舱首屏：非 AJAX 渲染页面并下发筛选项；AJAX 返回聚合 JSON。
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $service = new DashboardService((int)$this->auth->id);
            try {
                list($filters) = $service->normalize((array)$this->request->get());
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_FILTER_INVALID']);
            }
            $this->success('', null, $service->overview($filters));
        }

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
        return $this->view->fetch();
    }
}
