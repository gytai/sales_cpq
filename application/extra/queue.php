<?php

use think\Env;

// 队列连接配置：默认与本地开发环境一致；Docker/线上通过 .env [queue] 段覆盖
return [
    'connector'  => Env::get('queue.connector', 'Redis'), // 驱动：Redis / Database / Sync
    'expire'     => 0,             // 任务的过期时间，默认为60秒; 若要禁用，则设置为 null
    'default'    => 'default',    // 默认的队列名称
    'host'       => Env::get('queue.hostname', '127.0.0.1'),       // redis 主机ip
    'port'       => Env::get('queue.hostport', 6379),        // redis 端口
    'password'   => Env::get('queue.password', ''),             // redis 密码
    'select'     => (int)Env::get('queue.select', 0),          // 使用哪一个 db，默认为 db0
    'timeout'    => 0,          // redis连接的超时时间
    'persistent' => false,
];
