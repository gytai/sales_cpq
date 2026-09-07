<?php

namespace app\admin\command;

use app\common\service\cpq\SchemaUpgradeService;
use think\console\Command;
use think\console\Input;
use think\console\Input\Option;
use think\console\Output;
use think\Db;

/**
 * CPQ 增量升级命令：按 database/cpq/upgrades/ 文件名序应用未执行的脚本。
 *
 * - 已有库升级：php think cpq:upgrade（执行前请备份数据库）；
 * - 空库安装请使用 php think cpq:install，它会建最终结构并标记脚本已执行；
 * - 复跑幂等：已执行的脚本不会重复执行。
 */
class CpqUpgrade extends Command
{
    protected function configure()
    {
        $this
            ->setName('cpq:upgrade')
            ->addOption('list', null, Option::VALUE_NONE, 'List pending upgrade scripts only')
            ->setDescription('Apply pending CPQ database upgrade scripts');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = Db::connect();
        $connection->execute('SELECT 1');
        $pdo = $connection->getPdo();

        $service = new SchemaUpgradeService();
        if ($input->getOption('list')) {
            foreach ($service->pendingScripts($pdo) as $name) {
                $output->info('pending: ' . $name);
            }
            return;
        }

        $applied = $service->applyAll(
            $pdo,
            function ($name) use ($output) {
                $output->info('applied: ' . $name);
            }
        );

        if (!$applied) {
            $output->info('Nothing to upgrade, database is up to date.');
            return;
        }
        $output->info(sprintf('CPQ upgrade finished, %d script(s) applied.', count($applied)));
    }
}
