<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\TaxRule as TaxRuleModel;
use app\common\controller\Backend;
use app\common\library\cpq\ProductCategory;
use app\common\service\cpq\ImportPreviewService;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * 税率
 *
 * @icon fa fa-percent
 */
class TaxRule extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'code,country_code,region_code,product_type';
    protected $multiFields = 'status';
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'code' => '', 'country_code' => '', 'region_code' => '', 'product_type' => '',
        'rate' => '', 'effective_date' => '', 'expiry_date' => '', 'status' => 'normal',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new TaxRuleModel();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('productTypeList', ['' => '不限'] + ProductCategory::list());
        $this->assignconfig('statusList', $this->model->getStatusList());
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
            $result = (new ImportPreviewService())->preview('tax_rule', $rows);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
        }
        $this->success('预览完成', null, $result);
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
