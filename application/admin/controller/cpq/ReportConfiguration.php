<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\ProductLineScopeService;
use app\common\service\cpq\ReportService;
use think\Db;

/**
 * P93 配置分析报表（GYTAI-78）。
 *
 * top 型号、配置选项频次（有界采样）与校验失败统计；
 * 非法筛选返回 business_code=CPQ_FILTER_INVALID。
 *
 * @icon fa fa-cubes
 */
class ReportConfiguration extends Backend
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
            $this->success('', null, $service->configurationAnalysis($filters));
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
