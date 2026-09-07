# M2 客户渠道与价格主数据后端验证记录（GYTAI-69）

对应方案 §2 / §4.3（P30-P37）/ §4.4（P40-P45）/ §6。本文件只记录真实跑过的验证与结果。

## 1. 环境

- PHP 7.4.33（`sales_cpq-app-1` 容器 CLI，仓库根挂载 `/var/www/html`）；
- MySQL 8.0（`sales_cpq-mysql-1` 容器，root/root，表前缀 `fa_`）；
- 测试均在容器内执行：`docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/<file>.php`；
- 单元/集成测试使用独立临时库（`cpq_m2_price_test` / `cpq_m1_upgrade_test`），执行结束自动 DROP；测试数据业务编码一律带 `CPQ-TEST-` 前缀，演示数据为 `CPQ-DEMO-`。

## 2. 数据结构

- `database/cpq/install.sql`：新增 8 张表（`cpq_customer_level`、`cpq_agent_level`、`cpq_region`、`cpq_sales_org`、`cpq_sales_org_member`、`cpq_customer`、`cpq_agent`、`cpq_release_version`），全库共 29 张 `fa_cpq_*` 表；
- `database/cpq/upgrades/2026090303_m2_customer_channel_price.sql`：同内容增量脚本（含回滚说明），幂等可复跑；
- `cpq_customer.agent_id` 与 `cpq_agent.customer_id` 互为引用，为避免循环外键，`agent_id` 不建物理外键，由服务层实施完整性校验（脚本头注释已说明）；
- 金额 `DECIMAL(18,4)`、汇率 `DECIMAL(18,8)`、比率 `DECIMAL(9,6)`，全链路 bccomp/bcadd 字符串运算，禁止 float。

## 3. 主测试 `tests/cpq/price_channel.php`

```bash
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/price_channel.php
```

结果：`M2 price & channel master data tests: PASS (80 assertions)`，临时库自动删除。覆盖点：

- **唯一性**：客户编码重复被 `uk_cpq_customer_code` 拒绝（SQLSTATE 23000）；客户引用不存在的等级被外键拒绝（1452）；
- **区域/组织树**：`ChannelTreeService` 维护物化路径（根 `/{id}/` 层级 1，子节点继承，三级路径正确）；`moveNode` 移动后父路径更新且子树级联重建；父节点设为自身后代被判环拒绝；有子节点的区域不能删除；
- **引用保护**：被客户引用的区域/客户等级、被代理商引用的客户均不可删除（报错含引用来源与条数）；无引用等级放行；
- **三层价格关系**：`指导价 >= 产线控制价 >= 公司控制价 >= 0` 全组合校验（含 `0.3/0.2/0.1` 精度用例，float 会失真、bccomp 通过）；金额规范化（`1.005`→`1.0050`）、负值/非数值拒绝；`classifyAgainstFloors` 输出 normal/line_approval/company_approval/forbidden 四档（P53 规则表）；
- **维度与重叠**：`dimension_key` 为规范化维度 SHA-256（与键序无关）；同维度已发布策略时间重叠拒绝，时间不重叠允许，库中草稿不参与，excludeId 排除自身；
- **价格表发布**：同（公司/板块/市场/币种）同优先级时间重叠的已发布价格表拒绝（服务层 + 生命周期 publish 双路径）；不同优先级允许；已发布价格表不可直接修改、不可再写价格条目（父表守卫）；
- **覆盖率**：`coverageReport` 统计在售型号 2 款、覆盖 1 款，缺口列出 `CPQ-TEST-MODEL-B`；
- **发布版本**：发布生成版本号递增 + 内容 SHA-256；已生效版本不可修改/不可撤回；计划生效未来 → pending，可撤回；撤回不可重复；回滚 = 重发旧内容新版本（哈希相同、版本递增、pending）；仅已生效版本可回滚；
- **价格规则**：条件 JSON 白名单（字段 `user.password`、比较符 `eval` 均被拒）；同互斥组同优先级且条件可能同时命中禁止发布；条件互斥（不同客户等级）或不同互斥组允许；生命周期 publish 路径同样拦截；
- **数据范围**：授权 LINE-B 的管理员发布 LINE-A 策略被拒（无该产品线的数据权限），不受限管理员放行；
- **脱敏与审计**：销售仅见指导价+产线控制价；产线价格管理员可见成本、不可见公司控制价；公司价格管理员全量；无角色按最低权限；`requiresAudit` 只对可见敏感字段的角色为真；`view_sensitive`/`export` 均写入 `cpq_audit_log`；
- **导入预览契约**：`{type,total,valid_count,error_count,errors[],items[]}`，错误行含 `row/field/code/message`（行号 1 起不含表头），必填缺失 `CPQ_IMPORT_REQUIRED`、文件内重复 `CPQ_IMPORT_DUPLICATE`、三层关系 `CPQ_PRICE_HIERARCHY`；预览不写库；金额规范化为字符串；未知类型拒绝。

