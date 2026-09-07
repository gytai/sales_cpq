<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use think\Db;

/**
 * CPQ 产品线数据范围服务（方案 §2 / §2.1）。
 *
 * 范围语义（服务端强校验，不依赖前端按钮隐藏）：
 *  - 超级管理员（所在用户组 rules='*'）：不限制；
 *  - cpq_admin_product_line 中存在 `*` 记录：不限制；
 *  - 存在具体产品线记录：仅允许这些产品线；
 *  - 无任何记录且非超级管理员：不允许访问任何产品线数据（fail-closed）。
 *
 * 空产品线（''，未指定）的数据只有不受限管理员可以维护。
 */
class ProductLineScopeService
{
    /** 全部产品线通配符 */
    const WILDCARD = '*';

    /** @var int|null */
    private $adminId;
    /** @var array|null null=不限制，数组=仅允许列表内的产品线 */
    private $allowedLines;

    /**
     * 页面筛选用的可选产品线：已发布产品系列的产品线编码，受限账号按授权范围过滤。
     *
     * @param int $adminId
     * @return array
     */
    public static function productLineOptionsFor($adminId)
    {
        $lines = Db::name('cpq_product_series')
            ->where('status', 'published')
            ->group('product_line')
            ->column('product_line');
        $scope = self::forAdmin($adminId);
        if (!$scope->isUnrestricted()) {
            $lines = array_values(array_intersect($lines, $scope->getAllowedLines() ?: []));
        }
        return array_values(array_filter(array_map('strval', $lines), function ($line) {
            return $line !== '';
        }));
    }

    /**
     * 构造指定管理员的数据范围。
     *
     * @param int $adminId
     * @param bool|null $isSuperAdmin 传入 true/false 时跳过数据库判定（后台会话已有结论）
     */
    public function __construct($adminId, $isSuperAdmin = null)
    {
        $this->adminId = (int)$adminId;
        if ($isSuperAdmin === null) {
            $isSuperAdmin = $this->detectSuperAdmin();
        }
        $this->allowedLines = $isSuperAdmin ? null : $this->loadAssignedLines();
    }

    /**
     * @param int $adminId
     * @param bool|null $isSuperAdmin
     * @return self
     */
    public static function forAdmin($adminId, $isSuperAdmin = null)
    {
        return new self($adminId, $isSuperAdmin);
    }

    /**
     * 是否不受产品线限制。
     *
     * @return bool
     */
    public function isUnrestricted()
    {
        return $this->allowedLines === null;
    }

    /**
     * 允许的产品线列表；不受限时返回 null。
     *
     * @return array|null
     */
    public function getAllowedLines()
    {
        return $this->allowedLines;
    }

    /**
     * 指定产品线是否在范围内。
     *
     * @param string $productLine
     * @return bool
     */
    public function isLineAllowed($productLine)
    {
        if ($this->allowedLines === null) {
            return true;
        }
        $productLine = trim((string)$productLine);
        return $productLine !== '' && in_array($productLine, $this->allowedLines, true);
    }

    /**
     * 断言产品线在范围内，越权抛出业务异常。
     *
     * @param string $productLine
     * @param string $message
     * @throws InvalidArgumentException
     */
    public function assertLineAllowed($productLine, $message = '无该产品线的数据权限')
    {
        if (!$this->isLineAllowed($productLine)) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * 根据管理员用户组判定是否超级管理员（rules='*'）。
     *
     * @return bool
     */
    private function detectSuperAdmin()
    {
        if ($this->adminId <= 0) {
            return false;
        }
        $count = Db::name('auth_group_access')
            ->alias('access')
            ->join('__AUTH_GROUP__ auth_group', 'auth_group.id = access.group_id')
            ->where('access.uid', $this->adminId)
            ->where('auth_group.rules', '*')
            ->where('auth_group.status', 'normal')
            ->count();
        return $count > 0;
    }

    /**
     * 读取管理员被授权的产品线；含 `*` 记录时不限制。
     *
     * @return array|null
     */
    private function loadAssignedLines()
    {
        if ($this->adminId <= 0) {
            return [];
        }
        $lines = Db::name('cpq_admin_product_line')
            ->where('admin_id', $this->adminId)
            ->column('product_line');
        if (in_array(self::WILDCARD, $lines, true)) {
            return null;
        }
        $lines = array_values(array_unique(array_filter(array_map('trim', $lines), function ($line) {
            return $line !== '';
        })));
        return $lines;
    }
}
