-- ============================================================
-- M2 客户渠道与价格主数据（GYTAI-69）
--
-- 新增客户与渠道主数据表：客户等级、代理等级、销售区域（树）、
-- 销售组织（树）、销售组织成员、客户、代理商；新增发布版本表
-- cpq_release_version（价格表/价格策略/价格规则发布后生成不可变版本，
-- 方案 P30/P37、§6.2）。
--
-- 价格域表（cpq_price_book / cpq_price_entry / cpq_price_policy /
-- cpq_price_rule / cpq_exchange_rate / cpq_tax_rule / cpq_fee_rule）
-- 已在 install.sql 随 M1 建立，本脚本不再变更其结构。
--
-- 注意：cpq_customer.agent_id 与 cpq_agent.customer_id 互为引用，
-- 为避免循环外键，agent_id 不建物理外键，由服务层实施完整性校验
-- （与 docs/cpq/data-model.md 第 2 节"服务层等价完整性校验"约定一致）。
--
-- 适用范围：
--   空库：先执行 install.sql（已包含本脚本全部结果），再由
--         `php think install` 将本脚本标记为已执行；
--   已有库：`php think cpq:upgrade` 按文件名序执行未应用的脚本。
--
-- 恢复说明（回滚）：
--   本脚本为前滚脚本，回滚前必须先备份（见 database/cpq/README.md）。
--   如需在库内手工反向执行：
--     DROP TABLE IF EXISTS `__PREFIX__cpq_release_version`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_agent`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_customer`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_sales_org_member`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_sales_org`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_region`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_agent_level`;
--     DROP TABLE IF EXISTS `__PREFIX__cpq_customer_level`;
-- ============================================================

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_customer_level` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '等级编码',
  `name` VARCHAR(120) NOT NULL COMMENT '等级名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小等级越高)',
  `default_discount` DECIMAL(9,6) NULL COMMENT '默认折扣率(0-1]',
  `market_scope` ENUM('domestic','international','all') NOT NULL DEFAULT 'all' COMMENT '适用市场',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=停用',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_customer_level_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ客户等级';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_agent_level` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '等级编码',
  `name` VARCHAR(120) NOT NULL COMMENT '等级名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序(越小等级越高)',
  `default_discount` DECIMAL(9,6) NULL COMMENT '默认折扣率(0-1]',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=停用',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_agent_level_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ代理等级';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_region` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '区域编码',
  `name` VARCHAR(120) NOT NULL COMMENT '区域名称',
  `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '父节点ID,0=根节点',
  `path` VARCHAR(500) NOT NULL DEFAULT '/' COMMENT '物化路径,如 /1/8/12/',
  `level` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '层级,根节点=1',
  `default_currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '默认币种',
  `sales_org_id` BIGINT UNSIGNED NULL COMMENT '负责销售组织ID',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=停用',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_region_code` (`code`),
  KEY `idx_cpq_region_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ销售区域';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_sales_org` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '组织编码',
  `name` VARCHAR(120) NOT NULL COMMENT '组织名称',
  `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上级组织ID,0=根节点',
  `path` VARCHAR(500) NOT NULL DEFAULT '/' COMMENT '物化路径,如 /1/8/12/',
  `level` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '层级,根节点=1',
  `manager_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '负责人管理员ID',
  `effective_date` DATE NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=停用',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_sales_org_code` (`code`),
  KEY `idx_cpq_sales_org_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ销售组织';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_sales_org_member` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `org_id` BIGINT UNSIGNED NOT NULL COMMENT '销售组织ID',
  `admin_id` INT UNSIGNED NOT NULL COMMENT '成员管理员ID',
  `role` VARCHAR(32) NOT NULL DEFAULT 'sales' COMMENT '组织内角色',
  `effective_date` DATE NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=停用',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_sales_org_member` (`org_id`,`admin_id`),
  KEY `idx_cpq_sales_org_member_admin` (`admin_id`),
  CONSTRAINT `fk_cpq_sales_org_member_org` FOREIGN KEY (`org_id`) REFERENCES `__PREFIX__cpq_sales_org` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ销售组织成员';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_customer` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '客户编码',
  `name` VARCHAR(120) NOT NULL COMMENT '客户名称',
  `name_en` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '英文名称',
  `type` VARCHAR(32) NOT NULL DEFAULT 'direct' COMMENT '客户类型:direct=直销,terminal=代理终端,other=其他',
  `credit_code` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '统一社会信用代码',
  `country_code` VARCHAR(8) NOT NULL DEFAULT 'CN' COMMENT '国家地区编码',
  `region_id` BIGINT UNSIGNED NULL COMMENT '销售区域ID',
  `customer_level_id` BIGINT UNSIGNED NULL COMMENT '客户等级ID',
  `agent_id` BIGINT UNSIGNED NULL COMMENT '所属代理商ID(终端客户,服务层校验,不建物理外键)',
  `default_currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '默认币种',
  `tax_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '税号',
  `payment_terms` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '付款条件',
  `trade_terms` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '贸易条款',
  `sales_org_id` BIGINT UNSIGNED NULL COMMENT '所属销售组织ID',
  `owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售负责人管理员ID',
  `status` ENUM('normal','disabled') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,disabled=停用',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_customer_code` (`code`),
  KEY `idx_cpq_customer_region` (`region_id`),
  KEY `idx_cpq_customer_level` (`customer_level_id`),
  KEY `idx_cpq_customer_agent` (`agent_id`),
  CONSTRAINT `fk_cpq_customer_region` FOREIGN KEY (`region_id`) REFERENCES `__PREFIX__cpq_region` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_cpq_customer_level` FOREIGN KEY (`customer_level_id`) REFERENCES `__PREFIX__cpq_customer_level` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_cpq_customer_sales_org` FOREIGN KEY (`sales_org_id`) REFERENCES `__PREFIX__cpq_sales_org` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ客户';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_agent` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '代理商编码',
  `customer_id` BIGINT UNSIGNED NOT NULL COMMENT '关联客户ID(代理商主体)',
  `agent_level_id` BIGINT UNSIGNED NULL COMMENT '代理等级ID',
  `authorized_regions` TEXT NULL COMMENT '授权销售区域ID JSON数组',
  `authorized_lines` TEXT NULL COMMENT '授权产品线编码JSON数组',
  `auth_start_date` DATE NULL COMMENT '授权生效日期',
  `auth_end_date` DATE NULL COMMENT '授权失效日期',
  `credit_limit` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '信用额度',
  `owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '负责人管理员ID',
  `status` ENUM('normal','disabled') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,disabled=停用',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_agent_code` (`code`),
  UNIQUE KEY `uk_cpq_agent_customer` (`customer_id`),
  CONSTRAINT `fk_cpq_agent_customer` FOREIGN KEY (`customer_id`) REFERENCES `__PREFIX__cpq_customer` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_cpq_agent_level` FOREIGN KEY (`agent_level_id`) REFERENCES `__PREFIX__cpq_agent_level` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ代理商';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_release_version` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `object_type` VARCHAR(64) NOT NULL COMMENT '对象逻辑表名:cpq_price_book/cpq_price_policy/cpq_price_rule',
  `object_id` BIGINT UNSIGNED NOT NULL COMMENT '对象ID',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '发布版本号',
  `content_hash` CHAR(64) NOT NULL COMMENT '发布内容SHA-256',
  `change_summary` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '变更摘要',
  `affected_product_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '影响产品数',
  `affected_customer_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '影响客户数',
  `submitted_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '提交人管理员ID',
  `approved_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批人管理员ID',
  `planned_effective_at` INT UNSIGNED NULL COMMENT '计划生效时间',
  `effective_at` INT UNSIGNED NULL COMMENT '实际生效时间',
  `status` ENUM('pending','published','withdrawn') NOT NULL DEFAULT 'pending' COMMENT '状态:pending=未生效,published=已生效,withdrawn=已撤回',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_release_version` (`object_type`,`object_id`,`version`),
  KEY `idx_cpq_release_version_object` (`object_type`,`object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ发布版本(已生效不可变)';
