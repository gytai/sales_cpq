# ADR 0003：Docker 运行时基线

- 状态：已接受（M0，2026-09-03）
- 上下文：方案 §10 定义了 nginx / app / worker / mysql / redis 的容器划分；M0 需要一套可复现、可一次启动的开发环境。

## 决策

1. 镜像与 tag 全部精确锁定，禁止浮动 tag：
   - `php:7.4.33-fpm-bullseye`（app / worker 共用 Dockerfile 构建）；
   - `nginx:1.27-alpine`、`mysql:8.0`、`redis:7.2-alpine`；
   - Composer 固定 2.2 LTS（PHP 7.4 支持的最后主线）；
   - phpredis 经 pecl 锁定 `redis-5.3.7`（think-queue 1.1.6 Redis 驱动依赖 ext-redis）。
2. `app` 与 `worker` 同镜像：worker 仅覆盖 command 为 `php think queue:listen`，保证任务执行环境与 Web 完全一致。
3. 代码目录 bind mount 开发；`vendor`、`thinkphp`、`runtime` 用卷保护，避免空挂载覆盖镜像内依赖、共享运行时状态。
4. MySQL 默认 8.0（5.7 已 EOL，不支持新部署）；字符集 `utf8mb4 / utf8mb4_general_ci`，与 `database/cpq/install.sql` 显式指定的表级 collation 一致。
5. 初始化入口为 `docker/init.sh`（容器内执行，可重复运行，`FORCE=1` 强制重装）；连接信息通过 `.env` 的 `[database]` / `[queue]` 段注入，应用侧经 `think\Env` 读取，不写死主机名。

## 后果

- 升级任一镜像 tag 属于基础设施变更，需重新跑 M0 PoC 并更新 `docs/cpq/m0-poc.md`。
- 生产部署的 TLS、备份、scheduler、minio 等按方案 §10.3 另行实施，本 compose 仅为开发/测试基线。
