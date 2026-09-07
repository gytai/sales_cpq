<?php

namespace app\admin\controller\cpq;

use app\admin\library\traits\CpqRelationIndex;
use app\admin\model\cpq\PriceEntry as PriceEntryModel;
use app\common\controller\Backend;
use app\common\service\cpq\ImportPreviewService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\Db;

/**
 * 价格条目
 *
 * @icon fa fa-list
 */
class PriceEntry extends Backend
{
    use CpqRelationIndex;

    protected $model = null;
    protected $modelValidate = true;
    protected $modelSceneValidate = true;
    protected $searchFields = 'price_book_id,target_id';
    protected $noNeedRight = ['selectbook', 'selecttarget'];
    protected $cpqScopeType = null;
    protected $cpqRelations = [];
    protected $cpqFormDefaults = [
        'price_book_id' => '', 'target_type' => 'model', 'target_id' => '',
        'amount' => '', 'unit' => 'item', 'min_qty' => '0', 'max_qty' => '',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new PriceEntryModel();
        $this->view->assign('targetTypeList', $this->model->getTargetTypeList());
        $this->assignconfig('targetTypeList', $this->model->getTargetTypeList());
    }

    /**
     * 可维护价格表下拉：新增价格条目时只允许选择草稿或待审批版本。
     */
    public function selectbook()
    {
        $this->request->filter(['trim', 'strip_tags', 'htmlspecialchars']);

        $keyValue = $this->request->post('keyValue', null);
        $qWords = $this->request->post('q_word/a', []);
        $keyword = trim(implode(' ', array_map('strval', $qWords)));
        $pageNumber = max(1, (int)$this->request->post('pageNumber', 1));
        $pageSize = min(100, max(1, (int)$this->request->post('pageSize', 10)));
        $priceBookIds = [];
        if ($keyValue !== null && $keyValue !== '') {
            $priceBookIds = array_filter(array_map('intval', is_array($keyValue) ? $keyValue : explode(',', (string)$keyValue)));
            if (empty($priceBookIds)) {
                return json(['list' => [], 'total' => 0]);
            }
        }

        $applyFilter = function ($query) use ($priceBookIds, $keyword) {
            if (!empty($priceBookIds)) {
                return $query->where('id', 'in', $priceBookIds);
            }
            $query->where('status', 'in', ['draft', 'pending']);
            if ($keyword !== '') {
                return $query->where('name|code', 'like', '%' . $keyword . '%');
            }
            return $query;
        };

        $total = $applyFilter(Db::name('cpq_price_book'))->count();
        if ($total <= 0) {
            return json(['list' => [], 'total' => 0]);
        }

        $rows = $applyFilter(Db::name('cpq_price_book'))
            ->field('id,code,name,version,status')
            ->order('id', 'desc')
            ->page($pageNumber, $pageSize)
            ->select();
        $statusLabels = [
            'draft' => '草稿',
            'pending' => '待审批',
            'published' => '已发布',
            'expired' => '已失效',
        ];
        $list = [];
        foreach ($rows as $row) {
            $name = trim((string)$row['name']);
            $label = $name !== '' ? $name : trim((string)$row['code']);
            $list[] = [
                'id' => (int)$row['id'],
                'name' => sprintf('%s（V%d · %s）', $label, (int)$row['version'], $statusLabels[$row['status']] ?? $row['status']),
            ];
        }

        return json(['list' => $list, 'total' => $total]);
    }

    /**
     * 定价对象下拉（selectpage 数据源）：按 target_type 在对应对象表内检索 code/name。
     * 只读检索接口，通过 $noNeedRight 跳过权限规则校验（仍要求登录）。
     */
    public function selecttarget()
    {
        $this->request->filter(['trim', 'strip_tags', 'htmlspecialchars']);

        $type = (string)$this->request->post('target_type', '');
        $tableMap = PriceEntryModel::TARGET_TABLES;
        if (!isset($tableMap[$type])) {
            return json(['list' => [], 'total' => 0]);
        }
        $table = $tableMap[$type];

        // 回显（编辑态）：按主键精确查询
        $keyValue = $this->request->post('keyValue', null);
        // selectpage 插件固定以 q_word[] 数组形式提交搜索词
        $qWords = $this->request->post('q_word/a', []);
        $keyword = trim(implode(' ', array_map('strval', $qWords)));
        $pageNumber = max(1, (int)$this->request->post('pageNumber', 1));
        $pageSize = min(100, max(1, (int)$this->request->post('pageSize', 10)));

        $targetIds = [];
        $searchWords = [];
        if ($keyValue !== null && $keyValue !== '') {
            $targetIds = array_filter(array_map('intval', is_array($keyValue) ? $keyValue : explode(',', (string)$keyValue)));
            if (empty($targetIds)) {
                return json(['list' => [], 'total' => 0]);
            }
        } elseif ($keyword !== '') {
            $searchWords = array_filter(array_map('trim', explode(' ', $keyword)));
        }

        $applyFilter = function ($query) use ($targetIds, $searchWords) {
            if (!empty($targetIds)) {
                return $query->where('id', 'in', $targetIds);
            }
            if (!empty($searchWords)) {
                return $query->where(function ($subQuery) use ($searchWords) {
                    foreach ($searchWords as $word) {
                        $subQuery->whereOr('name', 'like', "%{$word}%")
                            ->whereOr('code', 'like', "%{$word}%");
                    }
                });
            }
            return $query;
        };

        $total = $applyFilter(Db::name($table))->count();
        if ($total <= 0) {
            return json(['list' => [], 'total' => 0]);
        }

        $rows = $applyFilter(Db::name($table))
            ->order('id', 'asc')
            ->page($pageNumber, $pageSize)
            ->field('id,code,name')
            ->select();
        $list = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['code'] ?? ''));
            }
            $list[] = ['id' => (int)$row['id'], 'name' => $name];
        }

        return json(['list' => $list, 'total' => $total]);
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
            $result = (new ImportPreviewService())->preview('price_entry', $rows);
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
