# CPQ 增量升级脚本约定

本目录存放按版本递增的增量 SQL，规则（与 `docs/cpq/data-model.md` 第 3 节一致）：

1. 文件名：`YYYYMMDDXX_说明.sql`，`XX` 为当日序号（`01` 起），只增不改——已入库的脚本禁止回头修改，修正一律用新的增量脚本。
2. 内容：只写增量 `ALTER TABLE` / `CREATE TABLE IF NOT EXISTS` / 索引与数据订正；表名使用 `__PREFIX__` 占位符，由执行方替换为当前前缀（`fa_`）。
3. 字符集：新增表/字段显式声明 `utf8mb4` + `COLLATE utf8mb4_general_ci`，与 `install.sql` 和服务端配置一致。
4. 可前滚：每个脚本必须可在空库（先执行 `install.sql`）和已有数据库上执行通过；如需回滚，在脚本头部注释给出反向 SQL 或恢复说明。
5. 执行：空库运行 `php think install` 后会把已有脚本标记为已应用；已有库在备份后运行 `php think cpq:upgrade`，由 `cpq_migration` 按文件名顺序幂等追踪。

当前脚本：

- `2026090301_m1_master_data_governance.sql`：版本治理、审计、产品线数据范围与迁移表。
- `2026090302_m1_rule_engine_bom_no_material.sql`：BOM “不产生物料”标记。
- `2026090303_m2_customer_channel_price.sql`：客户/渠道主数据与价格发布版本。
- `2026090401_m2_pricing_agent_dimension.sql`：三层价格策略增加指定代理商维度与索引。

演示数据一律放 `../demo.sql`，不进入升级脚本。
