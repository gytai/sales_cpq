# ADR 0001：框架版本与依赖锁定

- 状态：已接受（M0，2026-09-03）
- 上下文：方案 §9.2 要求基于仓库现有 FastAdmin `1.6.5.20260602` / ThinkPHP `5.0.28` 执行兼容性 PoC 并锁定 `composer.lock`，禁止开发过程中临时替换框架。

## 决策

1. 锁定 `composer.lock` 入库，作为所有环境（本机、Docker、CI）唯一依赖来源；`docker/Dockerfile` 与 CI 均执行 `composer install` 而非 `update`。
2. 不升级 FastAdmin / ThinkPHP / think-queue 等核心依赖；如未来确需升级，单独形成升级 ADR 与迁移计划，不与业务开发混合（方案 §14）。
3. M0 新增依赖仅限 PDF 组件 `mpdf/mpdf:^8.1`（锁定 8.2.x，兼容 PHP 7.4）；Excel 复用已有 `phpoffice/phpspreadsheet`（锁定 1.30.1）。
4. Docker 内 PHP 镜像 tag 精确到 patch（`php:7.4.33-fpm-bullseye`），Composer 固定 2.2 LTS，均为 tag 锁定，禁止 `latest`。

## 后果

- 任何依赖变更必须通过 `composer require/update` 显式执行并重新提交 `composer.lock`；禁止直接编辑锁文件。
- `composer validate` 与锁文件一致性校验（`composer install --dry-run`）纳入 CI，防止 composer.json 与锁文件漂移。

## 已验证的组合（M0 基线）

| 组件 | 版本 | 说明 |
| --- | --- | --- |
| FastAdmin | 1.6.5.20260602 | 仓库基线，不变更 |
| ThinkPHP | 5.0.28 | `thinkphp` 目录随仓库分发，不变更 |
| PHP | 7.4.33 | Docker `php:7.4.33-fpm-bullseye` |
| MySQL | 8.0 | `mysql:8.0`，utf8mb4 / utf8mb4_general_ci |
| Redis | 7.2 | `redis:7.2-alpine` |
| 队列 | think-queue 1.1.6 + Redis 驱动 + phpredis 5.3.7 | ext-redis 由 pecl 锁定 `redis-5.3.7` |
| PDF | mpdf 8.2.x | `composer.lock` 锁定 |
| Excel | PhpSpreadsheet 1.30.1 | `composer.lock` 锁定 |
