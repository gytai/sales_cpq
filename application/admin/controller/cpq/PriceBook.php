<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\PriceBook as PriceBookModel;
use app\common\controller\Backend;
use app\common\service\cpq\MasterDataLifecycleService;
use app\common\service\cpq\PricePolicyService;
use app\common\service\cpq\PriceReleaseService;

/**
 * 价格表
 *
 * @icon fa fa-table
 */
class PriceBook extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name,company,business_unit';
    protected $multiFields = 'status';
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'company' => '', 'business_unit' => '',
        'market_scope' => 'all', 'currency' => 'CNY', 'tax_mode' => 'tax_exclusive',
        'priority' => 0, 'effective_date' => '', 'expiry_date' => '',
        'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new PriceBookModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('marketScopeList', $this->model->getMarketScopeList());
        $this->view->assign('taxModeList', $this->model->getTaxModeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('marketScopeList', $this->model->getMarketScopeList());
        $this->assignconfig('taxModeList', $this->model->getTaxModeList());
    }

    /**
     * 发布：先走生命周期服务，再登记不可变发布版本。
     */
    public function publish($ids = null)
    {
        $this->cpqRequirePostRequest();
        $row = $this->cpqGetVersionedRow($ids);
        try {
            (new MasterDataLifecycleService())->publish($row, $this->cpqScope());
            $planned = $this->cpqPlannedEffectiveAt();
            $coverage = (new PricePolicyService())->coverageReport($row->id);
            $affected = (int)($coverage['covered'] ?? 0);
            (new PriceReleaseService())->recordRelease(
                $this->cpqTable(),
                $row->getData(),
                (string)$this->request->post('change_summary', ''),
                $planned,
                $affected
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('发布成功');
    }

    /**
     * 覆盖缺口报告（只读）：发布前影响提示与缺口展示共用。
     */
    public function coverage($ids = null)
    {
        $id = $ids ?: $this->request->request('ids');
        if (!$id || strpos((string)$id, ',') !== false) {
            $this->error('请选择一条价格表');
        }
        $row = $this->model->get($id);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $coverage = (new PricePolicyService())->coverageReport((int)$row->id);
        $coverage['price_book'] = ['id' => (int)$row->id, 'code' => (string)$row->code, 'name' => (string)$row->name];
        $this->success('', null, $coverage);
    }

    /**
     * 解析计划生效时间（可空，字符串转 int 时间戳）。
     *
     * @return int|null
     */
    protected function cpqPlannedEffectiveAt()
    {
        $raw = trim((string)$this->request->post('planned_effective_at', ''));
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (int)$raw;
        }
        $timestamp = strtotime($raw);
        return $timestamp === false ? null : $timestamp;
    }
}
