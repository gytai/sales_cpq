<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/**
 * CPQ 价格发布版本服务（GYTAI-69，方案 P30/P37、§6.2）。
 *
 * 价格表/价格策略/价格规则每次发布生成一条 cpq_release_version 不可变
 * 版本记录：内容哈希（SHA-256，规范化 JSON）+ 版本号递增 + 计划/实际
 * 生效时间。已生效或已撤回的版本不可修改；回滚 = 用旧内容生成一个新
 * 的待生效版本（重新发布旧内容），而不是修改历史版本。
 */
class PriceReleaseService
{
    /** 支持发布版本管理的对象类型（逻辑表名） */
    const OBJECT_TYPES = ['cpq_price_book', 'cpq_price_policy', 'cpq_price_rule'];

    /** 价格表内容哈希并入的条目字段（按 id 排序取全量） */
    const BOOK_ENTRY_FIELDS = 'target_type,target_id,amount,unit,min_qty,max_qty';

    /** @var AuditLogService */
    private $audit;

    public function __construct(AuditLogService $audit = null)
    {
        $this->audit = $audit ?: new AuditLogService();
    }

    /**
     * 记录一次发布版本：同事务插入版本行 + 审计。
     *
     * 内容哈希：row 去掉 id/createtime/updatetime/status 后 ksort 的规范化
     * JSON；价格表额外并入其全部价格条目（按 id 排序、只取定价字段）。
     * 计划生效时间晚于当前时间 → pending（未生效），否则 published 且
     * effective_at 落当前时间。
     *
     * @param string   $objectType            对象逻辑表名
     * @param array    $row                   对象行数据（含 id）
     * @param string   $changeSummary         变更摘要
     * @param int|null $plannedEffectiveAt    计划生效时间戳
     * @param int      $affectedProductCount  影响产品数
     * @param int      $affectedCustomerCount 影响客户数
     * @param int      $submittedBy           提交人管理员ID（默认 0，操作人由审计表记录）
     * @return array 插入的版本行
     * @throws InvalidArgumentException
     */
    public function recordRelease($objectType, array $row, $changeSummary = '', $plannedEffectiveAt = null, $affectedProductCount = 0, $affectedCustomerCount = 0, $submittedBy = 0)
    {
        $this->assertObjectType($objectType);
        $objectId = (int)($row['id'] ?? 0);
        if ($objectId <= 0) {
            throw new InvalidArgumentException('发布对象缺少 ID');
        }

        $contentHash = $this->contentHash($objectType, $row);
        $now = time();
        $planned = $plannedEffectiveAt === null ? null : (int)$plannedEffectiveAt;
        $isPending = $planned !== null && $planned > $now;

        Db::startTrans();
        try {
            $version = (int)Db::name('cpq_release_version')
                ->where('object_type', $objectType)
                ->where('object_id', $objectId)
                ->max('version') + 1;

            $record = [
                'object_type' => $objectType,
                'object_id' => $objectId,
                'version' => $version,
                'content_hash' => $contentHash,
                'change_summary' => (string)$changeSummary,
                'affected_product_count' => (int)$affectedProductCount,
                'affected_customer_count' => (int)$affectedCustomerCount,
                'submitted_by' => (int)$submittedBy,
                'approved_by' => 0,
                'planned_effective_at' => $planned,
                'effective_at' => $isPending ? null : $now,
                'status' => $isPending ? 'pending' : 'published',
                'createtime' => $now,
                'updatetime' => $now,
            ];
            $record['id'] = (int)Db::name('cpq_release_version')->insertGetId($record);
            $this->audit->record(
                AuditLogService::ACTION_PUBLISH,
                'cpq_release_version',
                $record['id'],
                [
                    'object_type' => $objectType,
                    'object_id' => $objectId,
                    'version' => $version,
                    'content_hash' => $contentHash,
                ],
                (string)($row['code'] ?? '')
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $record;
    }

    /**
     * 已生效/已撤回的发布版本不可修改。
     *
     * @param array $release cpq_release_version 行
     * @throws InvalidArgumentException
     */
    public function assertImmutable(array $release)
    {
        if (in_array($release['status'] ?? '', ['published', 'withdrawn'], true)) {
            throw new InvalidArgumentException('已生效或已撤回的发布版本不可修改');
        }
    }

    /**
     * 撤回未生效的发布版本。
     *
     * @param int $id
     * @throws InvalidArgumentException
     */
    public function withdraw($id)
    {
        $release = Db::name('cpq_release_version')->where('id', (int)$id)->find();
        if (!$release) {
            throw new InvalidArgumentException('发布版本不存在');
        }
        if ($release['status'] !== 'pending') {
            throw new InvalidArgumentException('只有未生效的发布版本可以撤回');
        }

        Db::startTrans();
        try {
            Db::name('cpq_release_version')->where('id', (int)$id)->update([
                'status' => 'withdrawn',
                'updatetime' => time(),
            ]);
            $this->audit->record('withdraw', 'cpq_release_version', (int)$id, [
                'object_type' => (string)$release['object_type'],
                'object_id' => (int)$release['object_id'],
                'version' => (int)$release['version'],
            ]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /**
     * 创建回滚版本：仅已生效版本可回滚；回滚不是修改历史，而是用旧内容
     * （同一 content_hash）生成同对象的新版本（status=pending），生效后即
     * 等价于重新发布旧内容（方案 P37）。
     *
     * @param int    $id            源发布版本 ID
     * @param string $changeSummary 变更摘要，默认「回滚到版本N」
     * @return array 新版本行
     * @throws InvalidArgumentException
     */
    public function createRollback($id, $changeSummary = '')
    {
        $release = Db::name('cpq_release_version')->where('id', (int)$id)->find();
        if (!$release) {
            throw new InvalidArgumentException('发布版本不存在');
        }
        if ($release['status'] !== 'published') {
            throw new InvalidArgumentException('只有已生效的发布版本可以回滚');
        }
        $summary = trim((string)$changeSummary) !== ''
            ? (string)$changeSummary
            : '回滚到版本' . (int)$release['version'];

        Db::startTrans();
        try {
            $version = (int)Db::name('cpq_release_version')
                ->where('object_type', $release['object_type'])
                ->where('object_id', $release['object_id'])
                ->max('version') + 1;
            $now = time();
            $record = [
                'object_type' => $release['object_type'],
                'object_id' => $release['object_id'],
                'version' => $version,
                'content_hash' => $release['content_hash'],
                'change_summary' => $summary,
                'affected_product_count' => (int)$release['affected_product_count'],
                'affected_customer_count' => (int)$release['affected_customer_count'],
                'submitted_by' => 0,
                'approved_by' => 0,
                'planned_effective_at' => null,
                'effective_at' => null,
                'status' => 'pending',
                'createtime' => $now,
                'updatetime' => $now,
            ];
            $record['id'] = (int)Db::name('cpq_release_version')->insertGetId($record);
            $this->audit->record(AuditLogService::ACTION_COPY, 'cpq_release_version', $record['id'], [
                'object_type' => (string)$release['object_type'],
                'object_id' => (int)$release['object_id'],
                'rollback_from_version' => (int)$release['version'],
                'new_version' => $version,
            ]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return $record;
    }

    // ------------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------------

    /**
     * @param string $objectType
     * @throws InvalidArgumentException
     */
    private function assertObjectType($objectType)
    {
        if (!in_array($objectType, self::OBJECT_TYPES, true)) {
            throw new InvalidArgumentException('不支持的发布对象类型：' . (string)$objectType);
        }
    }

    /**
     * 规范化内容哈希：去元字段 + ksort；价格表并入其全部价格条目。
     *
     * @param string $objectType
     * @param array  $row
     * @return string
     */
    private function contentHash($objectType, array $row)
    {
        $content = $row;
        unset($content['id'], $content['createtime'], $content['updatetime'], $content['status']);
        if ($objectType === 'cpq_price_book') {
            $content['entries'] = Db::name('cpq_price_entry')
                ->where('price_book_id', (int)$row['id'])
                ->field(self::BOOK_ENTRY_FIELDS)
                ->order('id asc')
                ->select();
        }
        ksort($content);
        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE));
    }
}
