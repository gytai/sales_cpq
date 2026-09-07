# M1 产品与配置主数据后端基础验证记录（GYTAI-67）

对应方案 §5.1 / §6.4 / §9.1。本文件只记录真实跑过的验证与结果，未验证项在最后一节明确标注。

## 1. 环境

- PHP 7.4.33（`sales_cpq-app-1` 容器 CLI，仓库根挂载 `/var/www/html`）；
- MySQL 8.0（`sales_cpq-mysql-1` 容器，root/root，库 `fastadmin`，表前缀 `fa_`）；
- 测试均在容器内执行：`docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/<file>.php`；
- 单元/集成测试使用独立临时库（`cpq_m1_master_test` / `cpq_m1_upgrade_test`），执行结束自动 DROP；测试数据业务编码一律带 `CPQ-TEST-` 前缀，演示数据为 `CPQ-DEMO-`，互不混用。

## 2. 主数据治理测试 `tests/cpq/master_data.php`

```bash
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/master_data.php
```

结果：`M1 master data tests: PASS (66 assertions)`，临时库自动删除。覆盖点：

- **唯一性**：系列/型号同 `(code,version)` 重复插入被唯一键拒绝（SQLSTATE 23000，`uk_cpq_product_series_code_version` / `uk_cpq_product_model_code_version`）；同 code 不同 version 允许并存；
- **版本不可变**：published/expired 行 `assertEditable` 抛「已发布或已失效版本不可直接修改，请复制新版本」，draft/pending 与非版本化表不抛；
- **删除守卫**：pending/published/expired 行 `assertDraftDeletable` 抛「只有草稿版本可以删除」，draft 不抛；
- **状态机**：系列/型号 draft 直接 publish 抛「只有待审批版本可以发布」；pending→publish 成功；expire 仅允许 published；
- **版本接替（supersede）**：系列 v2 发布后同 code 旧 published 版本自动 expired，旧版本下型号改挂新系列行；型号 v2 发布后其配置规则/模板/BOM 映射的 `model_id` 改挂新行，旧行 `cpq_model_parameter` / `cpq_model_option_group` 残留被清理；
- **复制新版本**：`copyNewVersion` 生成 version+1 草稿；型号的参数与配置结构子数据一并复制；已存在草稿/待审批版本时再复制抛异常；
- **BOM 映射免审批**：`cpq_bom_mapping` 草稿可直接 publish；已发布行再次 publish 抛「只有草稿版本可以发布」；
- **引用保护**：`assertDeletable` 对被引用记录抛出可读消息（实测输出：`CPQ-TEST-SERIES-A 被 产品型号 引用（5 条）`、`CPQ-TEST-OPT-A 被 BOM映射 引用（1 条）`、`CPQ-TEST-PARAM-A 被 型号参数 引用（1 条）`）；无引用的系列/选项/参数定义不抛；
- **产品线数据范围**：受限管理员授权线放行、未授权线与空产品线抛「无该产品线的数据权限」；`*` 记录不受限；无任何记录 fail-closed 全拒绝；`new ProductLineScopeService($id, true)` 不限制；越权发布经 `MasterDataLifecycleService::assertLineScope`（publish 传 scope）被拒，授权范围内发布成功；
- **审计日志**：publish/expire/copy 后 `fa_cpq_audit_log` 有对应 action 记录且 `object_type`/`object_id`/`object_code` 匹配；CLI 上下文操作人落 `system`；
- **子表守卫**：对已发布型号写入 `cpq_model_parameter` 被 `assertWritableParent` 拒绝（「所属型号已发布或已失效，请复制新版本后修改其参数与配置结构」），草稿型号与非子表不干预。

已知行为（非缺陷，测试按实现语义断言）：复制出的系列新版本发布前需至少一个有效型号挂在**新版本行**上（发布校验先于型号接替执行），测试中先为 v2 建一个草稿型号再发布。

## 3. 增量升级测试 `tests/cpq/upgrade.php`

```bash
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/upgrade.php
```

结果：`M1 upgrade tests: PASS (18 assertions)`，临时库自动删除。三个场景均直接持有 PDO 驱动 `SchemaUpgradeService`（`prefix='fa_'`）：

- **场景 A 空库安装语义**：执行 install.sql → `markAllApplied` 标记 `2026090301_m1_master_data_governance.sql` → `pendingScripts` 为空、`appliedScripts` 与脚本清单一致；
- **场景 B 已有库升级**：install.sql 后按升级脚本头注释的反向 SQL 回退（恢复旧 `uk_cpq_product_series_code` / `uk_cpq_product_model_code` 唯一键，DROP `fa_cpq_audit_log` / `fa_cpq_admin_product_line` / `fa_cpq_migration`）→ `pendingScripts` 恰为 M1 脚本（`cpq_migration` 由服务自动补建）→ `applyAll` 应用成功 → information_schema 断言新唯一键 `uk_cpq_product_series_code_version` / `uk_cpq_product_model_code_version` 就位、旧键已删、三张新表存在 → 复跑 `applyAll` 返回空数组（幂等，无重复执行）；
- **场景 C**：已标记全部脚本的最新结构库 `applyAll` 为空操作。

