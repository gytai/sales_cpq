-- ============================================================
-- M2 报价定价上下文维度（GYTAI-70）
-- cpq_quote 增加 company（我方公司维度）与 market_scope（国内/国际）
-- 两列，供提交前重算/提交时构建 PricingService 请求上下文，
-- 与价格表/三层策略的 company、market_scope 范围维度对齐（P50 步骤一字段）。
--
-- 适用范围：
--   空库：install.sql 已包含本脚本结果，`php think cpq:install` 标记已执行；
--   已有库：`php think cpq:upgrade` 按文件名序执行。
--
-- 恢复说明（回滚）：
--   从备份恢复为首选（见 database/cpq/README.md）。库内手工反向：
--     ALTER TABLE `__PREFIX__cpq_quote`
--       DROP COLUMN `market_scope`,
--       DROP COLUMN `company`;
-- ============================================================

ALTER TABLE `__PREFIX__cpq_quote`
  ADD COLUMN `company` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '我方公司维度(匹配价格表/策略范围)' AFTER `currency`,
  ADD COLUMN `market_scope` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '市场范围:domestic/international/空=按客户国家推导' AFTER `company`;
