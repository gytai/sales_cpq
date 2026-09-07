<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\PriceRule as PriceRuleModel;
use app\common\controller\Backend;
use app\common\service\cpq\MasterDataLifecycleService;
use app\common\service\cpq\PriceReleaseService;

/**
 * 价格规则
 *
 * @icon fa fa-random
 */
class PriceRule extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name';
    protected $multiFields = 'status';
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'condition_json' => '', 'adjustment_type' => 'discount',
        'adjustment_target' => 'subtotal', 'adjustment_value' => '', 'can_stack' => 0,
        'exclusive_group' => '', 'minimum_amount' => '', 'maximum_amount' => '',
        'priority' => 0, 'effective_date' => '', 'expiry_date' => '',
        'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new PriceRuleModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('adjustmentTypeList', $this->model->getAdjustmentTypeList());
        $this->view->assign('adjustmentTargetList', $this->model->getAdjustmentTargetList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('adjustmentTypeList', $this->model->getAdjustmentTypeList());
        $this->assignconfig('adjustmentTargetList', $this->model->getAdjustmentTargetList());
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
            (new PriceReleaseService())->recordRelease(
                $this->cpqTable(),
                $row->getData(),
                (string)$this->request->post('change_summary', ''),
                $planned,
                0
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('发布成功');
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
