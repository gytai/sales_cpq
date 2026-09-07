<?php

use think\Env;

$connector = getenv('CPQ_QUEUE_CONNECTOR');
$redisHost = getenv('CPQ_REDIS_HOST');
$redisPort = getenv('CPQ_REDIS_PORT_INNER');
$redisPassword = getenv('CPQ_REDIS_PASSWORD');
$redisDatabase = getenv('CPQ_REDIS_DATABASE');

// CPQ_* 变量供容器覆盖挂载进来的宿主机 .env；未提供时保持原有 [queue] 配置。
return [
    'connector'  => $connector !== false ? $connector : Env::get('queue.connector', 'Redis'), // 驱动：Redis / Database / Sync
    'expire'     => 0,             // 任务的过期时间，默认为60秒; 若要禁用，则设置为 null
    'default'    => 'default',    // 默认的队列名称
    'host'       => $redisHost !== false ? $redisHost : Env::get('queue.hostname', '127.0.0.1'),       // redis 主机ip
    'port'       => $redisPort !== false ? (int)$redisPort : Env::get('queue.hostport', 6379),        // redis 端口
    'password'   => $redisPassword !== false ? $redisPassword : Env::get('queue.password', ''),             // redis 密码
    'select'     => $redisDatabase !== false ? (int)$redisDatabase : (int)Env::get('queue.select', 0),          // 使用哪一个 db，默认为 db0
    'timeout'    => 0,          // redis连接的超时时间
    'persistent' => false,
];
