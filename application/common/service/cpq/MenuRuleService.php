<?php

namespace app\common\service\cpq;

use think\Cache;
use think\Db;
use think\db\Connection;

/**
 * CPQ 后台菜单与权限规则安装（幂等 upsert）。
 *
 * 由 install 命令（全新安装）与 cpq:menu 命令（已有环境同步菜单）共用；
 * 连接可注入：CLI 安装流程中默认连接仍指向旧配置时，必须传入显式连接。
 */
class MenuRuleService
{
    /** @var Connection */
    private $connection;

    public function __construct(Connection $connection = null)
    {
        $this->connection = $connection ?: Db::connect();
    }

    public function install()
    {
        $now = time();
        $rootId = $this->upsertMenuRule([
            'pid' => 0,
            'name' => 'cpq',
            'title' => 'CPQ产品中心',
            'icon' => 'fa fa-cubes',
            'ismenu' => 1,
            'weigh' => 80,
        ], $now);

        // 版本化控制器公共动作：详情/提交/发布/停用/复制新版本
        $versionedActions = ['index' => '查看', 'detail' => '详情', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新', 'submit' => '提交', 'publish' => '发布', 'expire' => '停用', 'copy' => '复制新版本'];
        $simpleActions = ['index' => '查看', 'detail' => '详情', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新'];

        $controllers = [
            'cpq/product_series' => ['产品系列', 'fa fa-cubes', 100, $versionedActions],
            'cpq/product_model' => ['产品型号', 'fa fa-cube', 90, $versionedActions],
            'cpq/parameter_definition' => ['参数定义', 'fa fa-list-alt', 85, $simpleActions],
            'cpq/model_parameter' => ['型号参数', 'fa fa-link', 80, $simpleActions],
            'cpq/option_group' => ['配置组', 'fa fa-list-ul', 75, $simpleActions],
            'cpq/option_value' => ['配置选项', 'fa fa-check-square-o', 70, $simpleActions],
            'cpq/model_option_group' => ['型号配置结构', 'fa fa-sitemap', 65, $simpleActions],
            'cpq/config_rule' => ['配置规则', 'fa fa-random', 60, array_merge($versionedActions, ['testrule' => '单规则测试', 'analyze' => '冲突检测'])],
            'cpq/config_template' => ['推荐配置模板', 'fa fa-clone', 55, $versionedActions],
            'cpq/accessory_service' => ['配件与服务', 'fa fa-wrench', 50, $simpleActions],
            'cpq/bom_mapping' => ['BOM映射', 'fa fa-cubes', 45, ['index' => '查看', 'detail' => '详情', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新', 'publish' => '发布', 'expire' => '停用', 'copy' => '复制新版本']],
            'cpq/configurator' => ['产品配置器', 'fa fa-sliders', 40, ['index' => '查看', 'schema' => '读取配置结构', 'validateconfiguration' => '校验配置', 'bom' => 'BOM模拟']],
        ];

        foreach ($controllers as $controller => $definition) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $rootId,
                'name' => $controller,
                'title' => $definition[0],
                'icon' => $definition[1],
                'ismenu' => 1,
                'weigh' => $definition[2],
            ], $now);
            foreach ($definition[3] as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }

        // M2：CPQ价格中心
        $priceRootId = $this->upsertMenuRule([
            'pid' => 0,
            'name' => 'cpq_price',
            'title' => 'CPQ价格中心',
            'icon' => 'fa fa-rmb',
            'ismenu' => 1,
            'weigh' => 79,
        ], $now);
        $simpleActions = ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新'];
        $priceControllers = [
            'cpq/price_book' => ['价格表', 'fa fa-table', 100, array_merge($versionedActions, ['coverage' => '覆盖缺口'])],
            'cpq/price_entry' => ['价格条目', 'fa fa-list', 95, array_merge($simpleActions, ['importpreview' => '导入预览'])],
            'cpq/price_policy' => ['价格策略', 'fa fa-shield', 90, array_merge($versionedActions, ['importpreview' => '导入预览'])],
            'cpq/price_rule' => ['价格规则', 'fa fa-random', 85, $versionedActions],
            'cpq/exchange_rate' => ['汇率', 'fa fa-exchange', 80, array_merge($simpleActions, ['importpreview' => '导入预览'])],
            'cpq/tax_rule' => ['税率', 'fa fa-percent', 75, array_merge($simpleActions, ['importpreview' => '导入预览'])],
            'cpq/fee_rule' => ['费用规则', 'fa fa-truck', 70, array_merge($simpleActions, ['importpreview' => '导入预览'])],
            'cpq/release_version' => ['发布版本', 'fa fa-tags', 65, ['index' => '查看', 'withdraw' => '撤回', 'rollback' => '回滚发布']],
            // 价格模拟器（P36）：页面视图由 GYTAI-74 交付，菜单可见
            'cpq/pricing' => ['价格模拟器', 'fa fa-calculator', 60, ['index' => '查看', 'calculate' => '价格试算', 'explain' => '价格解释']],
        ];
        foreach ($priceControllers as $controller => $definition) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $priceRootId,
                'name' => $controller,
                'title' => $definition[0],
                'icon' => $definition[1],
                'ismenu' => 1,
                'weigh' => $definition[2],
            ], $now);
            foreach ($definition[3] as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }

        // M2：CPQ客户与渠道
        $channelRootId = $this->upsertMenuRule([
            'pid' => 0,
            'name' => 'cpq_channel',
            'title' => 'CPQ客户与渠道',
            'icon' => 'fa fa-users',
            'ismenu' => 1,
            'weigh' => 78,
        ], $now);
        $channelControllers = [
            'cpq/customer' => ['客户', 'fa fa-user', 100, array_merge($simpleActions, ['detail' => '详情', 'importpreview' => '导入预览'])],
            'cpq/customer_level' => ['客户等级', 'fa fa-star', 95, $simpleActions],
            'cpq/agent' => ['代理商', 'fa fa-handshake-o', 90, array_merge($simpleActions, ['detail' => '详情'])],
            'cpq/agent_level' => ['代理等级', 'fa fa-star-half-o', 85, $simpleActions],
            'cpq/region' => ['销售区域', 'fa fa-map-marker', 80, array_merge($simpleActions, ['move' => '移动节点'])],
            'cpq/sales_org' => ['销售组织', 'fa fa-sitemap', 75, array_merge($simpleActions, ['detail' => '详情', 'move' => '移动节点'])],
        ];
        // 销售组织成员：挂在销售组织详情页内的子表，注册隐藏权限节点（不进菜单）
        $channelHiddenControllers = [
            'cpq/sales_org_member' => $simpleActions,
        ];
        foreach ($channelControllers as $controller => $definition) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $channelRootId,
                'name' => $controller,
                'title' => $definition[0],
                'icon' => $definition[1],
                'ismenu' => 1,
                'weigh' => $definition[2],
            ], $now);
            foreach ($definition[3] as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }
        foreach ($channelHiddenControllers as $controller => $actions) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $channelRootId,
                'name' => $controller,
                'title' => '销售组织成员',
                'icon' => 'fa fa-circle-o',
                'ismenu' => 0,
                'weigh' => 0,
            ], $now);
            foreach ($actions as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }

        // M2：CPQ报价中心（GYTAI-70）
        $quoteRootId = $this->upsertMenuRule([
            'pid' => 0,
            'name' => 'cpq_quote_center',
            'title' => 'CPQ报价中心',
            'icon' => 'fa fa-file-text-o',
            'ismenu' => 1,
            'weigh' => 77,
        ], $now);
        $quoteControllers = [
            'cpq/quote' => ['报价管理', 'fa fa-file-text-o', 100, [
                'index' => '查看', 'wizard' => '报价向导', 'detail' => '详情', 'diff' => '版本差异',
                'save' => '保存草稿', 'recalculate' => '服务端试算', 'submit' => '提交',
                'withdraw' => '撤回', 'copy' => '复制', 'revision' => '创建修订', 'diffdata' => '差异数据',
            ]],
            // M3：报价模板与打印记录（GYTAI-75）
            'cpq/quote_template' => ['报价模板', 'fa fa-file-pdf-o', 90, [
                'index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新',
                'preview' => '模板预览', 'publish' => '发布', 'copy' => '复制新版本',
                'setdefault' => '设为市场默认', 'disable' => '停用',
            ]],
            'cpq/quote_document' => ['报价打印记录', 'fa fa-print', 80, [
                'index' => '查看', 'generate' => '生成PDF', 'download' => '受控下载',
                'verify' => '哈希验证', 'retry' => '失败重试', 'quotes' => '可打印报价候选',
            ]],
        ];
        foreach ($quoteControllers as $controller => $definition) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $quoteRootId,
                'name' => $controller,
                'title' => $definition[0],
                'icon' => $definition[1],
                'ismenu' => 1,
                'weigh' => $definition[2],
            ], $now);
            foreach ($definition[3] as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }

        // M3：CPQ审批中心（GYTAI-75：待办/审批/规则/委托）
        $approvalRootId = $this->upsertMenuRule([
            'pid' => 0,
            'name' => 'cpq_approval_center',
            'title' => 'CPQ审批中心',
            'icon' => 'fa fa-check-square-o',
            'ismenu' => 1,
            'weigh' => 76,
        ], $now);
        $approvalControllers = [
            'cpq/todo' => ['我的待办', 'fa fa-list-ul', 100, [
                'index' => '查看', 'batchtransfer' => '批量转交', 'urge' => '催办',
            ]],
            'cpq/approval_task' => ['我的待审批', 'fa fa-check-square-o', 90, [
                'index' => '查看', 'detail' => '审批详情', 'action' => '审批动作',
                'urge' => '催办', 'candidates' => '候选人查询',
            ]],
            'cpq/approval_instance' => ['我发起的', 'fa fa-paper-plane-o', 80, [
                'index' => '查看', 'flow' => '完整流程', 'withdraw' => '撤回',
            ]],
            'cpq/approval_record' => ['审批记录', 'fa fa-history', 70, [
                'index' => '查看',
            ]],
            'cpq/approval_rule' => ['审批规则', 'fa fa-random', 60, [
                'index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除',
                'multi' => '批量更新', 'toggle' => '启停', 'simulate' => '规则模拟',
            ]],
            'cpq/approval_delegation' => ['委托与代理', 'fa fa-user-plus', 50, [
                'index' => '查看', 'create' => '新增委托', 'approve' => '审批委托',
                'cancel' => '撤销委托', 'actions' => '代理操作记录', 'candidates' => '代理人候选',
            ]],
        ];
        foreach ($approvalControllers as $controller => $definition) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $approvalRootId,
                'name' => $controller,
                'title' => $definition[0],
                'icon' => $definition[1],
                'ismenu' => 1,
                'weigh' => $definition[2],
            ], $now);
            foreach ($definition[3] as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }

        // M4：CPQ报表中心（GYTAI-78：驾驶舱/漏斗/折扣毛利/审批效率/配置分析/导出）
        $reportRootId = $this->upsertMenuRule([
            'pid' => 0,
            'name' => 'cpq_report_center',
            'title' => 'CPQ报表中心',
            'icon' => 'fa fa-bar-chart',
            'ismenu' => 1,
            'weigh' => 75,
        ], $now);
        $reportControllers = [
            'cpq/dashboard' => ['销售驾驶舱', 'fa fa-dashboard', 110, [
                'index' => '查看',
            ]],
            'cpq/report_quote' => ['报价漏斗', 'fa fa-filter', 100, [
                'index' => '查看',
            ]],
            'cpq/report_pricing' => ['折扣与毛利', 'fa fa-percent', 90, [
                'index' => '查看',
            ]],
            'cpq/report_approval' => ['审批效率', 'fa fa-clock-o', 80, [
                'index' => '查看',
            ]],
            'cpq/report_configuration' => ['配置分析', 'fa fa-cubes', 70, [
                'index' => '查看',
            ]],
            'cpq/report_export' => ['报价导出', 'fa fa-file-excel-o', 60, [
                'index' => '查看', 'create' => '创建导出', 'status' => '任务状态',
                'claim' => '领取下载令牌', 'download' => '下载文件',
            ]],
        ];
        foreach ($reportControllers as $controller => $definition) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $reportRootId,
                'name' => $controller,
                'title' => $definition[0],
                'icon' => $definition[1],
                'ismenu' => 1,
                'weigh' => $definition[2],
            ], $now);
            foreach ($definition[3] as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }

        // M4：CPQ系统管理（GYTAI-78：P100数据范围/P101字典/P102编号规则/P104接口/P105任务/P106审计）
        // P103 打印模板复用报价中心已有的 cpq/quote_template 菜单，不重复注册
        $systemRootId = $this->upsertMenuRule([
            'pid' => 0,
            'name' => 'cpq_system',
            'title' => 'CPQ系统管理',
            'icon' => 'fa fa-cogs',
            'ismenu' => 1,
            'weigh' => 74,
        ], $now);
        $systemControllers = [
            'cpq/data_scope' => ['数据范围授权', 'fa fa-shield', 100, [
                'index' => '查看', 'detail' => '有效范围预览', 'orgs' => '组织下拉',
                'grantline' => '授予产品线', 'revokeline' => '撤销产品线',
                'grantorg' => '授予销售组织', 'revokeorg' => '撤销销售组织',
            ]],
            'cpq/dictionary' => ['字典与参数', 'fa fa-book', 90, [
                'index' => '查看', 'add' => '新增', 'edit' => '编辑',
                'del' => '删除', 'multi' => '批量更新', 'toggle' => '启停',
            ]],
            'cpq/number_rule' => ['编号规则', 'fa fa-sort-numeric-asc', 80, [
                'index' => '查看', 'add' => '新增', 'edit' => '编辑',
                'preview' => '编号试算', 'counters' => '计数器查看',
            ]],
            'cpq/integration_config' => ['接口管理', 'fa fa-plug', 70, [
                'index' => '查看', 'add' => '新增', 'edit' => '编辑', 'save' => '保存',
                'resetcredential' => '重置凭证', 'toggle' => '启停',
            ]],
            'cpq/job' => ['异步任务', 'fa fa-tasks', 60, [
                'index' => '查看', 'detail' => '详情', 'retry' => '失败重试',
            ]],
            'cpq/audit_log' => ['审计日志', 'fa fa-history', 50, [
                'index' => '查看', 'detail' => '详情',
            ]],
        ];
        foreach ($systemControllers as $controller => $definition) {
            $controllerId = $this->upsertMenuRule([
                'pid' => $systemRootId,
                'name' => $controller,
                'title' => $definition[0],
                'icon' => $definition[1],
                'ismenu' => 1,
                'weigh' => $definition[2],
            ], $now);
            foreach ($definition[3] as $action => $title) {
                $this->upsertMenuRule([
                    'pid' => $controllerId,
                    'name' => $controller . '/' . $action,
                    'title' => $title,
                    'icon' => 'fa fa-circle-o',
                    'ismenu' => 0,
                    'weigh' => 0,
                ], $now);
            }
        }

        $this->connection->name('auth_rule')->where('name', 'in', [
            'cpq/configurator/add',
            'cpq/configurator/edit',
            'cpq/configurator/del',
            'cpq/configurator/multi',
        ])->delete();
        Cache::rm('__menu__');
    }

    private function upsertMenuRule(array $rule, $now)
    {
        $existing = $this->connection->name('auth_rule')->where('name', $rule['name'])->find();
        $values = array_merge([
            'type' => 'file',
            'pid' => 0,
            'title' => '',
            'icon' => 'fa fa-circle-o',
            'url' => '',
            'condition' => '',
            'remark' => '',
            'ismenu' => 0,
            'menutype' => null,
            'extend' => '',
            'py' => '',
            'pinyin' => '',
            'weigh' => 0,
            'status' => 'normal',
            'updatetime' => $now,
        ], $rule);

        if ($existing) {
            $this->connection->name('auth_rule')->where('id', $existing['id'])->update($values);
            return (int)$existing['id'];
        }
        $values['createtime'] = $now;
        return (int)$this->connection->name('auth_rule')->insertGetId($values);
    }
}
