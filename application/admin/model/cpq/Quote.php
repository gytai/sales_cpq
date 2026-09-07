<?php

namespace app\admin\model\cpq;

use think\Model;

class Quote extends Model
{
    protected $name = 'cpq_quote';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $append = ['status_text'];

    /** 状态机常量（与 getStatusList 单一来源保持一致） */
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED = 'approved';
    const STATUS_SENT = 'sent';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_RETURNED = 'returned';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REVISED = 'revised';

    protected static function init()
    {
        self::beforeWrite(function ($row) {
            if (empty($row['code'])) {
                $row['code'] = self::generateCode();
            }
        });
    }

    public function getStatusList()
    {
        return [
            'draft' => '草稿',
            'submitted' => '已提交',
            'approved' => '已批准',
            'sent' => '已发送',
            'accepted' => '客户接受',
            'rejected' => '已驳回',
            'withdrawn' => '已撤回',
            'returned' => '已退回',
            'cancelled' => '已取消',
            'expired' => '已过期',
            'revised' => '已修订',
        ];
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? '';
        return $this->getStatusList()[$status] ?? $status;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function salesOrg()
    {
        return $this->belongsTo(SalesOrg::class, 'sales_org_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

    public function lines()
    {
        return $this->hasMany(QuoteLine::class, 'quote_id', 'id');
    }

    public function revisions()
    {
        return $this->hasMany(QuoteRevision::class, 'quote_id', 'id');
    }

    public function terms()
    {
        return $this->hasMany(QuoteTerm::class, 'quote_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(QuoteAttachment::class, 'quote_id', 'id');
    }

    /** 报价编码自动生成（数据库锁计数，避免并发重号）。 */
    public static function generateCode()
    {
        $service = new \app\common\service\cpq\NumberRuleService();
        $service->ensureRule('quote', '报价编号', 'Q-{YYYY}{MM}{DD}{SEQ3}', 'day', 1);
        return $service->next('quote');
    }

    /**
     * 可写状态白名单（草稿态及其衍生可修改态）。
     */
    public static function editableStatuses()
    {
        return ['draft', 'withdrawn', 'returned'];
    }

    /**
     * 只读状态（已提交、已批准、已发送等不可修改）。
     */
    public static function readonlyStatuses()
    {
        return ['submitted', 'approved', 'sent', 'accepted', 'rejected', 'cancelled', 'expired', 'revised'];
    }

    /**
     * 乐观锁检查。
     *
     * @param int $expectedVersion
     * @throws \InvalidArgumentException
     */
    public function assertOptimisticLock($expectedVersion)
    {
        $current = (int)$this->optimistic_lock_version;
        if ($current !== (int)$expectedVersion) {
            throw new \InvalidArgumentException(
                '报价已被其他人修改（乐观锁冲突：期望 ' . $expectedVersion . '，当前 ' . $current . '）'
            );
        }
    }

    /**
     * 递增乐观锁版本。
     */
    public function bumpOptimisticLock()
    {
        $this->optimistic_lock_version = (int)$this->optimistic_lock_version + 1;
    }
}
