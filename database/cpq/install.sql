SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_product_series` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '系列编码',
  `name` VARCHAR(120) NOT NULL COMMENT '系列名称',
  `name_en` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '英文名称',
  `business_unit` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '业务板块',
  `product_line` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '产品线',
  `brand` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '品牌',
  `default_unit` VARCHAR(32) NOT NULL DEFAULT 'set' COMMENT '默认单位',
  `default_currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '默认币种',
  `description` TEXT NULL COMMENT '产品说明',
  `image` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '产品图片',
  `effective_date` DATE NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','pending','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态:draft=草稿,pending=待审批,published=已发布,expired=已失效',
  `weigh` INT NOT NULL DEFAULT 0 COMMENT '权重',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_product_series_code` (`code`),
  KEY `idx_cpq_product_series_line_status` (`product_line`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ产品系列';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_product_model` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `series_id` BIGINT UNSIGNED NOT NULL COMMENT '产品系列ID',
  `code` VARCHAR(64) NOT NULL COMMENT '型号编码',
  `name` VARCHAR(120) NOT NULL COMMENT '型号名称',
  `name_en` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '英文名称',
  `category_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '产品分类',
  `base_item_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '基础物料编码',
  `unit` VARCHAR(32) NOT NULL DEFAULT 'set' COMMENT '计量单位',
  `allow_custom` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '允许销售自定义:0=否,1=是',
  `allow_overseas` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '允许海外销售:0=否,1=是',
  `effective_date` DATE NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','pending','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态:draft=草稿,pending=待审批,published=已发布,expired=已失效',
  `weigh` INT NOT NULL DEFAULT 0 COMMENT '权重',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_product_model_code` (`code`),
  KEY `idx_cpq_product_model_series_status` (`series_id`,`status`),
  CONSTRAINT `fk_cpq_product_model_series` FOREIGN KEY (`series_id`) REFERENCES `__PREFIX__cpq_product_series` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ产品型号';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_parameter_definition` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '参数编码',
  `name` VARCHAR(120) NOT NULL COMMENT '参数名称',
  `value_type` ENUM('text','number','boolean','select') NOT NULL DEFAULT 'text' COMMENT '值类型:text=文本,number=数值,boolean=布尔,select=选项',
  `unit` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '单位',
  `option_values` TEXT NULL COMMENT '可选值JSON',
  `validation_rule` TEXT NULL COMMENT '校验规则JSON',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=隐藏',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_parameter_definition_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ技术参数定义';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_model_parameter` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `model_id` BIGINT UNSIGNED NOT NULL COMMENT '产品型号ID',
  `parameter_id` BIGINT UNSIGNED NOT NULL COMMENT '参数定义ID',
  `value` TEXT NULL COMMENT '参数值',
  `is_configurable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '可配置:0=否,1=是',
  `is_required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '必填:0=否,1=是',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_model_parameter` (`model_id`,`parameter_id`),
  CONSTRAINT `fk_cpq_model_parameter_model` FOREIGN KEY (`model_id`) REFERENCES `__PREFIX__cpq_product_model` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_cpq_model_parameter_parameter` FOREIGN KEY (`parameter_id`) REFERENCES `__PREFIX__cpq_parameter_definition` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ型号技术参数';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_option_group` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '配置组编码',
  `name` VARCHAR(120) NOT NULL COMMENT '配置组名称',
  `name_en` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '英文名称',
  `input_type` ENUM('single','multiple','number','text','readonly') NOT NULL DEFAULT 'single' COMMENT '控件类型:single=单选,multiple=多选,number=数值,text=文本,readonly=只读计算值',
  `is_required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '必选:0=否,1=是',
  `min_select` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最少选择数',
  `max_select` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '最多选择数',
  `affects_price` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '影响价格:0=否,1=是',
  `affects_bom` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '影响BOM:0=否,1=是',
  `affects_lead_time` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '影响交期:0=否,1=是',
  `affects_weight` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '影响重量:0=否,1=是',
  `help_text` TEXT NULL COMMENT '帮助说明',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=隐藏',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_option_group_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ配置组';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_option_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `group_id` BIGINT UNSIGNED NOT NULL COMMENT '配置组ID',
  `code` VARCHAR(64) NOT NULL COMMENT '选项编码',
  `name` VARCHAR(120) NOT NULL COMMENT '选项名称',
  `name_en` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '英文名称',
  `material_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '物料编码',
  `default_qty` DECIMAL(18,4) NOT NULL DEFAULT 1.0000 COMMENT '默认数量',
  `min_qty` DECIMAL(18,4) NOT NULL DEFAULT 0.0000 COMMENT '最小数量',
  `max_qty` DECIMAL(18,4) NULL COMMENT '最大数量',
  `step` DECIMAL(18,4) NOT NULL DEFAULT 1.0000 COMMENT '数量步长',
  `price_key` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '增量价格引用键',
  `cost_key` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '增量成本引用键',
  `image` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '图片',
  `parameter_json` TEXT NULL COMMENT '技术参数JSON',
  `weigh` INT NOT NULL DEFAULT 0 COMMENT '权重',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=隐藏',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_option_value_code` (`group_id`,`code`),
  KEY `idx_cpq_option_value_group_status` (`group_id`,`status`),
  CONSTRAINT `fk_cpq_option_value_group` FOREIGN KEY (`group_id`) REFERENCES `__PREFIX__cpq_option_group` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ配置选项';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_model_option_group` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `model_id` BIGINT UNSIGNED NOT NULL COMMENT '产品型号ID',
  `group_id` BIGINT UNSIGNED NOT NULL COMMENT '配置组ID',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
  `is_visible` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '可见:0=否,1=是',
  `is_required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '必选:0=否,1=是',
  `default_value` TEXT NULL COMMENT '默认值JSON',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_model_option_group` (`model_id`,`group_id`),
  CONSTRAINT `fk_cpq_model_option_group_model` FOREIGN KEY (`model_id`) REFERENCES `__PREFIX__cpq_product_model` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_cpq_model_option_group_group` FOREIGN KEY (`group_id`) REFERENCES `__PREFIX__cpq_option_group` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ型号配置结构';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_config_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '规则编码',
  `name` VARCHAR(120) NOT NULL COMMENT '规则名称',
  `description` TEXT NULL COMMENT '规则说明',
  `type` ENUM('REQUIRES','EXCLUDES','ONE_OF','MIN_MAX','VISIBILITY','DEFAULT','FORMULA','WARNING') NOT NULL COMMENT '规则类型',
  `model_id` BIGINT UNSIGNED NULL COMMENT '适用型号ID',
  `product_line` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '适用产品线',
  `condition_json` TEXT NOT NULL COMMENT '条件JSON',
  `action_json` TEXT NOT NULL COMMENT '动作JSON',
  `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级',
  `severity` ENUM('blocking','warning') NOT NULL DEFAULT 'blocking' COMMENT '严重级别:blocking=阻断,warning=警告',
  `message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '提示信息',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `effective_date` DATE NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `status` ENUM('draft','pending','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态:draft=草稿,pending=待审批,published=已发布,expired=已失效',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_config_rule_code_version` (`code`,`version`),
  KEY `idx_cpq_config_rule_scope_status` (`model_id`,`product_line`,`status`),
  CONSTRAINT `fk_cpq_config_rule_model` FOREIGN KEY (`model_id`) REFERENCES `__PREFIX__cpq_product_model` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ配置规则';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_config_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '模板编码',
  `name` VARCHAR(120) NOT NULL COMMENT '模板名称',
  `model_id` BIGINT UNSIGNED NOT NULL COMMENT '产品型号ID',
  `market_scope` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '适用市场',
  `customer_level` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '客户等级',
  `config_json` LONGTEXT NOT NULL COMMENT '配置JSON',
  `description` TEXT NULL COMMENT '推荐说明',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','pending','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态:draft=草稿,pending=待审批,published=已发布,expired=已失效',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_config_template_code_version` (`code`,`version`),
  KEY `idx_cpq_config_template_model_status` (`model_id`,`status`),
  CONSTRAINT `fk_cpq_config_template_model` FOREIGN KEY (`model_id`) REFERENCES `__PREFIX__cpq_product_model` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ配置模板';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_accessory_service` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '编码',
  `type` VARCHAR(32) NOT NULL COMMENT '类型',
  `name` VARCHAR(120) NOT NULL COMMENT '名称',
  `name_en` VARCHAR(160) NOT NULL DEFAULT '' COMMENT '英文名称',
  `unit` VARCHAR(32) NOT NULL DEFAULT 'item' COMMENT '单位',
  `product_line` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '适用产品线',
  `tax_category` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '默认税类',
  `is_inventory_item` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '库存物料:0=否,1=是',
  `description` TEXT NULL COMMENT '销售说明',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态:normal=正常,hidden=隐藏',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_accessory_service_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ配件与服务';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_bom_mapping` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `model_id` BIGINT UNSIGNED NOT NULL COMMENT '产品型号ID',
  `option_value_id` BIGINT UNSIGNED NULL COMMENT '配置选项ID',
  `material_code` VARCHAR(64) NOT NULL COMMENT '物料编码',
  `qty_formula` VARCHAR(500) NOT NULL DEFAULT '1' COMMENT '数量公式',
  `unit` VARCHAR(32) NOT NULL DEFAULT 'item' COMMENT '单位',
  `substitute_material_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '替代料编码',
  `loss_rate` DECIMAL(9,6) NOT NULL DEFAULT 0 COMMENT '损耗率',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态:draft=草稿,published=已发布,expired=已失效',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_cpq_bom_mapping_model_status` (`model_id`,`status`),
  CONSTRAINT `fk_cpq_bom_mapping_model` FOREIGN KEY (`model_id`) REFERENCES `__PREFIX__cpq_product_model` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_cpq_bom_mapping_option` FOREIGN KEY (`option_value_id`) REFERENCES `__PREFIX__cpq_option_value` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ配置BOM映射';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_price_book` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '价格表编码',
  `name` VARCHAR(120) NOT NULL COMMENT '价格表名称',
  `company` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '公司',
  `business_unit` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '业务板块',
  `market_scope` ENUM('domestic','international','all') NOT NULL DEFAULT 'all' COMMENT '市场范围',
  `currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '币种',
  `tax_mode` ENUM('tax_exclusive','tax_inclusive') NOT NULL DEFAULT 'tax_exclusive' COMMENT '含税方式',
  `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级',
  `effective_date` DATE NOT NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','pending','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_price_book_code_version` (`code`,`version`),
  KEY `idx_cpq_price_book_scope` (`company`,`business_unit`,`market_scope`,`currency`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ价格表';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_price_entry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `price_book_id` BIGINT UNSIGNED NOT NULL COMMENT '价格表ID',
  `target_type` ENUM('model','option','accessory_service') NOT NULL COMMENT '定价对象类型',
  `target_id` BIGINT UNSIGNED NOT NULL COMMENT '定价对象ID',
  `amount` DECIMAL(18,4) NOT NULL COMMENT '价格',
  `unit` VARCHAR(32) NOT NULL DEFAULT 'item' COMMENT '单位',
  `min_qty` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '最小数量',
  `max_qty` DECIMAL(18,4) NULL COMMENT '最大数量',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_price_entry` (`price_book_id`,`target_type`,`target_id`,`min_qty`),
  KEY `idx_cpq_price_entry_target` (`target_type`,`target_id`),
  CONSTRAINT `fk_cpq_price_entry_book` FOREIGN KEY (`price_book_id`) REFERENCES `__PREFIX__cpq_price_book` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ价格条目';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_price_policy` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '策略编码',
  `name` VARCHAR(120) NOT NULL COMMENT '策略名称',
  `dimension_key` CHAR(64) NOT NULL COMMENT '维度哈希',
  `company` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '公司',
  `business_unit` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '业务板块',
  `market_scope` ENUM('domestic','international','all') NOT NULL DEFAULT 'all' COMMENT '市场范围',
  `region_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '区域编码',
  `customer_level` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '客户等级',
  `agent_level` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '代理等级',
  `customer_id` BIGINT UNSIGNED NULL COMMENT '客户ID',
  `product_line` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '产品线',
  `target_type` ENUM('model','option','accessory_service') NOT NULL COMMENT '定价对象类型',
  `target_id` BIGINT UNSIGNED NOT NULL COMMENT '定价对象ID',
  `currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '币种',
  `unit` VARCHAR(32) NOT NULL DEFAULT 'item' COMMENT '单位',
  `guide_price` DECIMAL(18,4) NOT NULL COMMENT '指导价',
  `line_floor` DECIMAL(18,4) NOT NULL COMMENT '产线控制价',
  `company_floor` DECIMAL(18,4) NOT NULL COMMENT '公司控制价',
  `cost` DECIMAL(18,4) NOT NULL DEFAULT 0 COMMENT '成本',
  `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级',
  `effective_date` DATE NOT NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','pending','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_price_policy_code_version` (`code`,`version`),
  KEY `idx_cpq_price_policy_match` (`target_type`,`target_id`,`currency`,`status`,`priority`),
  KEY `idx_cpq_price_policy_dimension` (`dimension_key`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ三层价格策略';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_price_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '规则编码',
  `name` VARCHAR(120) NOT NULL COMMENT '规则名称',
  `condition_json` TEXT NOT NULL COMMENT '条件JSON',
  `adjustment_type` ENUM('fixed','amount','discount','factor') NOT NULL COMMENT '调整类型',
  `adjustment_target` ENUM('base','option','service','freight','subtotal') NOT NULL DEFAULT 'subtotal' COMMENT '调整对象',
  `adjustment_value` DECIMAL(18,8) NOT NULL COMMENT '调整值',
  `can_stack` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '允许叠加',
  `exclusive_group` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '互斥组',
  `minimum_amount` DECIMAL(18,4) NULL COMMENT '保底金额',
  `maximum_amount` DECIMAL(18,4) NULL COMMENT '封顶金额',
  `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级',
  `effective_date` DATE NOT NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','pending','published','expired') NOT NULL DEFAULT 'draft' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_price_rule_code_version` (`code`,`version`),
  KEY `idx_cpq_price_rule_status_priority` (`status`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ价格规则';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_exchange_rate` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `source_currency` CHAR(3) NOT NULL COMMENT '源币种',
  `target_currency` CHAR(3) NOT NULL COMMENT '目标币种',
  `rate` DECIMAL(18,8) NOT NULL COMMENT '汇率',
  `source` VARCHAR(64) NOT NULL DEFAULT 'manual' COMMENT '汇率来源',
  `effective_date` DATE NOT NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_exchange_rate` (`source_currency`,`target_currency`,`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ汇率';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_tax_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '税率编码',
  `country_code` VARCHAR(8) NOT NULL DEFAULT '' COMMENT '国家地区编码',
  `region_code` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '销售区域编码',
  `product_type` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '产品类型',
  `rate` DECIMAL(9,6) NOT NULL COMMENT '税率',
  `effective_date` DATE NOT NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_tax_rule_code_date` (`code`,`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ税率规则';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_fee_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '费用规则编码',
  `name` VARCHAR(120) NOT NULL COMMENT '费用规则名称',
  `fee_type` VARCHAR(32) NOT NULL COMMENT '费用类型',
  `condition_json` TEXT NOT NULL COMMENT '条件JSON',
  `calculation_type` ENUM('fixed','per_quantity','percentage') NOT NULL COMMENT '计算类型',
  `value` DECIMAL(18,8) NOT NULL COMMENT '计算值',
  `currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '币种',
  `include_in_margin` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '计入毛利',
  `include_in_floor` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '计入价格控制',
  `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级',
  `effective_date` DATE NOT NULL COMMENT '生效日期',
  `expiry_date` DATE NULL COMMENT '失效日期',
  `status` ENUM('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_fee_rule_code_date` (`code`,`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ费用规则';

SET FOREIGN_KEY_CHECKS = 1;
