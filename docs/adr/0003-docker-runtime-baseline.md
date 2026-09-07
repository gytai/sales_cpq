# ADR 0003：Docker 运行时基线（PHP 7.4.33 / MySQL 8.0 / Redis 7.2 / Composer 2.2）

- 状态：已接受
- 日期：2026-09-03
- 关联：方案 §10；Issue GYTAI-80；验证记录见 `docs/cpq/m0-poc.md`

## 背景

M0 需要一套可复现的开发环境，覆盖方案 §10.1 的最小容器集合（nginx、app、worker、mysql、redis），并与 ADR 0001 锁定的框架基线兼容。基线中的每个镜像 tag 都必须精确固定，禁止「latest 漂移」。

## 决策

| 组件 | 锁定版本 | 理由 |
| --- | --- | --- |
| PHP | `php:7.4.33-fpm-bullseye` | 7.4 最后一个补丁版本；与本机 CLI（7.4.33）一致，PoC 结论可互相印证 |
| Composer | `composer:2.2` | 支持 PHP 7.4 的最后 LTS 主线 |
| MySQL | `mysql:8.0`（`mysql_native_password`） | 5.7 已 EOL；8.0 用 native password 插件兼容 ThinkPHP 5.0 的 PDO 连接；服务端统一 `utf8mb4 / utf8mb4_general_ci`，与 `database/cpq/install.sql` 显式 collation 对齐 |
| Redis | `redis:7.2-alpine` | think-queue 1.1.6 Redis 驱动的缓存/队列后端 |
| Nginx | `nginx:1.27-alpine` | 静态资源 + PHP-FPM 反代；PDF 目录禁止浏览 |
| PHP 扩展 | pdo_mysql / bcmath / zip / gd / opcache + pecl `redis-5.3.7` | FastAdmin、PhpSpreadsheet、mpdf、think-queue 的硬性要求 |

队列连接配置由 `application/extra/queue.php` 经 `think\Env` 读取 `.env` 的 `[queue]` 段，容器内外同一份代码、不同环境变量。

## 后果

- 正面：`docker compose up` 一条命令复现完整环境；worker 与 app 同镜像，队列 PoC 真实消费 Redis。
- 代价：MySQL 8.0 与部分遗留 SQL 模式（ONLY_FULL_GROUP_BY 等）的差异需在 CPQ SQL 编写时显式规避；`install.sql` 已统一声明 `utf8mb4_general_ci` 避免混排。
- 已验证：组合兼容性结论与复现步骤见 `docs/cpq/m0-poc.md` 与 `docker/README.md`。
