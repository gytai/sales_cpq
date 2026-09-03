# CPQ 数据模型

> 仓库上下文文档。上游需求：`docs/FastAdmin-CPQ开发功能与实施方案.md` §6。
> 权威表结构以 `database/cpq/install.sql` 与 `database/cpq/upgrades/` 为准，本文记录设计意图与状态。

## 0. 全局约定

- 物理表名 = FastAdmin 数据库前缀（默认 `fa_`）+ 逻辑表名（`cpq_*`）；
- 主键：无业务含义的 `BIGINT UNSIGNED AUTO_INCREMENT`；业务编码另设唯一索引；
- 金额 `DECIMAL(18,4)`；汇率 `DECIMAL(18,8)`；比例 `DECIMAL(9,6)`；
- 字符集 `utf8mb4`、排序规则 `utf8mb4_general_ci`（与框架 fastadmin.sql 一致，避免 MySQL 8 混排）；
- 时间戳沿用 FastAdmin 惯例 `createtime`/`updatetime`（BIGINT 秒，UTC 存储）；
- 唯一性：产品、规则、价格、报价版本均有业务编码或版本唯一索引；
- 软删除只用于草稿或主数据；审批、版本、审计和正式文件不允许业务层删除；
- 变更流程：新表/新字段 → `database/cpq/install.sql` 更新 + `database/cpq/upgrades/` 增量脚本（见该目录 README）。

## 1. 表清单与状态

### 1.1 主数据表（install.sql 已建）

| 表 | 作用 | 状态 |
| --- | --- | --- |
| `cpq_product_series` | 产品系列：code、name、business_unit、product_line、status、version | M1 已建，CRUD/页面已有 |
| `cpq_product_model` | 产品型号：code、series_id、category_code、base_item_code、unit、status、version | M1 已建 |
| `cpq_parameter_definition` | 技术参数定义：code、name、value_type、unit、option_values、validation_rule | M1 已建 |
| `cpq_model_parameter` | 型号参数值：model_id、parameter_id、value、is_configurable、sort | M1 已建 |
| `cpq_option_group` | 配置组：code、input_type、is_required、min_select、max_select | M1 已建 |
| `cpq_option_value` | 配置选项：code、group_id、material_code、default_qty、status | M1 已建 |
| `cpq_model_option_group` | 型号配置结构：model_id、group_id、sort、is_visible、is_required | M1 已建 |
| `cpq_accessory_service` | 配件和服务：code、type、unit、tax_category、status | M1 已建 |
| `cpq_config_template` | 推荐配置模板：code、model_id、market_scope、config_json、version | M1 已建 |
| `cpq_bom_mapping` | 配置到物料映射：model_id、option_value_id、material_code、qty_formula、version | M1 已建 |

### 1.2 规则与价格表（install.sql 已建，业务能力待 M2）

| 表 | 作用 | 状态 |
| --- | --- | --- |
| `cpq_config_rule` | 配置规则头：code、type、scope、priority、severity、version、status + condition/action JSON | M1 已建（规则 JSON 内嵌于本表，独立表达式表暂缓） |
| `cpq_price_book` | 价格表：code、company、scope、currency、tax_mode、version、status | 表已建，M2 开发 |
| `cpq_price_entry` | 价格条目：price_book_id、target_type、target_id、amount、unit | 表已建，M2 开发 |
| `cpq_price_policy` | 三层价格策略：dimension_key、guide_price、line_floor、company_floor、cost | 表已建，M2 开发 |
| `cpq_price_rule` | 条件价格规则：condition_json、adjustment_type、adjustment_value、priority | 表已建，M2 开发 |
| `cpq_exchange_rate` | 汇率：source_currency、target_currency、rate、effective_at | 表已建，M2 开发 |
| `cpq_tax_rule` | 税率：country、region_id、product_type、rate、effective_at | 表已建，M2 开发 |
| `cpq_fee_rule` | 费用规则：fee_type、condition_json、calculation_type、value | 表已建，M2 开发 |

`dimension_key` 由以下维度规范化后计算哈希：公司、业务板块、国内/国际、区域、客户等级、
代理等级、客户、产品线、型号/选项、币种、单位、日期区间。原始维度仍单独字段存储以便查询审计。

### 1.3 客户与渠道表（未建，M2）

`cpq_customer`（客户）、`cpq_agent`（代理商）、`cpq_region`（销售区域）、`cpq_sales_org`（销售组织）。
按 §6.1 字段设计，在 M2 首个任务中随升级 SQL 建立。

### 1.4 报价与审批表（未建，M2/M3）

| 表 | 作用 | 里程碑 |
| --- | --- | --- |
| `cpq_quote` / `cpq_quote_revision` | 报价主表 + 版本（quote_id+revision_no 唯一，snapshot_json） | M2 |
| `cpq_quote_line` / `cpq_quote_configuration` / `cpq_quote_price_snapshot` / `cpq_quote_term` | 行、配置快照（config_hash、rule_version、bom_json）、价格快照（price_trace_json）、条款快照 | M2 |
| `cpq_approval_instance` / `cpq_approval_task` / `cpq_approval_action` | 审批实例/任务/动作（before_hash、after_hash） | M3 |
| `cpq_attachment` / `cpq_audit_log` / `cpq_integration_event` | 附件、审计日志（trace_id、diff_json）、集成事件 | M3 |
| `cpq_release_version` | 发布版本（object_type、version、content_hash、effective_at） | M2 |

## 2. 关键约束（服务层与数据库双层实施）

1. 报价版本号唯一索引 `quote_id + revision_no`；
2. 相同适用范围和时间区间的发布版本不得冲突（服务层校验）；
3. 审批动作插入和任务状态变化必须处于同一数据库事务；
4. 所有引用使用外键或在服务层实施等价的完整性校验（当前 install.sql 未建物理外键，
   完整性由服务层保证；是否补外键在 M2 数据建模任务中决策并记 ADR）。

## 3. 安装与升级约定（M0 固化）

- `database/cpq/install.sql`：首次安装，全部 `CREATE TABLE IF NOT EXISTS`，占位符 `__PREFIX__`；
- `database/cpq/upgrades/`：按版本递增的增量 SQL（`YYYYMMDDXX_说明.sql`），只增不改历史文件；
- `database/cpq/demo.sql`：仅脱敏演示数据（`CPQ-DEMO` 前缀）；
- 安装命令：`php think cpq:install [--demo]`（同时写入后台菜单权限规则）；
- 空库验证流程见 `docker/README.md` 与 `docs/cpq/m0-poc.md`；
- 禁止直接修改 `application/admin/command/Install/fastadmin.sql`。
