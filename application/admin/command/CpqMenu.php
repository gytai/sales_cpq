<?php

namespace app\admin\command;

use app\common\service\cpq\MenuRuleService;
use think\Config;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\Db;
use think\Exception;

class CpqMenu extends Command
{
    protected function configure()
    {
        $this->setName('cpq:menu')->setDescription('Sync CPQ admin menu rules (idempotent)');
    }

    protected function execute(Input $input, Output $output)
    {
        $connection = Db::connect();
        $prefix = (string)Config::get('database.prefix');
        $tables = $connection->query("SHOW TABLES LIKE '{$prefix}auth_rule'");
        if (empty($tables)) {
            throw new Exception('auth_rule table not found; run `php think install` first.');
        }
        (new MenuRuleService($connection))->install();
        $output->info('CPQ admin menu rules synced successfully.');
    }
}
