<?php

namespace app\common\service\cpq;

use think\Db;

/**
 * CPQ 敏感字段服务（GYTAI-69，方案 §2.2 / P31）。
 *
 * 成本（cost）与公司控制价（company_floor）按角色做字段级脱敏：
 *  - 全量可见：公司定价/财务复核/审计/系统管理员/主数据管理员；
 *  - 产线可见：产线定价（可见成本与产线控制价，不可见公司控制价）；
 *  - 其余角色（销售等）：仅可见指导价与产线控制价；
 * 产线控制价（line_floor）全角色可见，不做脱敏。
 *
 * 凡可见敏感字段的角色，其查看/导出行为必须写审计（由调用方在
 * requiresAudit 为 true 时调用 recordAccess）。
 */
class SensitiveFieldService
{
    /** 可见 cost + line_floor + company_floor 的角色 */
    const FULL_ACCESS_ROLES = ['company_pricer', 'finance_reviewer', 'auditor', 'system_admin', 'master_data_admin'];

    /** 可见 cost + line_floor（不可见 company_floor）的角色 */
    const LINE_ACCESS_ROLES = ['line_pricer'];

    /** 产线定价角色需要递归隐藏的字段（公司层控制价）。 */
    const LINE_HIDDEN_FIELDS = ['company_floor', 'company_control_price'];

    /** 其余角色需要递归隐藏的全部成本/毛利/公司控制价字段。 */
    const RESTRICTED_HIDDEN_FIELDS = [
        'cost', 'cost_total', 'unit_cost', 'cost_amount',
        'company_floor', 'company_control_price',
        'margin_amount', 'margin_rate',
        'gross_margin', 'gross_margin_amount', 'gross_margin_rate',
    ];

    /** 参与角色映射的 CPQ 角色编码（与 fa_auth_group.name 精确匹配） */
    const CPQ_ROLES = [
        'sales', 'sales_manager', 'product_manager', 'line_pricer',
        'company_pricer', 'finance_reviewer', 'master_data_admin',
        'auditor', 'system_admin',
    ];

    /** @var AuditLogService */
    private $audit;

    public function __construct(AuditLogService $audit = null)
    {
        $this->audit = $audit ?: new AuditLogService();
    }

    /**
     * 按角色对行数据脱敏：递归移除（unset）无权限的敏感字段。
     * 支持单行（关联数组）、多行（行列表）与任意深度的嵌套结构；
     * 产线控制价（line_floor）全角色保留。
     *
     * @param array $rows  单行或多行数据
     * @param array $roles 当前用户角色编码集合
     * @return array
     */
    public function maskRows(array $rows, array $roles)
    {
        if (!$rows || array_intersect($roles, self::FULL_ACCESS_ROLES)) {
            return $rows;
        }
        $hidden = array_intersect($roles, self::LINE_ACCESS_ROLES)
            ? self::LINE_HIDDEN_FIELDS
            : self::RESTRICTED_HIDDEN_FIELDS;
        return $this->maskRecursive($rows, $hidden);
    }

    /**
     * 递归删除隐藏字段（按引用不可行，返回脱敏后的新数组）。
     *
     * @param array $data
     * @param array $hidden
     * @return array
     */
    private function maskRecursive(array $data, array $hidden)
    {
        foreach ($data as $key => $value) {
            // 大小写不敏感匹配，防止写入方引入 unitCost 等变体键绕过脱敏。
            if (in_array(strtolower((string)$key), $hidden, true)) {
                unset($data[$key]);
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->maskRecursive($value, $hidden);
            }
        }
        return $data;
    }

    /**
     * 当前角色集合是否可见任一敏感字段（可见时查看/导出须审计）。
     *
     * @param array $roles
     * @return bool
     */
    public function requiresAudit(array $roles)
    {
        return (bool)array_intersect($roles, array_merge(self::FULL_ACCESS_ROLES, self::LINE_ACCESS_ROLES));
    }

    /**
     * 记录敏感字段的查看/导出审计（不写敏感值本身，只写可见字段清单）。
     *
     * @param string $action     'view_sensitive' 或 'export'
     * @param string $objectType 对象逻辑表名
     * @param array  $objectIds  涉及的对象 ID 列表
     * @param array  $roles      当前用户角色编码集合
     * @return int 审计记录 ID
     */
    public function recordAccess($action, $objectType, array $objectIds, array $roles)
    {
        return $this->audit->record($action, $objectType, 0, [
            'object_ids' => array_map('intval', $objectIds),
            'visible_fields' => $this->visibleFields($roles),
            'roles' => array_values($roles),
        ], '');
    }

    /**
     * 管理员 → CPQ 角色编码集合：超管组（rules='*'）返回 ['system_admin']；
     * 否则取用户组名与 CPQ 角色编码精确匹配的集合；无匹配返回 []（最低权限）。
     *
     * @param int $adminId
     * @return array
     */
    public static function rolesOfAdmin($adminId)
    {
        $adminId = (int)$adminId;
        if ($adminId <= 0) {
            return [];
        }
        $groups = Db::name('auth_group_access')
            ->alias('access')
            ->join('__AUTH_GROUP__ auth_group', 'auth_group.id = access.group_id')
            ->where('access.uid', $adminId)
            ->where('auth_group.status', 'normal')
            ->field('auth_group.name,auth_group.rules')
            ->select();
        $roles = [];
        foreach ($groups as $group) {
            if (($group['rules'] ?? '') === '*') {
                return ['system_admin'];
            }
            $name = trim((string)($group['name'] ?? ''));
            if (in_array($name, self::CPQ_ROLES, true)) {
                $roles[] = $name;
            }
        }
        return array_values(array_unique($roles));
    }

    /**
     * 角色集合 → 可见的敏感字段清单（用于审计明细）。
     *
     * @param array $roles
     * @return array
     */
    private function visibleFields(array $roles)
    {
        if (array_intersect($roles, self::FULL_ACCESS_ROLES)) {
            return ['cost', 'line_floor', 'company_floor'];
        }
        if (array_intersect($roles, self::LINE_ACCESS_ROLES)) {
            return ['cost', 'line_floor'];
        }
        return ['line_floor'];
    }
}
