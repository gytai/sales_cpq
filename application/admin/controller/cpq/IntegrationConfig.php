<?php

namespace app\admin\controller\cpq;

use app\common\controller\Backend;
use app\common\service\cpq\IntegrationCredentialService;
use app\common\service\cpq\SensitiveFieldService;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use think\exception\PDOException;

/**
 * CPQ 接口管理（P104，GYTAI-78）
 *
 * 维护 cpq_integration_config（CRM/ERP/邮件等外部系统接入配置）。
 * 读取：system_admin / auditor；写动作：仅 system_admin。
 * 列表与详情经 IntegrationCredentialService::publicConfig 净化，
 * 永不返回任何 credential_* 字段；凭证只允许「重置」，永不回显。
 *
 * @icon fa fa-plug
 */
class IntegrationConfig extends Backend
{
    /** 可写角色：仅系统管理员 */
    const WRITE_ROLES = ['system_admin'];

    /** 可读角色 */
    const READ_ROLES = ['system_admin', 'auditor'];

    /**
     * 接口配置分页列表（全部字段经 publicConfig 净化）。
     */
    public function index()
    {
        $this->assertRead();
        if (!$this->request->isAjax()) {
            $this->assignconfig('canWrite', $this->canWrite());
            return $this->view->fetch();
        }
        $page = max(1, (int)$this->request->request('page', 1));
        $limit = max(1, min(100, (int)$this->request->request('limit', 20)));
        $keyword = trim((string)$this->request->request('keyword', ''));
        $systemType = trim((string)$this->request->request('system_type', ''));
        $status = trim((string)$this->request->request('status', ''));

        $buildQuery = function () use ($keyword, $systemType, $status) {
            $query = Db::name('cpq_integration_config');
            if ($keyword !== '') {
                $query->where('code|name', 'like', '%' . $keyword . '%');
            }
            if (in_array($systemType, ['crm', 'erp', 'mail', 'other'], true)) {
                $query->where('system_type', $systemType);
            }
            if (in_array($status, ['enabled', 'disabled'], true)) {
                $query->where('status', $status);
            }
            return $query;
        };
        $total = (int)$buildQuery()->count();
        $rows = $buildQuery()
            ->order('id', 'asc')
            ->limit(($page - 1) * $limit, $limit)
            ->select();

        return json(['total' => $total, 'rows' => $this->publicRows($rows ?: [])]);
    }

    /**
     * 新增接口配置（GET 渲染表单，POST 保存基本信息，不含凭证）。
     */
    public function add()
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->view->assign('row', [
                'code' => '', 'name' => '', 'system_type' => 'other', 'base_url' => '',
                'auth_type' => 'none', 'hmac_algorithm' => 'sha256',
                'timeout_ms' => 5000, 'max_retries' => 3, 'status' => 'disabled',
            ]);
            return $this->view->fetch();
        }
        $this->doSave(null);
    }

    /**
     * 编辑接口配置基本信息（凭证不在此表单内）。
     */
    public function edit($ids = null)
    {
        $this->assertWrite();
        $row = Db::name('cpq_integration_config')->where('id', (int)$ids)->find();
        if (!$row) {
            $this->error('接口配置不存在');
        }
        if (!$this->request->isPost()) {
            $this->view->assign('row', $this->publicRow($row));
            return $this->view->fetch();
        }
        $this->doSave((int)$ids);
    }

    /**
     * 保存接口配置基本信息（id 可选：空=新增）。
     */
    public function save()
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('id');
        $this->doSave($id > 0 ? $id : null);
    }

    /**
     * 重置凭证：仅写入，永不回显；成功只返回 credentials_configured 布尔。
     * 审计由 IntegrationCredentialService::save 内部写入。
     */
    public function resetcredential()
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('id');
        $credentialsRaw = (string)$this->request->post('credentials', '');
        $credentials = json_decode($credentialsRaw, true);
        if (!is_array($credentials) || $credentials === [] || array_keys($credentials) === range(0, count($credentials) - 1)) {
            $this->error('凭证必须是 JSON 对象（键值对）', null, ['business_code' => 'CPQ_INVALID']);
        }
        try {
            (new IntegrationCredentialService())->save(['credentials' => $credentials], $id);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $this->success('凭证已重置', null, ['credentials_configured' => true]);
    }

    /**
     * 启用/停用接口配置。
     */
    public function toggle()
    {
        $this->assertWrite();
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $id = (int)$this->request->post('id');
        $status = trim((string)$this->request->post('status'));
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            $this->error('状态无效', null, ['business_code' => 'CPQ_INVALID']);
        }
        try {
            $saved = (new IntegrationCredentialService())->save(['status' => $status], $id);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $this->success($status === 'enabled' ? '已启用' : '已停用', null, $saved);
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * 保存基本信息（新增/编辑共用）。
     *
     * @param int|null $id
     */
    private function doSave($id)
    {
        $row = (array)$this->request->post('row/a', []);
        $input = array_intersect_key($row, array_flip([
            'code', 'name', 'system_type', 'base_url', 'auth_type',
            'hmac_algorithm', 'timeout_ms', 'max_retries', 'status',
        ]));
        try {
            $saved = (new IntegrationCredentialService())->save($input, $id);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        } catch (PDOException $exception) {
            $this->error('接口编码已存在或数据冲突', null, ['business_code' => 'CPQ_DUPLICATE']);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage(), null, ['business_code' => 'CPQ_INVALID']);
        }
        $this->success('保存成功', null, $saved);
    }

    /**
     * 批量净化：优先走服务层 publicConfig；未配置加密密钥时按同一语义本地剥离。
     *
     * @param array $rows
     * @return array
     */
    private function publicRows(array $rows)
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->publicRow($row);
        }
        return $out;
    }

    /**
     * @param array $row
     * @return array
     */
    private function publicRow(array $row)
    {
        try {
            return (new IntegrationCredentialService())->publicConfig($row);
        } catch (RuntimeException $exception) {
            // 未配置 CPQ_INTEGRATION_KEY 时仅做只读净化展示，写操作仍会报错
            $configured = !empty($row['credential_ciphertext']);
            foreach (array_keys($row) as $key) {
                if (strpos((string)$key, 'credential_') === 0) {
                    unset($row[$key]);
                }
            }
            $row['credentials_configured'] = $configured;
            return $row;
        }
    }

    /**
     * @return array
     */
    private function cpqRoles()
    {
        return SensitiveFieldService::rolesOfAdmin((int)$this->auth->id);
    }

    /**
     * @return bool
     */
    private function canWrite()
    {
        return (bool)array_intersect(self::WRITE_ROLES, $this->cpqRoles());
    }

    private function assertRead()
    {
        if (!array_intersect(self::READ_ROLES, $this->cpqRoles())) {
            $this->error('无权访问');
        }
    }

    private function assertWrite()
    {
        if (!$this->canWrite()) {
            $this->error('无权访问');
        }
    }
}
