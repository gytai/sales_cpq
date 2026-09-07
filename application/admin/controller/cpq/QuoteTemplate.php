<?php

namespace app\admin\controller\cpq;

use app\admin\model\cpq\QuoteTemplate as QuoteTemplateModel;
use app\common\controller\Backend;
use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\QuoteTemplateService;
use think\Db;
use think\exception\PDOException;

/**
 * 报价中心：报价模板（P59，GYTAI-75）
 *
 *  - 标准 CRUD：中英文模板结构（封面/公司信息/产品表/条款/签章/水印），
 *    变量白名单校验（未知变量保存时报错）；
 *  - preview  模板预览（测试数据渲染 + 缺失变量提示）；
 *  - publish  发布（内容校验）；已发布模板内容不可直接修改，复制新版本；
 *  - copy     复制新版本；setdefault 设为市场默认模板（同语言+市场唯一）。
 *
 * @icon fa fa-file-pdf-o
 */
class QuoteTemplate extends Backend
{
    protected $model = null;
    protected $modelValidate = true;
    protected $searchFields = 'code,name,name_en';
    protected $multiFields = 'status';

    /** @var QuoteTemplateService */
    private $templateService;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new QuoteTemplateModel();
        $this->templateService = new QuoteTemplateService();
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->view->assign('languageList', $this->model->getLanguageList());
        $this->view->assign('marketList', $this->model->getMarketList());
        $this->view->assign('variableWhitelist', QuoteTemplateService::VARIABLE_WHITELIST);
        $this->assignconfig('statusList', $this->model->getStatusList());
        $this->assignconfig('languageList', $this->model->getLanguageList());
        $this->assignconfig('marketList', $this->model->getMarketList());
    }

    /**
     * 仅草稿模板可删除（已发布/停用模板保留历史，服务端重复校验）。
     */
    public function del($ids = '')
    {
        $ids = $ids ?: $this->request->request('ids');
        $row = Db::name('cpq_quote_template')->where('id', 'in', (array)$ids)->select();
        foreach ($row as $item) {
            if ($item['status'] !== 'draft') {
                $this->error('仅草稿模板可删除，已发布模板请停用或复制新版本');
            }
        }
        parent::del($ids);
    }

    /**
     * 模板预览：测试数据渲染 + 缺失变量。
     */
    public function preview($ids = null)
    {
        $template = Db::name('cpq_quote_template')->where('id', (int)$ids)->find();
        if (!$template) {
            $this->error('模板不存在');
        }
        $traceId = bin2hex(random_bytes(12));
        try {
            $preview = $this->templateService->preview($template);
        } catch (\Throwable $exception) {
            $this->error('模板预览失败：' . $exception->getMessage(), null, ['trace_id' => $traceId]);
        }
        $this->success('OK', null, [
            'business_code' => 'OK',
            'trace_id' => $traceId,
            'payload' => [
                'html' => $preview['html'],
                'missing_variables' => $preview['missing_variables'],
                'missing_variable_texts' => array_map(function ($variable) {
                    return $variable . '（' . (QuoteTemplateService::VARIABLE_WHITELIST[$variable] ?? $variable) . '）';
                }, $preview['missing_variables']),
            ],
        ]);
    }

    /**
     * 发布模板。
     */
    public function publish()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('ids');
        try {
            $this->templateService->publish($id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_TEMPLATE_INVALID']);
        }
        (new AuditLogService())->record('publish', 'cpq_quote_template', $id, []);
        $this->success('模板已发布');
    }

    /**
     * 设为市场默认模板。
     */
    public function setdefault()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('ids');
        try {
            $this->templateService->setDefault($id);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_TEMPLATE_INVALID']);
        }
        (new AuditLogService())->record('update', 'cpq_quote_template', $id, ['is_default' => 1]);
        $this->success('已设为市场默认模板');
    }

    /**
     * 复制新版本（已发布模板内容不可改，复制为草稿修改）。
     */
    public function copy()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('ids');
        $template = Db::name('cpq_quote_template')->where('id', $id)->find();
        if (!$template) {
            $this->error('模板不存在');
        }
        $now = time();
        $newCode = '';
        for ($i = 1; $i <= 50; $i++) {
            $candidate = $template['code'] . '-V' . ((int)$template['version'] + $i);
            if (!Db::name('cpq_quote_template')->where('code', $candidate)->count()) {
                $newCode = $candidate;
                break;
            }
        }
        if ($newCode === '') {
            $this->error('无法生成新模板编码');
        }
        $newId = Db::name('cpq_quote_template')->insertGetId([
            'code' => $newCode,
            'name' => $template['name'] . '（副本）',
            'name_en' => $template['name_en'],
            'language' => $template['language'],
            'market_scope' => $template['market_scope'],
            'paper_size' => $template['paper_size'],
            'is_default' => 0,
            'content_json' => $template['content_json'],
            'allowed_variables' => $template['allowed_variables'],
            'version' => 1,
            'status' => 'draft',
            'createtime' => $now,
            'updatetime' => $now,
        ]);
        (new AuditLogService())->record('copy', 'cpq_quote_template', (int)$newId, ['source_id' => $id]);
        $this->success('已复制为新版本草稿', null, ['payload' => ['id' => $newId, 'code' => $newCode]]);
    }

    /**
     * 停用模板。
     */
    public function disable()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('ids');
        Db::name('cpq_quote_template')->where('id', $id)->update([
            'status' => 'disabled',
            'is_default' => 0,
            'updatetime' => time(),
        ]);
        (new AuditLogService())->record('expire', 'cpq_quote_template', $id, []);
        $this->success('模板已停用');
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                $params = $this->normalize($params);
                try {
                    $result = $this->model->allowField(true)->save($params);
                    if ($result !== false) {
                        (new AuditLogService())->record('create', 'cpq_quote_template', (int)$this->model->id, ['code' => $params['code']]);
                        $this->success();
                    } else {
                        $this->error($this->model->getError());
                    }
                } catch (PDOException $e) {
                    $this->error($e->getMessage());
                }
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $this->view->assign('defaultContentJson', json_encode($this->templateService->defaultContent(), JSON_UNESCAPED_UNICODE));
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get((int)$ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            if ($params) {
                if ($row['status'] === 'published') {
                    $this->error('已发布模板内容不可直接修改，请复制新版本');
                }
                $params = $this->normalize($params);
                try {
                    $result = $this->model->allowField(true)->save($params, ['id' => (int)$ids]);
                    if ($result !== false) {
                        (new AuditLogService())->record('update', 'cpq_quote_template', (int)$ids, ['code' => $params['code'] ?? $row['code']]);
                        $this->success();
                    } else {
                        $this->error($this->model->getError());
                    }
                } catch (PDOException $e) {
                    $this->error($e->getMessage());
                }
            }
            $this->error(__('Parameter %s can not be empty', ''));
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    private function normalize(array $params)
    {
        $params['code'] = trim((string)($params['code'] ?? ''));
        $params['name'] = trim((string)($params['name'] ?? ''));
        $params['language'] = in_array(($params['language'] ?? ''), ['zh', 'en'], true) ? $params['language'] : 'zh';
        $params['market_scope'] = in_array(($params['market_scope'] ?? ''), ['all', 'domestic', 'international'], true) ? $params['market_scope'] : 'all';
        $content = json_decode((string)($params['content_json'] ?? ''), true);
        if (!is_array($content)) {
            throw new \InvalidArgumentException('模板结构必须是合法 JSON');
        }
        // 保存即校验：板块白名单 + 变量白名单
        $params['content_json'] = json_encode($this->templateService->validateContent($content), JSON_UNESCAPED_UNICODE);
        $params['allowed_variables'] = json_encode(array_keys(QuoteTemplateService::VARIABLE_WHITELIST));
        unset($params['status'], $params['is_default']);
        return $params;
    }
}
