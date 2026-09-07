# ADR 0002：composer.lock 入库锁定依赖组合

- 状态：已接受
- 日期：2026-09-03
- 关联：方案 §9.2；Issue GYTAI-80

## 背景

仓库原 `.gitignore` 忽略了 `composer.lock`，各环境依赖解析结果不可复现：同一 `composer.json` 在不同时间解析出的 `topthink/framework dev-master`、PhpSpreadsheet、mpdf 版本可能不同，PoC 结论无法回放到新环境。方案 §9.2 要求「确认可运行组合，并锁定 `composer.lock`」。

## 决策

1. `composer.lock` 从 `.gitignore` 移除并入库，作为 M0 锁定的依赖组合的唯一事实来源。
2. 安装一律使用 `composer install`（按锁文件还原），禁止用 `composer update` 全量解析；新增/调整依赖时只对目标包做定向更新（如 `composer update mpdf/mpdf --with-all-dependencies`）并连同锁文件一起提交。
3. `composer.json` 的 `scripts` 固定测试入口：`composer test:cpq`（单元）、`composer test:cpq-integration`（数据库集成）。
4. 本机与 CI 使用 Composer 2.2 LTS（支持 PHP 7.4 的最后主线），与 Docker 镜像内版本一致（见 ADR 0003）。

## 后果

- 正面：新环境（本机、CI、Docker）安装的依赖逐字节一致，PoC 结论可复核。
- 代价：依赖升级变为显式动作，需评审锁文件 diff；这是有意为之的摩擦。
