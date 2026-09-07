<?php

namespace app\admin\library\traits;

use app\common\service\cpq\AuditLogService;
use app\common\service\cpq\MasterDataLifecycleService;
use InvalidArgumentException;
use think\Db;

/**
 * CPQ 版本化生命周期操作（需配合 CpqRelationIndex 一起使用）。
 *
 * 状态机（方案 §5.1）：
 *   draft → pending → published → expired（pending → draft 驳回在 M3 审批任务实现）
 *
 * 已发布/已失效版本不可编辑（编辑守卫在 CpqRelationIndex::edit 中），
 * 变更通过 copy（复制新版本）产生下一版本草稿；publish 发布新版本时
 * 自动接替同编码旧已发布版本。跨表校验与审计全部在
 * MasterDataLifecycleService 内完成。
 */
trait CpqVersioned
{
    public function submit($ids = null)
    {
        $this->cpqRequirePostRequest();
        $service = new MasterDataLifecycleService();
        $row = $this->cpqGetVersionedRow($ids);
        if (!$service->requiresApproval($this->cpqTable())) {
            $this->error('该类型无需提交审批，可直接发布草稿');
        }
        try {
            $this->cpqAssertRowScope($row->toArray());
            if (($row['status'] ?? '') !== 'draft') {
                throw new InvalidArgumentException('只有草稿可以提交审批');
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        Db::startTrans();
        try {
            $row->save(['status' => 'pending']);
            $this->cpqAudit(AuditLogService::ACTION_SUBMIT, $row->toArray(), [
                'code' => (string)($row['code'] ?? ''),
                'version' => (int)($row['version'] ?? 1),
            ]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            $this->error($exception->getMessage());
        }
        $this->success('提交成功');
    }

    public function publish($ids = null)
    {
        $this->cpqRequirePostRequest();
        $row = $this->cpqGetVersionedRow($ids);
        try {
            (new MasterDataLifecycleService())->publish($row, $this->cpqScope());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('发布成功');
    }

    /**
     * 停用（失效）已发布版本。
     */
    public function expire($ids = null)
    {
        $this->cpqRequirePostRequest();
        $row = $this->cpqGetVersionedRow($ids);
        try {
            (new MasterDataLifecycleService())->expire($row, $this->cpqScope());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('已停用');
    }

    /**
     * 复制新版本：把已发布/已失效版本复制为下一版本草稿。
     */
    public function copy($ids = null)
    {
        $this->cpqRequirePostRequest();
        $row = $this->cpqGetVersionedRow($ids);
        try {
            $newRow = (new MasterDataLifecycleService())->copyNewVersion($row, $this->cpqScope());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
        }
        $this->success('已复制为新版本草稿', null, [
            'id' => (int)$newRow['id'],
            'version' => (int)$newRow['version'],
        ]);
    }

    /**
     * 仅允许 POST 触发状态迁移。
     */
    protected function cpqRequirePostRequest()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
    }

    /**
     * 读取单条版本化记录（拒绝批量操作）。
     *
     * @param int|string|null $ids
     * @return \think\Model
     */
    protected function cpqGetVersionedRow($ids)
    {
        $id = $ids ?: $this->request->post('ids');
        if (!$id || strpos((string)$id, ',') !== false) {
            $this->error('请选择一条记录');
        }
        $row = $this->model->get($id);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        return $row;
    }
}
