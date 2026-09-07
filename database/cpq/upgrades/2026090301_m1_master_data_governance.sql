-- ============================================================
-- M1 产品与配置主数据治理（GYTAI-67）
-- 1. 产品系列/型号唯一键从 (code) 调整为 (code, version)，
--    支持"已发布不可编辑、复制新版本"的版本化生命周期；
-- 2. 新增 CPQ 审计日志表 cpq_audit_log（只增不删）；
-- 3. 新增管理员产品线数据范围表 cpq_admin_product_line；
-- 4. 新增增量升级执行记录表 cpq_migration。
--
-- 适用范围：
--   空库：先执行 install.sql（已包含本脚本全部结果），再由
--         `php think cpq:install` 将本脚本标记为已执行；
--   已有库：`php think cpq:upgrade` 按文件名序执行未应用的脚本。
--
-- 恢复说明（回滚）：
--   本脚本为前滚脚本，回滚前必须先备份（见 database/cpq/README.md）。
--   从备份恢复是首选方式。如需在库内手工反向执行（会丢失审计与
--   范围数据，且要求同编码不存在多版本行），反向 SQL 如下：
--     ALTER TABLE `__PREFIX__cpq_product_series`
--       DROP INDEX `uk_cpq_product_series_code_version`,
--       ADD UNIQUE KEY `uk_cpq_product_series_code` (`code`);
--     ALTER TABLE `__PREFIX__cpq_product_model`
--       DROP INDEX `uk_cpq_product_model_code_version`,
--       ADD UNIQUE KEY `uk_cpq_product_model_code` (`code`);
--     DROP TABLE IF EXISTS `__PREFIX__cpq_admin_product_line`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_audit_log`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_migration`;
-- ============================================================

ALTER TABLE `__PREFIX__cpq_product_series`
  DROP INDEX `uk_cpq_product_series_code`,
  ADD UNIQUE KEY `uk_cpq_product_series_code_version` (`code`,`version`);

ALTER TABLE `__PREFIX__cpq_product_model`
  DROP INDEX `uk_cpq_product_model_code`,
  ADD UNIQUE KEY `uk_cpq_product_model_code_version` (`code`,`version`);

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `trace_id` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '追踪号',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作管理员ID',
  `username` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '操作管理员',
  `action` VARCHAR(32) NOT NULL COMMENT '动作:create/update/delete/submit/publish/expire/copy',
  `object_type` VARCHAR(64) NOT NULL COMMENT '对象表名',
  `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '对象ID',
  `object_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '对象编码',
  `detail_json` TEXT NULL COMMENT '变更明细JSON',
  `ip` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '来源IP',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_cpq_audit_log_object` (`object_type`,`object_id`,`id`),
  KEY `idx_cpq_audit_log_user_time` (`user_id`,`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ审计日志(只增不删)';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_admin_product_line` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `admin_id` INT UNSIGNED NOT NULL COMMENT '管理员ID',
  `product_line` VARCHAR(64) NOT NULL COMMENT '产品线编码(*表示全部)',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_admin_product_line` (`admin_id`,`product_line`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ管理员产品线数据范围';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_migration` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` VARCHAR(190) NOT NULL COMMENT '升级脚本文件名',
  `applied_at` INT UNSIGNED NULL COMMENT '执行时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_migration_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ增量升级执行记录';
