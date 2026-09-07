<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

return [
    'api/cpq/v1/product-models/:id/schema'  => ['api/cpq.configuration/schema', ['method' => 'get'], ['id' => '\\d+']],
    'api/cpq/v1/product-models/:id/bom-check' => ['api/cpq.configuration/bomCheck', ['method' => 'get'], ['id' => '\\d+']],
    'api/cpq/v1/product-models'             => ['api/cpq.configuration/models', ['method' => 'get']],
    'api/cpq/v1/configurations/validate'    => ['api/cpq.configuration/validateConfiguration', ['method' => 'post']],
    'api/cpq/v1/configurations/bom'         => ['api/cpq.configuration/bom', ['method' => 'post']],
    'api/cpq/v1/prices/calculate'           => ['api/cpq.price/calculate', ['method' => 'post']],
    'api/cpq/v1/prices/explain'             => ['api/cpq.price/explain', ['method' => 'post']],
    'api/cpq/v1/imports/:id/confirm'         => ['api/cpq.import/confirm', ['method' => 'post'], ['id' => '\\d+']],
    'api/cpq/v1/imports/:type'               => ['api/cpq.import/create', ['method' => 'post'], ['type' => '[a-z_]+' ]],
    'api/cpq/v1/jobs/:id/retry'              => ['api/cpq.job/retry', ['method' => 'post'], ['id' => '[a-f0-9]{32}']],
    'api/cpq/v1/jobs/:id/error-report'       => ['api/cpq.job/errorReport', ['method' => 'get'], ['id' => '[a-f0-9]{32}']],
    'api/cpq/v1/jobs/:id'                    => ['api/cpq.job/detail', ['method' => 'get'], ['id' => '[a-f0-9]{32}']],
    'api/cpq/v1/exports/quotes'              => ['api/cpq.export/quotes', ['method' => 'post']],
    'api/cpq/v1/exports/:id/download'        => ['api/cpq.export/download', ['method' => 'get'], ['id' => '[a-f0-9]{32}']],
    //别名配置,别名只能是映射到控制器且访问时必须加上请求的方法
    '__alias__'   => [
    ],
    //变量规则
    '__pattern__' => [
    ],
//        域名绑定到模块
//        '__domain__'  => [
//            'admin' => 'admin',
//            'api'   => 'api',
//        ],
];
