# ADR 0001：沿用 FastAdmin 1.6.5 / ThinkPHP 5.0.28 基线，MVP 期间不升级框架

- 状态：已接受
- 日期：2026-09-03
- 关联：方案 §1.3、§9.2、§17；Issue GYTAI-80

## 背景

仓库当前代码基线为 FastAdmin `1.6.5.20260602`（见 `application/config.php`）、ThinkPHP `5.0.28`（见 `thinkphp/base.php`）、PHP `>=7.4`。方案 §17 明确「本项目已经采用 FastAdmin 代码基线，MVP 不再进行框架选型」。M0 需要在此基线上锁定一套可复现的运行时组合。

## 决策

1. MVP 期间锁定 FastAdmin 1.6.5 / ThinkPHP 5.0.28 / PHP 7.4.x，不升级框架主版本，不引入 Vue/React 等第二前端栈。
2. CPQ 代码只做增量：Controller 继承 `app\common\controller\Backend` / `Api`，页面与脚本放入 `application/admin/view/cpq/`、`public/assets/js/backend/cpq/`，不修改 FastAdmin/ThinkPHP 核心文件。
3. 业务依赖只允许增量添加（如 `mpdf/mpdf`、`phpoffice/phpspreadsheet`），版本约束写入 `composer.json` 并由 `composer.lock` 锁定（见 ADR 0002）。
4. 若后续发现基线存在无法接受的安全风险，必须单独形成 ADR 与迁移计划，经确认后独立实施，不与业务开发混合。

## 后果

- 正面：PoC 与验收结论对全部里程碑有效；不会因框架升级引入回归。
- 代价：PHP 7.4 已停止安全更新，依赖版本选择受其约束（如 Composer 限 2.2 LTS、mpdf 限 8.x）。该风险由 Docker 运行时基线（ADR 0003）收敛暴露面，并在 M4 上线准备时单独评估 PHP 8.x 迁移。
