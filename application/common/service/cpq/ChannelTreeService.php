<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/**
 * CPQ 渠道树服务：销售区域（cpq_region）与销售组织（cpq_sales_org）
 * 共用的树形主数据维护逻辑（GYTAI-69，方案 §3）。
 *
 * 职责：
 *  - 节点保存：父节点存在性、禁止挂到自身/后代（父链上溯 + 物化路径双判环）、
 *    物化路径 path 与 level 的重算（父变化时重建全部后代）；
 *  - 节点移动：等同于更新 parent_id 的保存路径；
 *  - 删除守卫：存在子节点不可删，再交由生命周期服务做业务引用检查；
 *  - 子树查询：基于物化路径前缀匹配。
 */
class ChannelTreeService
{
    /** 支持树化维护的逻辑表名 */
    const TABLES = ['cpq_region', 'cpq_sales_org'];

    /** 父链上溯判环的最大深度保护（防止脏数据导致死循环） */
    const MAX_DEPTH = 100;

    /** @var MasterDataLifecycleService */
    private $lifecycle;

    public function __construct(MasterDataLifecycleService $lifecycle = null)
    {
        $this->lifecycle = $lifecycle ?: new MasterDataLifecycleService();
    }

    /**
     * 保存节点（新增或更新）：校验父节点与环，重算自身及后代的 path/level。
     *
     * @param string   $table 逻辑表名（cpq_region / cpq_sales_org）
     * @param array    $data  节点数据（至少含 parent_id；新增时可带 code/name 等业务字段）
     * @param int|null $id    传入时为更新，否则为新增
     * @return int 节点 ID
     * @throws InvalidArgumentException
     */
    public function saveNode($table, array $data, $id = null)
    {
        $this->assertTable($table);
        $id = $id === null ? null : (int)$id;
        $parentId = (int)($data['parent_id'] ?? 0);

        Db::startTrans();
        try {
            $existing = null;
            if ($id !== null) {
                $existing = Db::name($table)->where('id', $id)->field('id,parent_id,path,level')->find();
                if (!$existing) {
                    throw new InvalidArgumentException('节点不存在');
                }
            }

            if ($parentId > 0) {
                $parentExists = Db::name($table)->where('id', $parentId)->count();
                if (!$parentExists) {
                    throw new InvalidArgumentException('父节点不存在');
                }
            }
            if ($existing !== null && $parentId > 0) {
                $this->assertNotCircular($table, $id, $parentId);
            }

            $now = time();
            $data['parent_id'] = $parentId;
            // path/level 由服务统一重算，不接受外部传入
            unset($data['id'], $data['path'], $data['level']);

            if ($existing === null) {
                // 先落库拿到自增 ID，再按父子关系重算路径
                $data['path'] = '/';
                $data['level'] = 1;
                $data['createtime'] = $now;
                $data['updatetime'] = $now;
                $nodeId = (int)Db::name($table)->insertGetId($data);
            } else {
                $data['updatetime'] = $now;
                Db::name($table)->where('id', $id)->update($data);
                $nodeId = $id;
            }

            // 重算自身 path/level，并向下重建全部后代（父未变化时为幂等操作）
            $this->rebuildSubtree($table, $nodeId);
            Db::commit();
            return $nodeId;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /**
     * 移动节点到新父节点下（含判环与整棵子树的路径重建）。
     *
     * @param string $table
     * @param int    $id
     * @param int    $newParentId 0 表示移动为根节点
     * @return int 节点 ID
     * @throws InvalidArgumentException
     */
    public function moveNode($table, $id, $newParentId)
    {
        return $this->saveNode($table, ['parent_id' => (int)$newParentId], $id);
    }

    /**
     * 断言节点可删除：存在子节点不可删；再做业务引用检查
     * （生命周期服务对 cpq_region / cpq_sales_org 的引用规则）。
     *
     * @param string $table
     * @param array  $ids 待删除节点 ID 列表（整棵子树一起删时传入全部节点）
     * @throws InvalidArgumentException
     */
    public function assertNodeDeletable($table, array $ids)
    {
        $this->assertTable($table);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return;
        }
        $children = Db::name($table)
            ->where('parent_id', 'in', $ids)
            ->where('id', 'not in', $ids)
            ->count();
        if ($children > 0) {
            throw new InvalidArgumentException('存在子节点，不能删除');
        }
        $rows = Db::name($table)->where('id', 'in', $ids)->select();
        $this->lifecycle->assertDeletable($table, $rows);
    }

    /**
     * 子树节点 ID 列表（含自身），基于物化路径前缀匹配。
     *
     * @param string $table
     * @param int    $id
     * @return int[]
     */
    public function subtreeIds($table, $id)
    {
        $this->assertTable($table);
        $id = (int)$id;
        $node = Db::name($table)->where('id', $id)->field('id,path')->find();
        if (!$node) {
            return [];
        }
        $ids = Db::name($table)
            ->where('path', 'like', $node['path'] . '%')
            ->column('id');
        $ids = array_map('intval', $ids);
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        sort($ids);
        return $ids;
    }

    // ------------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------------

    /**
     * @param string $table
     * @throws InvalidArgumentException
     */
    private function assertTable($table)
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('不支持的树形主数据表：' . (string)$table);
        }
    }

    /**
     * 判环：新父节点不能是节点自身或其后代。
     * 沿 parent 链上溯判环，同时用物化路径兜底（path 中含 /id/ 即为后代）。
     *
     * @param string $table
     * @param int    $id
     * @param int    $parentId
     * @throws InvalidArgumentException
     */
    private function assertNotCircular($table, $id, $parentId)
    {
        if ($parentId === $id) {
            throw new InvalidArgumentException('不能把父节点设置为节点自身');
        }
        $cursor = $parentId;
        $guard = 0;
        while ($cursor > 0 && $guard++ < self::MAX_DEPTH) {
            if ($cursor === $id) {
                throw new InvalidArgumentException('不能把父节点设置为自己的后代节点');
            }
            $cursor = (int)Db::name($table)->where('id', $cursor)->value('parent_id');
        }
        $parentPath = (string)Db::name($table)->where('id', $parentId)->value('path');
        if ($parentPath !== '' && strpos($parentPath, '/' . $id . '/') !== false) {
            throw new InvalidArgumentException('不能把父节点设置为自己的后代节点');
        }
    }

    /**
     * 重算节点自身 path/level，并递归重建全部后代（值未变化时不写库）。
     *
     * @param string $table
     * @param int    $id
     */
    private function rebuildSubtree($table, $id)
    {
        $node = Db::name($table)->where('id', $id)->field('id,parent_id,path,level')->find();
        if (!$node) {
            return;
        }
        $parentId = (int)$node['parent_id'];
        $path = '/' . $id . '/';
        $level = 1;
        if ($parentId > 0) {
            $parent = Db::name($table)->where('id', $parentId)->field('id,path,level')->find();
            if ($parent) {
                $path = $parent['path'] . $id . '/';
                $level = (int)$parent['level'] + 1;
            }
        }
        if ($path !== (string)$node['path'] || $level !== (int)$node['level']) {
            Db::name($table)->where('id', $id)->update([
                'path' => $path,
                'level' => $level,
                'updatetime' => time(),
            ]);
        }
        $children = Db::name($table)->where('parent_id', $id)->column('id');
        foreach ($children as $childId) {
            $this->rebuildSubtree($table, (int)$childId);
        }
    }
}
