<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\library\traits\CpqVersioned;
use app\admin\model\cpq\PricePolicy as PricePolicyModel;
use app\common\controller\Backend;
use app\common\service\cpq\ImportPreviewService;
use app\common\service\cpq\MasterDataLifecycleService;
use app\common\service\cpq\PriceReleaseService;
use app\common\service\cpq\SensitiveFieldService;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 价格策略
 *
 * @icon fa fa-shield
 */
class PricePolicy extends Backend
{
    use CpqRelationIndex;
    use CpqVersioned;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,name,company,business_unit,product_line';
    protected $multiFields = 'status';
    protected $cpqScopeType = 'line';
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'name' => '', 'dimension_key' => '', 'company' => '', 'business_unit' => '',
        'market_scope' => 'all', 'region_code' => '', 'customer_level' => '', 'agent_level' => '',
        'customer_id' => '', 'agent_id' => '', 'product_line' => '', 'target_type' => 'model', 'target_id' => '',
        'currency' => 'CNY', 'unit' => 'item', 'guide_price' => '', 'line_floor' => '',
        'company_floor' => '', 'cost' => '0', 'priority' => 0, 'effective_date' => '',
        'expiry_date' => '', 'version' => 1, 'status' => 'draft',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new PricePolicyModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('targetTypeList', $this->model->getTargetTypeList());
        $this->view->assign('marketScopeList', $this->model->getMarketScopeList());
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('targetTypeList', $this->model->getTargetTypeList());
        $this->assignconfig('marketScopeList', $this->model->getMarketScopeList());
        // 敏感字段可见性：成本/公司控制价按角色隐藏表单与列（列表数据服务端已脱敏）
        $roles = SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
        $this->view->assign('canViewCost', (bool)array_intersect($roles, array_merge(
            SensitiveFieldService::FULL_ACCESS_ROLES,
            SensitiveFieldService::LINE_ACCESS_ROLES
        )));
        $this->view->assign('canViewCompanyFloor', (bool)array_intersect($roles, SensitiveFieldService::FULL_ACCESS_ROLES));
        $this->assignconfig('canViewCost', (bool)array_intersect($roles, array_merge(
            SensitiveFieldService::FULL_ACCESS_ROLES,
            SensitiveFieldService::LINE_ACCESS_ROLES
        )));
        $this->assignconfig('canViewCompanyFloor', (bool)array_intersect($roles, SensitiveFieldService::FULL_ACCESS_ROLES));
    }

    /**
     * 列表：敏感字段按角色脱敏，并按需记录访问审计。
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if (!$this->request->isAjax()) {
            return $this->view->fetch();
        }
        if ($this->request->request('keyField')) {
            return $this->selectpage();
        }

        list($where, $sort, $order, $offset, $limit) = $this->buildparams();
        $query = $this->model;
        if (!empty($this->cpqRelations)) {
            $this->relationSearch = true;
            $query = $query->with($this->cpqRelations);
        }
        $query = $this->cpqApplyScopeFilter($query);
        $list = $query->where($where)->order($sort, $order)->paginate($limit);

        $service = new SensitiveFieldService();
        $roles = SensitiveFieldService::rolesOfAdmin($this->auth->id);
        $rows = $service->maskRows($list->items(), $roles);
        if ($service->requiresAudit($roles)) {
            $service->recordAccess('view_sensitive', 'cpq_price_policy', array_column($list->items(), 'id'), $roles);
        }

        return json([
            'total' => $list->total(),
            'rows' => $rows,
        ]);
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
     * 导入预览（不落库，仅校验并返回差异）。
     */
    public function importpreview()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $rows = $this->cpqImportRows();
        if (empty($rows)) {
            $this->error('没有可预览的数据行');
        }
        try {
            $result = (new ImportPreviewService())->preview('price_policy', $rows);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        }
        $this->success('预览完成', null, $result);
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

    /**
     * 解析导入数据：POST rows（JSON 数组字符串）或上传 xlsx/xls/csv 文件。
     *
     * @return array
     */
    protected function cpqImportRows()
    {
        $rowsRaw = $this->request->post('rows', '');
        if (is_string($rowsRaw) && trim($rowsRaw) !== '') {
            $decoded = json_decode($rowsRaw, true);
            if (!is_array($decoded)) {
                $this->error('rows 参数必须是 JSON 数组字符串');
            }
            return $decoded;
        }
        if (is_array($rowsRaw) && !empty($rowsRaw)) {
            return $rowsRaw;
        }
        $file = $this->request->file('file');
        if (!$file) {
            $this->error('请提供 rows 参数或上传文件');
        }
        $ext = strtolower(pathinfo((string)$file->getInfo('name'), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->error('仅支持 xlsx/xls/csv 文件');
        }
        try {
            $spreadsheet = IOFactory::load($file->getInfo('tmp_name'));
            $sheetData = $spreadsheet->getSheet(0)->toArray('', true, true, false);
        } catch (\Throwable $e) {
            $this->error('文件读取失败：' . $e->getMessage());
        }
        if (empty($sheetData)) {
            $this->error('文件内容为空');
        }
        $headers = array_map(function ($header) {
            return trim((string)$header);
        }, (array)array_shift($sheetData));
        $rows = [];
        foreach ($sheetData as $line) {
            $line = array_slice(array_pad((array)$line, count($headers), null), 0, count($headers));
            $row = array_combine($headers, $line);
            $hasValue = false;
            foreach ($row as $v) {
                if ($v !== null && $v !== '') {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
