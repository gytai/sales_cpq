-- ============================================================
-- M2 确定性定价：价格策略增加“指定代理商”维度（GYTAI-68）
--
-- 价格策略八级匹配要求把“指定代理商”置于“指定客户”之后、
-- “客户/代理等级 + 区域”之前。agent_id 不建物理外键：cpq_agent
-- 是后置引入的渠道表，完整性由后台选择器与定价服务运行期校验保证。
--
-- 空库由 install.sql 直接获得最终结构，cpq:install 会将本脚本标记
-- 为已执行；已有库由 cpq:upgrade 前滚执行。
--
-- 恢复说明（回滚前先备份）：
--   ALTER TABLE `__PREFIX__cpq_price_policy`
--     DROP INDEX `idx_cpq_price_policy_agent`,
--     DROP COLUMN `agent_id`;
-- ============================================================

ALTER TABLE `__PREFIX__cpq_price_policy`
  ADD COLUMN `agent_id` BIGINT UNSIGNED NULL COMMENT '指定代理商ID(服务层校验)' AFTER `customer_id`,
  ADD KEY `idx_cpq_price_policy_agent` (`agent_id`);
