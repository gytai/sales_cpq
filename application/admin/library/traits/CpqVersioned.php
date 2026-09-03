<?php

namespace app\admin\library\traits;

use think\Db;

trait CpqVersioned
{
    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if (in_array($row['status'], ['published', 'expired'], true)) {
            $this->error('已发布或已失效版本不可直接修改，请复制新版本');
        }
        return parent::edit($ids);
    }

    public function del($ids = null)
    {
        $ids = $ids ?: $this->request->post('ids');
        $rows = $this->model->where($this->model->getPk(), 'in', $ids)->select();
        foreach ($rows as $row) {
            if ($row['status'] !== 'draft') {
                $this->error('只有草稿版本可以删除');
            }
        }
        return parent::del($ids);
    }

    public function submit($ids = null)
    {
        $this->requirePostRequest();
        if (property_exists($this, 'cpqRequiresApproval') && !$this->cpqRequiresApproval) {
            $this->error('该类型无需提交审批，可直接发布草稿');
        }
        $row = $this->getVersionedRow($ids);
        if ($row['status'] !== 'draft') {
            $this->error('只有草稿可以提交审批');
        }
        $row->save(['status' => 'pending']);
        $this->success('提交成功');
    }

    public function publish($ids = null)
    {
        $this->requirePostRequest();
        $row = $this->getVersionedRow($ids);
        $requiresApproval = !property_exists($this, 'cpqRequiresApproval') || $this->cpqRequiresApproval;
        $publishableStatus = $requiresApproval ? 'pending' : 'draft';
        if ($row['status'] !== $publishableStatus) {
            $this->error($requiresApproval ? '只有待审批版本可以发布' : '只有草稿版本可以发布');
        }
        if (method_exists($this, 'validateBeforePublish')) {
            $this->validateBeforePublish($row);
        }

        Db::startTrans();
        try {
            $row->save(['status' => 'published']);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            $this->error($exception->getMessage());
        }
        $this->success('发布成功');
    }

    private function requirePostRequest()
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
    }

    private function getVersionedRow($ids)
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
