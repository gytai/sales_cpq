<?php

namespace app\admin\command;

use think\Config;
use think\Cache;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\Db;
use think\Exception;

class CpqInstall extends Command
{
    protected function configure()
    {
        $this
            ->setName('cpq:install')
            ->addOption('demo', null, Option::VALUE_NONE, 'Install CPQ demo data')
            ->setDescription('Install CPQ database tables');
    }

    protected function execute(Input $input, Output $output)
    {
        $installFile = ROOT_PATH . 'database' . DS . 'cpq' . DS . 'install.sql';
        if (!is_file($installFile)) {
            throw new Exception('CPQ 安装脚本不存在');
        }

        $sql = file_get_contents($installFile);
        if ($sql === false || trim($sql) === '') {
            throw new Exception('CPQ 安装脚本为空');
        }

        $prefix = (string)Config::get('database.prefix');
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new Exception('数据库表前缀不合法');
        }

        $sql = str_replace('__PREFIX__', $prefix, $sql);
        $connection = Db::connect();
        $connection->execute('SELECT 1');
        $connection->getPdo()->exec($sql);
        $this->installMenuRules();

        $output->info('CPQ database tables installed successfully.');

        if ($input->getOption('demo')) {
            $demoFile = ROOT_PATH . 'database' . DS . 'cpq' . DS . 'demo.sql';
            if (!is_file($demoFile)) {
                throw new Exception('CPQ 演示数据脚本不存在');
            }
            $demoSql = file_get_contents($demoFile);
            if ($demoSql === false || trim($demoSql) === '') {
                throw new Exception('CPQ 演示数据脚本为空');
            }
            $demoSql = str_replace('__PREFIX__', $prefix, $demoSql);
            $connection->getPdo()->exec($demoSql);
            $output->info('CPQ demo data installed successfully.');
        }
    }

    private function installMenuRules()
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

        $controllers = [
            'cpq/product_series' => ['产品系列', 'fa fa-cubes', 100, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新', 'submit' => '提交', 'publish' => '发布']],
            'cpq/product_model' => ['产品型号', 'fa fa-cube', 90, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新', 'submit' => '提交', 'publish' => '发布']],
            'cpq/parameter_definition' => ['参数定义', 'fa fa-list-alt', 85, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新']],
            'cpq/model_parameter' => ['型号参数', 'fa fa-link', 80, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新']],
            'cpq/option_group' => ['配置组', 'fa fa-list-ul', 75, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新']],
            'cpq/option_value' => ['配置选项', 'fa fa-check-square-o', 70, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新']],
            'cpq/model_option_group' => ['型号配置结构', 'fa fa-sitemap', 65, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新']],
            'cpq/config_rule' => ['配置规则', 'fa fa-random', 60, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新', 'submit' => '提交', 'publish' => '发布']],
            'cpq/config_template' => ['推荐配置模板', 'fa fa-clone', 55, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新', 'submit' => '提交', 'publish' => '发布']],
            'cpq/accessory_service' => ['配件与服务', 'fa fa-wrench', 50, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新']],
            'cpq/bom_mapping' => ['BOM映射', 'fa fa-cubes', 45, ['index' => '查看', 'add' => '新增', 'edit' => '编辑', 'del' => '删除', 'multi' => '批量更新', 'publish' => '发布']],
            'cpq/configurator' => ['产品配置器', 'fa fa-sliders', 40, ['index' => '查看', 'schema' => '读取配置结构', 'validateconfiguration' => '校验配置']],
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

        Db::name('auth_rule')->where('name', 'in', [
            'cpq/configurator/add',
            'cpq/configurator/edit',
            'cpq/configurator/del',
            'cpq/configurator/multi',
        ])->delete();
        Cache::rm('__menu__');
    }

    private function upsertMenuRule(array $rule, $now)
    {
        $existing = Db::name('auth_rule')->where('name', $rule['name'])->find();
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
            Db::name('auth_rule')->where('id', $existing['id'])->update($values);
            return (int)$existing['id'];
        }
        $values['createtime'] = $now;
        return (int)Db::name('auth_rule')->insertGetId($values);
    }
}
