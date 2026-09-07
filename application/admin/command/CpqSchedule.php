<?php

namespace app\admin\command;

use app\common\service\cpq\SchedulerService;
use think\console\Command;
use think\console\Input;
use think\console\Input\Option;
use think\console\Output;

class CpqSchedule extends Command
{
    protected function configure()
    {
        $this->setName('cpq:schedule')
            ->addOption('quote-valid-days', null, Option::VALUE_OPTIONAL, '报价有效天数', 30)
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '单轮最大处理数', 100)
            ->setDescription('Run CPQ scheduled publish, quote expiry and integration retry');
    }

    protected function execute(Input $input, Output $output)
    {
        $result = (new SchedulerService())->run(null, (int)$input->getOption('quote-valid-days'), (int)$input->getOption('limit'));
        $output->info(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}