## 4. 真实命令回归（临时改 `.env` 指向临时库，`trap` 兜底恢复）

### 场景一：空库 `cpq_reg_fresh`

```bash
php think cpq:install --demo
```

关键输出原样：

```
auth_rule table not found; skip admin menu rules installation.
CPQ upgrade scripts marked as applied (fresh install).
CPQ database tables installed successfully.
CPQ demo data installed successfully.
```

断言结果：

```
cpq_tables=21
demo_model=CPQ-DEMO-EQUIPMENT-A, status=published
migration=2026090301_m1_master_data_governance.sql
```

复跑 `php think cpq:install --demo` 仍成功（幂等），提示 `Existing CPQ tables detected; run php think cpq:upgrade to apply pending upgrades.`。

### 场景二：已有库 `cpq_reg_old`

先 `cpq:install`，再按升级脚本头注释的反向 SQL 回退结构（含 DROP `fa_cpq_migration`），然后：

```
$ php think cpq:upgrade --list
pending: 2026090301_m1_master_data_governance.sql

$ php think cpq:upgrade
applied: 2026090301_m1_master_data_governance.sql
CPQ upgrade finished, 1 script(s) applied.

$ php think cpq:upgrade
Nothing to upgrade, database is up to date.
```

升级后 information_schema 断言：两个 `(code,version)` 新唯一键就位，`fa_cpq_audit_log`、`fa_cpq_admin_product_line`、`fa_cpq_migration` 三表存在。

### `.env` 还原证据

回归结束后容器内备份与现文件逐字节一致（`diff` 无输出）：

```
ENV_DIFF_EMPTY
HOST_ENV_DIFF_EMPTY
```

两个临时库 `cpq_reg_fresh` / `cpq_reg_old` 已 DROP。

## 5. 既有测试回归

```bash
docker exec sales_cpq-app-1 sh -c 'cd /var/www/html && php tests/cpq/run.php && php tests/cpq/poc.php && php tests/cpq/integration.php'
```

结果：

```
ConfigurationService tests: PASS
[PASS] PDF/mpdf 生成中文报价单
[PASS] Excel/PhpSpreadsheet 中文读写
[PASS] 队列/think-queue 推送与消费
=== M0 组件 PoC 结果 ===
ALL PASS
CPQ database integration tests: PASS
```

## 6. 测试中发现并修复的缺陷（最小修改）

1. **`application/admin/command/CpqInstall.php`**（`execute()`）：空库（无 FastAdmin 后台表）执行 `cpq:install` 时，`installMenuRules()` 访问不存在的 `fa_auth_rule` 导致 `SQLSTATE[42S02] Table ... doesn't exist`，与命令宣称的「空库安装」语义矛盾。修复：新增 `tableExists()` 探测，`auth_rule` 不存在时跳过菜单规则安装并输出 warning；已有库（含 `auth_rule`）路径行为不变。
2. **`application/common/service/cpq/MasterDataLifecycleService.php`**（`copyNewVersion()`，原 218 行）：`$row->toArray()` 会把模型的 `$append` 附加属性（如 `status_text`）带进 `Db::insert`，触发 `fields not exists:[status_text]`，凡经后台模型复制的版本化记录都会失败。修复：改用 `$row->getData()` 取原始字段（注释说明原因）。

## 7. 恢复说明

- 升级脚本 `database/cpq/upgrades/2026090301_m1_master_data_governance.sql` 为前滚脚本；**执行升级前必须先备份数据库**（见 `database/cpq/README.md`），从备份恢复是首选方式；
- 脚本头注释内附完整反向 SQL（恢复旧 `(code)` 唯一键、DROP 三张新表），仅供库内手工回退使用——会丢失审计与产品线范围数据，且要求同编码不存在多版本行；本文件第 3/4 节的「回退再前滚」场景即按该反向 SQL 构造，反向 SQL 本身已经过真实执行验证。

## 8. 未验证项

- 后台 12 个 `application/admin/controller/cpq/*` 控制器与 `CpqVersioned` / `CpqRelationIndex` trait 的 HTTP 层行为（需登录会话，未在本轮验证，仅 PHP lint 通过）；
- `cpq_config_rule` / `cpq_config_template` 的发布跨表校验（DSL 白名单、模板配置合法性）未在本轮逐项触发，仅验证了其 `model_id` 随型号接替改挂；
- 真实 FastAdmin 库上的 `cpq:install` 菜单规则安装路径本轮未重复执行（避免改动现有菜单数据），该路径代码未改动、M0 已验证过。
