<?php

namespace app\common\service\cpq;

use think\Db;

/** 定时发布、报价过期与集成失败重试的幂等调度器。 */
class SchedulerService
{
    public function run($now = null, $quoteValidDays = 30, $limit = 100)
    {
        $now = $now === null ? time() : (int)$now;
        return [
            'published' => $this->publishDue($now, $limit),
            'quotes_expired' => $this->expireQuotes($now, $quoteValidDays, $limit),
            'integration_requeued' => $this->requeueIntegrations($now, $limit),
        ];
    }

    private function publishDue($now, $limit)
    {
        $rows = Db::name('cpq_release_version')->where('status', 'pending')->where('planned_effective_at', '<=', $now)->order('id asc')->limit((int)$limit)->select();
        $count = 0;
        foreach ($rows as $row) {
            Db::startTrans();
            try {
                $changed = Db::name('cpq_release_version')->where('id', (int)$row['id'])->where('status', 'pending')->update(['status'=>'published','effective_at'=>$now,'updatetime'=>$now]);
                if ($changed && in_array($row['object_type'], PriceReleaseService::OBJECT_TYPES, true)) {
                    Db::name($row['object_type'])->where('id', (int)$row['object_id'])->update(['status'=>'published','updatetime'=>$now]);
                    (new AuditLogService())->record('scheduled_publish', 'cpq_release_version', (int)$row['id'], ['object_type'=>$row['object_type'],'object_id'=>(int)$row['object_id']]);
                    $count++;
                }
                Db::commit();
            } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        }
        return $count;
    }

    private function expireQuotes($now, $days, $limit)
    {
        $cutoff = $now - max(1, (int)$days) * 86400;
        $ids = Db::name('cpq_quote')->where('status', 'in', ['approved','sent'])->where('submitted_at', '<=', $cutoff)->order('id asc')->limit((int)$limit)->column('id');
        if (!$ids) return 0;
        $changed = Db::name('cpq_quote')->where('id', 'in', $ids)->where('status', 'in', ['approved','sent'])->update(['status'=>'expired','updatetime'=>$now]);
        foreach ($ids as $id) (new AuditLogService())->record('scheduled_expire', 'cpq_quote', (int)$id, ['valid_days'=>(int)$days]);
        return (int)$changed;
    }

    private function requeueIntegrations($now, $limit)
    {
        $ids = Db::name('cpq_integration_event')->where('status', 'failed')->where('next_retry_at', '<=', $now)->order('id asc')->limit((int)$limit)->column('id');
        if (!$ids) return 0;
        return (int)Db::name('cpq_integration_event')->where('id', 'in', $ids)->where('status', 'failed')->update(['status'=>'pending','updatetime'=>$now]);
    }
}
