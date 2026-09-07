-- ============================================================
-- M2 报价草稿、快照与修订（GYTAI-71）
--
-- 新增报价主表 cpq_quote、报价行 cpq_quote_line、
-- 配置快照 cpq_quote_config_snapshot、价格快照
-- cpq_quote_price_snapshot、条款快照 cpq_quote_term、
-- 附件 cpq_quote_attachment，以及报价修订版本
-- cpq_quote_revision（含完整快照冻结）。
--
-- 适用范围：
--   空库：先执行 install.sql（已包含本脚本全部结果），再由
--         `php think cpq:install` 将本脚本标记为已执行；
--   已有库：`php think cpq:upgrade` 按文件名序执行未应用的脚本。
--
-- 恢复说明（回滚）：
--   本脚本为前滚脚本，回滚前必须先备份（见 database/cpq/README.md）。
--   如需在库内手工反向执行：
--     DROP TABLE IF EXISTS `__PREFIX__cpq_quote_attachment`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_quote_term`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_quote_price_snapshot`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_quote_config_snapshot`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_quote_revision`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_quote_line`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_quote`;
-- ============================================================

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '报价编码',
  `name` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '报价名称',
  `description` TEXT NULL COMMENT '报价说明',
  `customer_id` BIGINT UNSIGNED NULL COMMENT '客户ID',
  `agent_id` BIGINT UNSIGNED NULL COMMENT '代理商ID',
  `sales_org_id` BIGINT UNSIGNED NULL COMMENT '销售组织ID',
  `owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售负责人管理员ID',
  `product_line` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '产品线编码',
  `currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '报价币种',
  `status` ENUM('draft','submitted','approved','sent','accepted','rejected','withdrawn','cancelled','expired','revised') NOT NULL DEFAULT 'draft' COMMENT '报价状态',
  `current_revision_no` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当前版本号(0=草稿无版本)',
  `optimistic_lock_version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '乐观锁版本',
  `idempotency_key` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '幂等键(提交防重复)',
  `final_price_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '最终价格哈希(提交后冻结)',
  `final_approval_level` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '最终审批等级(none/line/company/forbidden)',
  `submitted_at` INT UNSIGNED NULL COMMENT '提交时间',
  `approved_at` INT UNSIGNED NULL COMMENT '审批通过时间',
  `sent_at` INT UNSIGNED NULL COMMENT '发送时间',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_quote_code` (`code`),
  KEY `idx_cpq_quote_status` (`status`),
  KEY `idx_cpq_quote_customer` (`customer_id`),
  KEY `idx_cpq_quote_owner` (`owner_id`),
  KEY `idx_cpq_quote_product_line` (`product_line`),
  KEY `idx_cpq_quote_sales_org` (`sales_org_id`),
  KEY `idx_cpq_quote_idempotency` (`idempotency_key`),
  CONSTRAINT `fk_cpq_quote_customer` FOREIGN KEY (`customer_id`) REFERENCES `__PREFIX__cpq_customer` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_cpq_quote_agent` FOREIGN KEY (`agent_id`) REFERENCES `__PREFIX__cpq_agent` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_cpq_quote_sales_org` FOREIGN KEY (`sales_org_id`) REFERENCES `__PREFIX__cpq_sales_org` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价主表';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_revision` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价ID',
  `revision_no` INT UNSIGNED NOT NULL COMMENT '版本号(从1开始)',
  `status` ENUM('draft','frozen','submitted','approved','archived') NOT NULL DEFAULT 'draft' COMMENT '版本状态',
  `pricing_request_json` LONGTEXT NULL COMMENT '定价请求完整快照',
  `pricing_result_json` LONGTEXT NULL COMMENT '定价结果完整快照',
  `price_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '价格哈希',
  `approval_level` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '审批等级',
  `submittable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否可提交:0=否,1=是',
  `block_reasons_json` TEXT NULL COMMENT '阻断原因JSON',
  `config_snapshot_json` LONGTEXT NULL COMMENT '冻结时的配置快照',
  `price_snapshot_json` LONGTEXT NULL COMMENT '冻结时的价格快照(含trace)',
  `snapshot_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '完整快照哈希(去重)',
  `diff_from_previous_json` TEXT NULL COMMENT '与上一版本的差异摘要',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人管理员ID',
  `frozen_at` INT UNSIGNED NULL COMMENT '冻结时间',
  `submitted_at` INT UNSIGNED NULL COMMENT '提交时间',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_quote_revision` (`quote_id`,`revision_no`),
  KEY `idx_cpq_quote_revision_status` (`quote_id`,`status`),
  CONSTRAINT `fk_cpq_quote_revision_quote` FOREIGN KEY (`quote_id`) REFERENCES `__PREFIX__cpq_quote` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价版本(修订/冻结)';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_line` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价ID',
  `line_no` INT UNSIGNED NOT NULL COMMENT '行号(从1开始)',
  `model_id` BIGINT UNSIGNED NOT NULL COMMENT '产品型号ID',
  `quantity` DECIMAL(18,4) NOT NULL DEFAULT 1.0000 COMMENT '数量',
  `unit` VARCHAR(32) NOT NULL DEFAULT 'set' COMMENT '单位',
  `configuration_json` LONGTEXT NULL COMMENT '配置JSON(用户输入)',
  `configuration_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '配置哈希',
  `bom_json` LONGTEXT NULL COMMENT 'BOM结果JSON',
  `manual_discount` DECIMAL(9,6) NULL COMMENT '手工折扣(支付比例)',
  `discount_reason` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '折扣理由',
  `accessories_json` TEXT NULL COMMENT '配件/服务JSON',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_quote_line` (`quote_id`,`line_no`),
  KEY `idx_cpq_quote_line_model` (`model_id`),
  CONSTRAINT `fk_cpq_quote_line_quote` FOREIGN KEY (`quote_id`) REFERENCES `__PREFIX__cpq_quote` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_cpq_quote_line_model` FOREIGN KEY (`model_id`) REFERENCES `__PREFIX__cpq_product_model` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价行';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_config_snapshot` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `revision_id` BIGINT UNSIGNED NOT NULL COMMENT '版本ID',
  `quote_line_id` BIGINT UNSIGNED NOT NULL COMMENT '报价行ID',
  `model_id` BIGINT UNSIGNED NOT NULL COMMENT '产品型号ID',
  `model_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '型号编码(快照)',
  `model_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '型号版本(快照)',
  `configuration` LONGTEXT NULL COMMENT '规范化配置JSON',
  `configuration_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '配置哈希',
  `applied_rules_json` TEXT NULL COMMENT '命中的配置规则编码列表',
  `is_valid` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '校验是否通过',
  `validation_errors_json` TEXT NULL COMMENT '校验错误JSON',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_quote_config_snapshot` (`revision_id`,`quote_line_id`),
  KEY `idx_cpq_quote_config_snapshot_line` (`quote_line_id`),
  CONSTRAINT `fk_cpq_quote_config_snapshot_revision` FOREIGN KEY (`revision_id`) REFERENCES `__PREFIX__cpq_quote_revision` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_cpq_quote_config_snapshot_line` FOREIGN KEY (`quote_line_id`) REFERENCES `__PREFIX__cpq_quote_line` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价配置快照';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_price_snapshot` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `revision_id` BIGINT UNSIGNED NOT NULL COMMENT '版本ID',
  `quote_line_id` BIGINT UNSIGNED NOT NULL COMMENT '报价行ID',
  `model_id` BIGINT UNSIGNED NOT NULL COMMENT '产品型号ID',
  `model_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '型号编码(快照)',
  `quantity` DECIMAL(18,4) NOT NULL DEFAULT 1.0000 COMMENT '数量(快照)',
  `pricing_currency` CHAR(3) NOT NULL DEFAULT '' COMMENT '价格表币种',
  `quote_currency` CHAR(3) NOT NULL DEFAULT '' COMMENT '报价币种',
  `tax_mode` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '含税方式',
  `base_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '基础单价',
  `option_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '选项增量价合计',
  `service_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '配件/服务合计',
  `unit_subtotal` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '单价小计',
  `goods_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '货品金额(数量调整)',
  `goods_discounted` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '折扣后货品金额',
  `fees_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '费用合计',
  `untaxed_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '未税金额',
  `tax_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '税额',
  `total_amount` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '含税总额',
  `converted_amounts_json` TEXT NULL COMMENT '汇率转换后金额JSON',
  `manual_discount` DECIMAL(9,6) NULL COMMENT '手工折扣(支付比例)',
  `discount_reason` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '折扣理由',
  `control_unit_price` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '控制价单价',
  `classification` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '控制价分级',
  `approval_level` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '审批等级',
  `price_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '价格哈希',
  `price_trace_json` LONGTEXT NULL COMMENT '完整价格轨迹JSON',
  `price_book_snapshot_json` TEXT NULL COMMENT '价格表快照',
  `policy_snapshot_json` TEXT NULL COMMENT '策略快照',
  `tax_rule_snapshot_json` TEXT NULL COMMENT '税率规则快照',
  `exchange_rate_snapshot_json` TEXT NULL COMMENT '汇率快照',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_quote_price_snapshot` (`revision_id`,`quote_line_id`),
  KEY `idx_cpq_quote_price_snapshot_line` (`quote_line_id`),
  CONSTRAINT `fk_cpq_quote_price_snapshot_revision` FOREIGN KEY (`revision_id`) REFERENCES `__PREFIX__cpq_quote_revision` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_cpq_quote_price_snapshot_line` FOREIGN KEY (`quote_line_id`) REFERENCES `__PREFIX__cpq_quote_line` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价价格快照';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_term` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价ID',
  `revision_id` BIGINT UNSIGNED NULL COMMENT '版本ID(冻结时填入)',
  `term_type` VARCHAR(64) NOT NULL COMMENT '条款类型(payment/trade/warranty/delivery/other)',
  `term_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '条款编码',
  `content` TEXT NOT NULL COMMENT '条款内容',
  `is_editable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '是否可编辑:0=系统锁定,1=可编辑',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_cpq_quote_term_quote` (`quote_id`),
  KEY `idx_cpq_quote_term_revision` (`revision_id`),
  CONSTRAINT `fk_cpq_quote_term_quote` FOREIGN KEY (`quote_id`) REFERENCES `__PREFIX__cpq_quote` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_cpq_quote_term_revision` FOREIGN KEY (`revision_id`) REFERENCES `__PREFIX__cpq_quote_revision` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价条款快照';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_attachment` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价ID',
  `original_name` VARCHAR(255) NOT NULL COMMENT '原始文件名',
  `storage_path` VARCHAR(500) NOT NULL COMMENT '存储路径',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `mime_type` VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'MIME类型',
  `uploaded_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上传人管理员ID',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_cpq_quote_attachment_quote` (`quote_id`),
  CONSTRAINT `fk_cpq_quote_attachment_quote` FOREIGN KEY (`quote_id`) REFERENCES `__PREFIX__cpq_quote` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价附件';
