-- ============================================================
-- M1 配置规则引擎与 BOM 校验（GYTAI-66）
--
-- cpq_option_value 新增 no_material 标记：明确标记"不产生物料"的
-- 选项免于 P20 的 BOM 缺失映射校验（每个影响 BOM 的已发布选项
-- 必须有有效映射或明确标记不产生物料）。
--
-- 适用范围：
--   空库：先执行 install.sql（已包含本脚本全部结果），再由
--         `php think cpq:install` 将本脚本标记为已执行；
--   已有库：`php think cpq:upgrade` 按文件名序执行未应用的脚本。
--
-- 恢复说明（回滚）：
--   本脚本为前滚脚本，回滚前必须先备份（见 database/cpq/README.md）。
--   如需在库内手工反向执行：
--     ALTER TABLE `__PREFIX__cpq_option_value` DROP COLUMN `no_material`;
-- ============================================================

ALTER TABLE `__PREFIX__cpq_option_value`
  ADD COLUMN `no_material` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '不产生物料:0=否,1=是（明确标记后免于 BOM 映射校验）' AFTER `parameter_json`;
