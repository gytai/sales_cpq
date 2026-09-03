# CPQ 既有代码盘点（M0）

> 范围：进入 M0 时仓库基线（`376de7f` 之上的未提交 CPQ 工作，已并入基线提交）中的全部 CPQ 相关改动。逐项给出处置结论：保留 / 补齐 / 后续迁移。该清单回答"哪些是可复用成果、哪些是缺口"，供 M1+ 参考。

## 1. 后端 PHP

| 路径 | 内容 | 处置 | 说明 |
| --- | --- | --- | --- |
| `application/admin/controller/cpq/`（12 个控制器） | 产品系列、产品型号、参数定义、选项组/值、规则、模板、BOM 映射等 11 个主数据 CRUD + 配置器 | 保留 | 遵循 FastAdmin Backend 约定，是 M1 直接复用的主数据基座 |
| `application/admin/model/cpq/`、`validate/cpq/` | 对应 11 个实体的模型与校验器 | 保留 | — |
| `application/admin/library/traits/CpqVersioned.php`、`CpqRelationIndex.php` | 版本控制与关系索引 trait | 保留 | — |
| `application/common/service/cpq/ConfigurationService.php` | 配置校验核心（依赖/互斥/数量/公式/BOM 生成） | 保留 | 已被 `tests/cpq/run.php` 覆盖，行为已锁定 |
| `application/common/repository/cpq/ConfigurationSchemaRepository.php` | 已发布配置方案读取 | 保留 | 集成测试覆盖 |
| `application/api/controller/cpq/Configuration.php` + `application/route.php` | 配置校验 API | 保留 | — |
| `application/admin/command/CpqInstall.php` | `cpq:install` 命令（SQL 安装/演示数据/菜单规则） | 保留 | — |
| `application/common/behavior/Common.php`、`application/config.php`、`application/command.php` 改动 | 接入 CPQ 命令与路由 | 保留 | — |

## 2. 后台前端（RequireJS / Bootstrap Table）

- `public/assets/js/backend/cpq/*.js`（13 个模块 + common.js）
- `application/admin/view/cpq/*`（11 个模块的 CRUD 视图 + 配置器页面）

处置：保留。其中 `configurator.js`（配置器交互）与 `C-005`（循环依赖检测 UI 提示）在 M1 需按验收用例补齐联调。

## 3. 数据库与测试

| 路径 | 处置 | 说明 |
| --- | --- | --- |
| `database/cpq/install.sql` / `demo.sql` / `upgrades/` / `README.md` | 保留 | `__PREFIX__` 由安装命令替换；collation 已修正为 utf8mb4 |
| `tests/cpq/run.php` | 保留 | 单元测试，PASS |
| `tests/cpq/integration.php` | 保留 | 数据库集成测试，依赖演示数据 |

## 4. 缺口（M1+ 补齐项）

- `C-005` 循环依赖发布校验（当前无测试，见 `docs/cpq/acceptance-tests.md` §4）；
- 报价/审批/PDF/审计等 M2+ 业务模块（不在本盘点范围，未开始）；
- 越权与审计用例（`docs/cpq/acceptance-tests.md` §5）。

## 5. 迁移项

- 无。既有代码均符合 FastAdmin 工程约定，无需要推倒重写或迁移技术栈的部分。
