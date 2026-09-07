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
  UNIQUE KEY `uk_cpq_product_series_code_version` (`code`,`version`),
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
  UNIQUE KEY `uk_cpq_product_model_code_version` (`code`,`version`),
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
  `no_material` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '不产生物料:0=否,1=是（明确标记后免于 BOM 映射校验）',
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
  `agent_id` BIGINT UNSIGNED NULL COMMENT '指定代理商ID(不建物理外键,避免与后置建表顺序耦合)',
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
  KEY `idx_cpq_price_policy_agent` (`agent_id`),
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

-- ============================================================
-- M2 客户渠道与价格发布版本（GYTAI-69）
-- 与 upgrades/2026090303_m2_customer_channel_price.sql 保持一致
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

-- ============================================================
-- M2 报价草稿、快照与修订（GYTAI-71）
-- 与 upgrades/2026090402_m2_quote_draft_snapshot_revision.sql 保持一致
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
  `company` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '我方公司维度(匹配价格表/策略范围)',
  `market_scope` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '市场范围:domestic/international/空=按客户国家推导',
  `status` ENUM('draft','submitted','approved','sent','accepted','rejected','withdrawn','returned','cancelled','expired','revised') NOT NULL DEFAULT 'draft' COMMENT '报价状态',
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
  `manual_discount` DECIMAL(9,6) NULL DEFAULT 1.000000 COMMENT '手工折扣(支付比例,缺省为1即无折扣)',
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

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_migration` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` VARCHAR(190) NOT NULL COMMENT '升级脚本文件名',
  `applied_at` INT UNSIGNED NULL COMMENT '执行时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_migration_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ增量升级执行记录';

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- M3 审批状态机、委托代理、报价模板与打印记录（GYTAI-72/GYTAI-75）
-- 增量脚本见 upgrades/2026090404_m3_approval_print.sql
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_approval_instance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价ID',
  `revision_no` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '报价版本号(冻结快照版本)',
  `approval_level` VARCHAR(16) NOT NULL DEFAULT 'none' COMMENT '审批等级:none/line/company',
  `status` VARCHAR(24) NOT NULL DEFAULT 'active' COMMENT '实例状态:active/completed/rejected/returned/withdrawn/cancelled',
  `current_node` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '当前节点:sales_confirm/line_approval/company_approval',
  `initiator_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发起人(提交人)管理员ID',
  `sla_deadline` INT UNSIGNED NULL COMMENT '当前节点SLA截止时间',
  `submitted_at` INT UNSIGNED NULL COMMENT '提交时间',
  `completed_at` INT UNSIGNED NULL COMMENT '完成时间',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_approval_instance` (`quote_id`,`revision_no`),
  KEY `idx_cpq_approval_instance_status` (`status`,`current_node`),
  KEY `idx_cpq_approval_instance_initiator` (`initiator_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ审批实例(固定路径)';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_approval_task` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `instance_id` BIGINT UNSIGNED NOT NULL COMMENT '审批实例ID',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价ID',
  `revision_no` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '报价版本号',
  `node` VARCHAR(32) NOT NULL COMMENT '节点:sales_confirm/line_approval/company_approval',
  `assignee_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批人管理员ID',
  `is_required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=加签必办任务(会签),0=候选人任务(或签)',
  `status` VARCHAR(24) NOT NULL DEFAULT 'pending' COMMENT '任务状态:pending/completed/rejected/returned/transferred/superseded/cancelled',
  `arrived_at` INT UNSIGNED NULL COMMENT '到达时间',
  `sla_deadline` INT UNSIGNED NULL COMMENT 'SLA截止时间',
  `acted_at` INT UNSIGNED NULL COMMENT '处理时间',
  `action` VARCHAR(24) NOT NULL DEFAULT '' COMMENT '最终动作:confirm/approve/reject/return/transfer/add_sign',
  `idempotency_key` VARCHAR(128) NULL DEFAULT NULL COMMENT '动作幂等键',
  `comment` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '审批意见',
  `reason_category` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '原因分类',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_cpq_approval_task_assignee` (`assignee_id`,`status`),
  KEY `idx_cpq_approval_task_instance` (`instance_id`,`node`,`status`),
  KEY `idx_cpq_approval_task_quote` (`quote_id`,`revision_no`),
  CONSTRAINT `fk_cpq_approval_task_instance` FOREIGN KEY (`instance_id`) REFERENCES `__PREFIX__cpq_approval_instance` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ审批任务';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_approval_action` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `instance_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批实例ID',
  `task_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审批任务ID(0=实例级动作)',
  `quote_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '报价ID',
  `revision_no` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '报价版本号',
  `node` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '节点',
  `action` VARCHAR(24) NOT NULL COMMENT '动作:confirm/approve/reject/return/transfer/add_sign/withdraw/urge/cancel',
  `actor_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '实际处理人管理员ID',
  `actor_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '处理人',
  `delegate_from_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '委托人管理员ID(代理处理时),0=本人处理',
  `comment` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '意见',
  `reason_category` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '原因分类',
  `before_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '动作前报价价格哈希',
  `after_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '动作后报价价格哈希',
  `idempotency_key` VARCHAR(128) NULL DEFAULT NULL COMMENT '幂等键',
  `ip` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '来源IP',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_approval_action_idem` (`idempotency_key`),
  KEY `idx_cpq_approval_action_instance` (`instance_id`,`id`),
  KEY `idx_cpq_approval_action_quote` (`quote_id`,`revision_no`),
  KEY `idx_cpq_approval_action_actor` (`actor_id`,`createtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ审批动作(只增不删)';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_approval_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '规则编码',
  `name` VARCHAR(120) NOT NULL COMMENT '规则名称',
  `node` VARCHAR(32) NOT NULL COMMENT '节点:line_approval/company_approval(销售确认节点固定由报价负责人处理)',
  `approver_role` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '审批角色编码(line_pricer/company_pricer),空=仅显式候选人',
  `product_line` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '适用产品线,空=全部',
  `candidate_admin_ids` TEXT NULL COMMENT '显式候选人管理员ID(JSON数组)',
  `sla_hours` INT UNSIGNED NOT NULL DEFAULT 24 COMMENT 'SLA时长(小时)',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_approval_rule_code` (`code`),
  KEY `idx_cpq_approval_rule_node` (`node`,`status`,`product_line`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ审批规则(固定路径候选人与SLA)';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_approval_delegation` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `delegator_id` INT UNSIGNED NOT NULL COMMENT '委托人管理员ID',
  `delegate_id` INT UNSIGNED NOT NULL COMMENT '代理人管理员ID',
  `business_type` VARCHAR(32) NOT NULL DEFAULT 'approval' COMMENT '业务类型:approval',
  `product_line` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '适用产品线,空=全部',
  `starts_at` INT UNSIGNED NOT NULL COMMENT '开始时间',
  `ends_at` INT UNSIGNED NOT NULL COMMENT '结束时间',
  `reason` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '委托原因',
  `status` ENUM('pending','active','cancelled','rejected','expired') NOT NULL DEFAULT 'pending' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_cpq_approval_delegation_delegator` (`delegator_id`,`status`),
  KEY `idx_cpq_approval_delegation_delegate` (`delegate_id`,`status`),
  KEY `idx_cpq_approval_delegation_window` (`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ审批委托与代理';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_template` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` VARCHAR(64) NOT NULL COMMENT '模板编码',
  `name` VARCHAR(120) NOT NULL COMMENT '模板名称',
  `name_en` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '英文名称',
  `language` ENUM('zh','en') NOT NULL DEFAULT 'zh' COMMENT '模板语言',
  `market_scope` ENUM('all','domestic','international') NOT NULL DEFAULT 'all' COMMENT '适用市场',
  `paper_size` ENUM('A4','Letter') NOT NULL DEFAULT 'A4' COMMENT '纸张',
  `is_default` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否该语言+市场默认模板',
  `content_json` MEDIUMTEXT NULL COMMENT '模板结构JSON(封面/公司信息/板块/条款/签章/水印)',
  `allowed_variables` TEXT NULL COMMENT '变量白名单(JSON数组)',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '版本',
  `status` ENUM('draft','published','disabled') NOT NULL DEFAULT 'draft' COMMENT '状态',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_quote_template_code` (`code`),
  KEY `idx_cpq_quote_template_market` (`language`,`market_scope`,`status`,`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价模板(中英文)';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_quote_document` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `quote_id` BIGINT UNSIGNED NOT NULL COMMENT '报价ID',
  `revision_no` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '报价版本号',
  `template_id` BIGINT UNSIGNED NOT NULL COMMENT '模板ID',
  `language` ENUM('zh','en') NOT NULL DEFAULT 'zh' COMMENT '语言',
  `currency` CHAR(3) NOT NULL DEFAULT 'CNY' COMMENT '报价币种',
  `status` ENUM('pending','processing','succeeded','failed') NOT NULL DEFAULT 'pending' COMMENT '任务状态',
  `file_path` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文件相对路径(相对根目录)',
  `file_hash` CHAR(64) NOT NULL DEFAULT '' COMMENT '文件SHA-256',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件字节数',
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下载次数',
  `retry_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '重试次数',
  `error_message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '失败原因',
  `requested_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '发起人管理员ID',
  `generated_at` INT UNSIGNED NULL COMMENT '生成完成时间',
  `createtime` INT UNSIGNED NULL COMMENT '创建时间',
  `updatetime` INT UNSIGNED NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_cpq_quote_document_quote` (`quote_id`,`revision_no`),
  KEY `idx_cpq_quote_document_status` (`status`,`createtime`),
  CONSTRAINT `fk_cpq_quote_document_quote` FOREIGN KEY (`quote_id`) REFERENCES `__PREFIX__cpq_quote` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ报价打印记录(异步PDF)';

-- M3 异步任务、导入导出与集成平台（GYTAI-73，P94/P100-P105）。
-- 凭证仅保存 AES-256-GCM 密文；任务、导出下载与集成事件均有审计/重试状态。

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_dictionary_value` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dictionary_code` VARCHAR(64) NOT NULL COMMENT '字典分类编码',
  `value_code` VARCHAR(64) NOT NULL COMMENT '字典值编码',
  `label` VARCHAR(120) NOT NULL COMMENT '显示名',
  `status` ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
  `sort` INT NOT NULL DEFAULT 0,
  `createtime` INT UNSIGNED NULL,
  `updatetime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_dictionary_value` (`dictionary_code`,`value_code`),
  KEY `idx_cpq_dictionary_status` (`dictionary_code`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ业务字典值';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_dictionary_reference` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dictionary_value_id` BIGINT UNSIGNED NOT NULL,
  `business_type` VARCHAR(64) NOT NULL,
  `business_id` VARCHAR(64) NOT NULL,
  `createtime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_dictionary_reference` (`dictionary_value_id`,`business_type`,`business_id`),
  CONSTRAINT `fk_cpq_dictionary_reference_value` FOREIGN KEY (`dictionary_value_id`) REFERENCES `__PREFIX__cpq_dictionary_value` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ字典业务引用';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_number_rule` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `pattern` VARCHAR(120) NOT NULL DEFAULT '{YYYY}{SEQ6}' COMMENT '支持YYYY/MM/DD/SEQn/SCOPE',
  `period_type` ENUM('none','year','month','day') NOT NULL DEFAULT 'year',
  `initial_value` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('enabled','disabled') NOT NULL DEFAULT 'enabled',
  `createtime` INT UNSIGNED NULL,
  `updatetime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_number_rule_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ并发安全编号规则';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_number_counter` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id` BIGINT UNSIGNED NOT NULL,
  `scope_key` VARCHAR(64) NOT NULL DEFAULT '',
  `period_key` VARCHAR(16) NOT NULL DEFAULT '',
  `current_value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updatetime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_number_counter` (`rule_id`,`scope_key`,`period_key`),
  CONSTRAINT `fk_cpq_number_counter_rule` FOREIGN KEY (`rule_id`) REFERENCES `__PREFIX__cpq_number_rule` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ编号计数器';

INSERT IGNORE INTO `__PREFIX__cpq_number_rule`
  (`code`,`name`,`pattern`,`period_type`,`initial_value`,`status`,`createtime`,`updatetime`)
VALUES
  ('quote','报价编号','Q-{YYYY}{MM}{DD}{SEQ3}','day',1,'enabled',UNIX_TIMESTAMP(),UNIX_TIMESTAMP());

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_job` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key` CHAR(32) NOT NULL COMMENT '对外不可猜测任务ID',
  `type` VARCHAR(32) NOT NULL COMMENT 'pdf/excel_import/excel_export/erp_sync/email/scheduled_publish',
  `business_type` VARCHAR(64) NOT NULL DEFAULT '',
  `business_id` VARCHAR(64) NOT NULL DEFAULT '',
  `idempotency_hash` CHAR(64) NOT NULL,
  `status` ENUM('pending','processing','succeeded','failed','cancelled') NOT NULL DEFAULT 'pending',
  `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `payload_json` LONGTEXT NULL,
  `result_json` LONGTEXT NULL,
  `error_code` VARCHAR(64) NOT NULL DEFAULT '',
  `error_message` VARCHAR(500) NOT NULL DEFAULT '',
  `error_report_path` VARCHAR(255) NOT NULL DEFAULT '',
  `file_path` VARCHAR(255) NOT NULL DEFAULT '',
  `file_hash` CHAR(64) NOT NULL DEFAULT '',
  `download_token_hash` CHAR(64) NOT NULL DEFAULT '',
  `expires_at` INT UNSIGNED NULL,
  `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_retries` INT UNSIGNED NOT NULL DEFAULT 3,
  `next_retry_at` INT UNSIGNED NULL,
  `requested_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `started_at` INT UNSIGNED NULL,
  `finished_at` INT UNSIGNED NULL,
  `createtime` INT UNSIGNED NULL,
  `updatetime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_job_key` (`job_key`),
  UNIQUE KEY `uk_cpq_job_idempotency` (`idempotency_hash`),
  KEY `idx_cpq_job_status` (`status`,`next_retry_at`,`id`),
  KEY `idx_cpq_job_requester` (`requested_by`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ统一异步任务';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_import_batch` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id` BIGINT UNSIGNED NULL,
  `type` VARCHAR(32) NOT NULL,
  `preview_token_hash` CHAR(64) NOT NULL,
  `preview_json` LONGTEXT NOT NULL,
  `status` ENUM('previewed','confirmed','processing','succeeded','failed','expired') NOT NULL DEFAULT 'previewed',
  `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `requested_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `confirmed_at` INT UNSIGNED NULL,
  `expires_at` INT UNSIGNED NOT NULL,
  `createtime` INT UNSIGNED NULL,
  `updatetime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_import_preview_token` (`preview_token_hash`),
  KEY `idx_cpq_import_status` (`status`,`expires_at`),
  CONSTRAINT `fk_cpq_import_job` FOREIGN KEY (`job_id`) REFERENCES `__PREFIX__cpq_job` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ导入预览确认批次';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_integration_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `system_type` ENUM('crm','erp','mail','other') NOT NULL DEFAULT 'other',
  `base_url` VARCHAR(500) NOT NULL DEFAULT '',
  `auth_type` ENUM('none','hmac','oauth2') NOT NULL DEFAULT 'none',
  `credential_ciphertext` TEXT NULL,
  `credential_nonce` VARCHAR(64) NOT NULL DEFAULT '',
  `credential_tag` VARCHAR(64) NOT NULL DEFAULT '',
  `credential_key_version` VARCHAR(32) NOT NULL DEFAULT 'v1',
  `hmac_algorithm` VARCHAR(16) NOT NULL DEFAULT 'sha256',
  `timeout_ms` INT UNSIGNED NOT NULL DEFAULT 5000,
  `max_retries` INT UNSIGNED NOT NULL DEFAULT 3,
  `status` ENUM('enabled','disabled') NOT NULL DEFAULT 'disabled',
  `createtime` INT UNSIGNED NULL,
  `updatetime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_integration_config_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ集成配置(凭证密文)';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_integration_event` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_key` CHAR(64) NOT NULL COMMENT '来源事件或幂等键哈希',
  `config_id` BIGINT UNSIGNED NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `business_type` VARCHAR(64) NOT NULL DEFAULT '',
  `business_id` VARCHAR(64) NOT NULL DEFAULT '',
  `payload_json` LONGTEXT NOT NULL,
  `payload_hash` CHAR(64) NOT NULL,
  `status` ENUM('pending','processing','succeeded','failed','dead') NOT NULL DEFAULT 'pending',
  `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `max_retries` INT UNSIGNED NOT NULL DEFAULT 3,
  `next_retry_at` INT UNSIGNED NULL,
  `locked_at` INT UNSIGNED NULL,
  `last_error` VARCHAR(500) NOT NULL DEFAULT '',
  `delivered_at` INT UNSIGNED NULL,
  `createtime` INT UNSIGNED NULL,
  `updatetime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cpq_integration_event_key` (`event_key`),
  KEY `idx_cpq_integration_event_dispatch` (`status`,`next_retry_at`,`id`),
  CONSTRAINT `fk_cpq_integration_event_config` FOREIGN KEY (`config_id`) REFERENCES `__PREFIX__cpq_integration_config` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ集成事件Outbox';

CREATE TABLE IF NOT EXISTS `__PREFIX__cpq_download_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id` BIGINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `result` ENUM('allowed','denied','expired','hash_mismatch') NOT NULL,
  `ip` VARCHAR(50) NOT NULL DEFAULT '',
  `createtime` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cpq_download_job` (`job_id`,`id`),
  CONSTRAINT `fk_cpq_download_job` FOREIGN KEY (`job_id`) REFERENCES `__PREFIX__cpq_job` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='CPQ导出下载审计';