## 4. 回归测试

```bash
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/master_data.php     # PASS (66 assertions)
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/upgrade.php         # PASS (30 assertions)
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/run.php             # PASS
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/rule_analysis.php   # PASS (33)
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/rule_engine.php     # PASS (26)
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/integration.php     # PASS
docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/poc.php             # ALL PASS
```

`upgrade.php` 场景 B 扩展为：回退到升级前结构（含 DROP M2 八表）→ `pendingScripts` 恰为两个 M1 脚本 + `2026090303_m2_customer_channel_price.sql` → `applyAll` 按序应用后 M2 八表就位 → 复跑幂等。

## 5. 真实命令回归（临时改 `.env` 指向临时库 `cpq_m2_fresh`，验证后恢复并删库）

```bash
php think cpq:install --demo
```

输出：

```
auth_rule table not found; skip admin menu rules installation.
CPQ upgrade scripts marked as applied (fresh install).
CPQ database tables installed successfully.
CPQ demo data installed successfully.
```

断言：`fa_cpq_*` 表 29 张；`fa_cpq_migration` 含全部 3 个脚本；M2 演示数据（客户 1 + 区域 2 + 发布版本 1）落库；复跑 `cpq:install --demo` 仍成功（幂等，提示改用 `cpq:upgrade`）。

## 6. 交付清单

- SQL：`database/cpq/install.sql`、`database/cpq/upgrades/2026090303_m2_customer_channel_price.sql`、`database/cpq/demo.sql`（M2 演示段）
- 服务：`ChannelTreeService`、`PricePolicyService`、`PriceRuleService`、`PriceReleaseService`、`SensitiveFieldService`、`ImportPreviewService`；`MasterDataLifecycleService` 增量扩展（3 张价格表纳入版本化生命周期 + 发布校验 + 引用保护 + 价格条目父表守卫）
- 后台：`application/admin/model/cpq/` 15 个、`validate/cpq/` 14 个、`controller/cpq/` 15 个（含树形 move、价格策略脱敏列表、发布版本 withdraw/rollback、六个 importpreview）；`CpqInstall.php` 菜单注册新增「CPQ价格中心」「CPQ客户与渠道」两组
- 测试：`tests/cpq/price_channel.php`（新）、`tests/cpq/upgrade.php`（扩展）

## 7. 已知边界（非缺陷）

- 后台视图/JS 属前端任务 GYTAI-74，本任务不交付，add/edit 的 GET 页面待其补齐；
- `demo.sql` 中价格策略行的 `dimension_key` 置空串（SHA-256 需 PHP 端规范化计算），仅用于页面与覆盖率演示；正式数据由服务端写入时计算；
- `SensitiveFieldService::rolesOfAdmin` 依赖 FastAdmin `fa_auth_group` 表（组名与 CPQ 角色编码精确匹配），临时测试库无该表，仅覆盖 adminId≤0 分支；
- 异步导入平台不在本任务范围，导入仅同步预览契约（方案 P105 属 M3）。
