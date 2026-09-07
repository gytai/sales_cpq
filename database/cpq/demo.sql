SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO `__PREFIX__cpq_product_series`
  (`code`,`name`,`name_en`,`business_unit`,`product_line`,`brand`,`default_unit`,`default_currency`,`description`,`version`,`status`,`weigh`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-SERIES','通用设备演示系列','Generic Equipment Demo','装备制造','DEMO-LINE','DEMO','set','CNY','用于验证通用配置、定价和报价流程的脱敏演示系列',1,'published',100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_product_model`
  (`series_id`,`code`,`name`,`name_en`,`category_code`,`base_item_code`,`unit`,`allow_custom`,`allow_overseas`,`version`,`status`,`weigh`,`createtime`,`updatetime`)
SELECT `id`,'CPQ-DEMO-EQUIPMENT-A','通用可配置设备A','Configurable Equipment A','DEMO-EQUIPMENT','BASE-A','set',1,1,1,'published',100,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_series` WHERE `code`='CPQ-DEMO-SERIES'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`series_id`=VALUES(`series_id`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_group`
  (`code`,`name`,`name_en`,`input_type`,`is_required`,`min_select`,`max_select`,`affects_price`,`affects_bom`,`help_text`,`sort`,`status`,`createtime`,`updatetime`)
VALUES
  ('power_level','功率等级','Power Level','single',1,1,1,1,1,'选择设备功率等级',10,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('cooling_level','散热等级','Cooling Level','single',0,0,1,1,1,'高功率场景需要增强散热',20,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('features','功能模块','Feature Modules','multiple',1,1,2,1,1,'最多选择两个功能模块',30,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('quantity','设备数量','Quantity','number',1,0,0,1,0,'允许范围为1到10',40,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('delivery_note','交付备注','Delivery Note','text',0,0,0,0,0,'特殊交付要求说明',45,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('calculated_capacity','计算容量','Calculated Capacity','readonly',0,0,0,0,0,'由规则自动计算',50,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`input_type`=VALUES(`input_type`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_value`
  (`group_id`,`code`,`name`,`name_en`,`material_code`,`default_qty`,`min_qty`,`max_qty`,`step`,`price_key`,`cost_key`,`weigh`,`status`,`createtime`,`updatetime`)
SELECT `id`,'standard','标准功率','Standard Power','POWER-STANDARD',1,1,1,1,'PRICE-POWER-STANDARD','COST-POWER-STANDARD',100,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_option_group` WHERE `code`='power_level'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`material_code`=VALUES(`material_code`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_value`
  (`group_id`,`code`,`name`,`name_en`,`material_code`,`default_qty`,`min_qty`,`max_qty`,`step`,`price_key`,`cost_key`,`weigh`,`status`,`createtime`,`updatetime`)
SELECT `id`,'high','高功率','High Power','POWER-HIGH',1,1,1,1,'PRICE-POWER-HIGH','COST-POWER-HIGH',90,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_option_group` WHERE `code`='power_level'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`material_code`=VALUES(`material_code`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_value`
  (`group_id`,`code`,`name`,`name_en`,`material_code`,`default_qty`,`min_qty`,`max_qty`,`step`,`price_key`,`cost_key`,`weigh`,`status`,`createtime`,`updatetime`)
SELECT `id`,'standard','标准散热','Standard Cooling','COOL-STANDARD',1,1,1,1,'PRICE-COOL-STANDARD','COST-COOL-STANDARD',100,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_option_group` WHERE `code`='cooling_level'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`material_code`=VALUES(`material_code`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_value`
  (`group_id`,`code`,`name`,`name_en`,`material_code`,`default_qty`,`min_qty`,`max_qty`,`step`,`price_key`,`cost_key`,`weigh`,`status`,`createtime`,`updatetime`)
SELECT `id`,'enhanced','增强散热','Enhanced Cooling','COOL-ENHANCED',1,1,1,1,'PRICE-COOL-ENHANCED','COST-COOL-ENHANCED',90,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_option_group` WHERE `code`='cooling_level'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`material_code`=VALUES(`material_code`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_value`
  (`group_id`,`code`,`name`,`name_en`,`material_code`,`default_qty`,`min_qty`,`max_qty`,`step`,`price_key`,`cost_key`,`weigh`,`status`,`createtime`,`updatetime`)
SELECT `id`,'monitoring','状态监测','Monitoring','FEATURE-MONITORING',1,1,1,1,'PRICE-FEATURE-MONITORING','COST-FEATURE-MONITORING',100,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_option_group` WHERE `code`='features'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`material_code`=VALUES(`material_code`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_value`
  (`group_id`,`code`,`name`,`name_en`,`material_code`,`default_qty`,`min_qty`,`max_qty`,`step`,`price_key`,`cost_key`,`weigh`,`status`,`createtime`,`updatetime`)
SELECT `id`,'remote','远程服务','Remote Service','FEATURE-REMOTE',1,1,1,1,'PRICE-FEATURE-REMOTE','COST-FEATURE-REMOTE',90,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_option_group` WHERE `code`='features'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`material_code`=VALUES(`material_code`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_option_value`
  (`group_id`,`code`,`name`,`name_en`,`material_code`,`default_qty`,`min_qty`,`max_qty`,`step`,`price_key`,`cost_key`,`weigh`,`status`,`createtime`,`updatetime`)
SELECT `id`,'offline','离线模块','Offline Module','FEATURE-OFFLINE',1,1,1,1,'PRICE-FEATURE-OFFLINE','COST-FEATURE-OFFLINE',80,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_option_group` WHERE `code`='features'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`material_code`=VALUES(`material_code`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_model_option_group`
  (`model_id`,`group_id`,`sort`,`is_visible`,`is_required`,`default_value`,`createtime`,`updatetime`)
SELECT `m`.`id`,`g`.`id`,`g`.`sort`,1,`g`.`is_required`,
  CASE `g`.`code` WHEN 'power_level' THEN '"standard"' ELSE NULL END,
  UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m`
CROSS JOIN `__PREFIX__cpq_option_group` `g`
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A' AND `g`.`code` IN ('power_level','cooling_level','features','quantity','delivery_note','calculated_capacity')
ON DUPLICATE KEY UPDATE `sort`=VALUES(`sort`),`is_required`=VALUES(`is_required`),`default_value`=VALUES(`default_value`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_config_rule`
  (`code`,`name`,`type`,`model_id`,`product_line`,`condition_json`,`action_json`,`priority`,`severity`,`message`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'REQUIRE-COOLING','高功率散热依赖','REQUIRES',`id`,'DEMO-LINE',
  '{"field":"configuration.power_level","operator":"=","value":"high"}',
  '[{"action":"require","target":"cooling_level","value":"enhanced"}]',
  100,'blocking','高功率型号必须配置增强散热',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `condition_json`=VALUES(`condition_json`),`action_json`=VALUES(`action_json`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_config_rule`
  (`code`,`name`,`type`,`model_id`,`product_line`,`condition_json`,`action_json`,`priority`,`severity`,`message`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'EXCLUDE-OFFLINE','远程与离线互斥','EXCLUDES',`id`,'DEMO-LINE',
  '{"field":"configuration.features","operator":"contains","value":"remote"}',
  '[{"action":"exclude","target":"features","value":"offline"}]',
  90,'blocking','远程服务与离线模块不能同时选择',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `condition_json`=VALUES(`condition_json`),`action_json`=VALUES(`action_json`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_config_rule`
  (`code`,`name`,`type`,`model_id`,`product_line`,`condition_json`,`action_json`,`priority`,`severity`,`message`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'QUANTITY-RANGE','数量范围','MIN_MAX',`id`,'DEMO-LINE','{}',
  '[{"action":"min_max","target":"quantity","min":1,"max":10}]',
  80,'blocking','设备数量必须在1到10之间',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `action_json`=VALUES(`action_json`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_config_rule`
  (`code`,`name`,`type`,`model_id`,`product_line`,`condition_json`,`action_json`,`priority`,`severity`,`message`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CAPACITY-FORMULA','容量计算','FORMULA',`id`,'DEMO-LINE','{"field":"configuration.quantity","operator":"not_empty"}',
  '[{"action":"formula","target":"calculated_capacity","operation":"multiply","operands":[{"field":"configuration.quantity"},{"value":2.5}],"scale":4}]',
  70,'blocking','',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `condition_json`=VALUES(`condition_json`),`action_json`=VALUES(`action_json`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_config_template`
  (`code`,`name`,`model_id`,`market_scope`,`customer_level`,`config_json`,`description`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-STANDARD','标准配置',`id`,'国内','标准客户',
  '{"power_level":"standard","features":["monitoring"],"quantity":"1"}',
  '通用演示型号的标准配置',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `config_json`=VALUES(`config_json`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_accessory_service`
  (`code`,`type`,`name`,`name_en`,`unit`,`product_line`,`tax_category`,`is_inventory_item`,`description`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-SERVICE-A','安装调试','安装调试服务','Installation Service','service','DEMO-LINE','SERVICE',0,'通用安装调试演示服务','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('CPQ-DEMO-SERVICE-B','培训','操作培训服务','Operation Training','service','DEMO-LINE','SERVICE',0,'设备操作与维护培训演示服务','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

-- 技术参数定义（全局字典）
INSERT INTO `__PREFIX__cpq_parameter_definition`
  (`code`,`name`,`value_type`,`unit`,`option_values`,`validation_rule`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-PARAM-POWER-RATING','额定功率','number','kW',NULL,'{"min":1,"max":1000}','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('CPQ-DEMO-PARAM-VOLTAGE','电气制式','select','V','["220","380","660"]',NULL,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('CPQ-DEMO-PARAM-PROTECTION','防护等级','text','',NULL,NULL,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

-- 型号技术参数：EQUIPMENT-A
INSERT INTO `__PREFIX__cpq_model_parameter`
  (`model_id`,`parameter_id`,`value`,`is_configurable`,`is_required`,`sort`,`createtime`,`updatetime`)
SELECT `m`.`id`,`p`.`id`,`v`.`value`,`v`.`is_configurable`,`v`.`is_required`,`v`.`sort`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m`
JOIN `__PREFIX__cpq_parameter_definition` `p` ON `p`.`code` IN ('CPQ-DEMO-PARAM-POWER-RATING','CPQ-DEMO-PARAM-VOLTAGE','CPQ-DEMO-PARAM-PROTECTION')
JOIN (
  SELECT 'CPQ-DEMO-PARAM-POWER-RATING' AS param_code,'45' AS `value`,0 AS is_configurable,1 AS is_required,10 AS `sort`
  UNION ALL SELECT 'CPQ-DEMO-PARAM-VOLTAGE','380',0,1,20
  UNION ALL SELECT 'CPQ-DEMO-PARAM-PROTECTION','IP54',1,0,30
) `v` ON `v`.`param_code` = `p`.`code`
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updatetime`=UNIX_TIMESTAMP();

-- 型号：EQUIPMENT-B（演示参数差异的第二设备）
INSERT INTO `__PREFIX__cpq_product_model`
  (`series_id`,`code`,`name`,`name_en`,`category_code`,`base_item_code`,`unit`,`allow_custom`,`allow_overseas`,`version`,`status`,`weigh`,`createtime`,`updatetime`)
SELECT `id`,'CPQ-DEMO-EQUIPMENT-B','通用可配置设备B','Configurable Equipment B','DEMO-EQUIPMENT','BASE-B','set',0,1,1,'published',90,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_series` WHERE `code`='CPQ-DEMO-SERIES'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`series_id`=VALUES(`series_id`),`updatetime`=UNIX_TIMESTAMP();

-- 型号配置结构：EQUIPMENT-B（数量 + 交付备注）
INSERT INTO `__PREFIX__cpq_model_option_group`
  (`model_id`,`group_id`,`sort`,`is_visible`,`is_required`,`default_value`,`createtime`,`updatetime`)
SELECT `m`.`id`,`g`.`id`,`g`.`sort`,1,`g`.`is_required`,NULL,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m`
CROSS JOIN `__PREFIX__cpq_option_group` `g`
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-B' AND `g`.`code` IN ('quantity','delivery_note')
ON DUPLICATE KEY UPDATE `sort`=VALUES(`sort`),`is_required`=VALUES(`is_required`),`updatetime`=UNIX_TIMESTAMP();

-- 型号技术参数：EQUIPMENT-B
INSERT INTO `__PREFIX__cpq_model_parameter`
  (`model_id`,`parameter_id`,`value`,`is_configurable`,`is_required`,`sort`,`createtime`,`updatetime`)
SELECT `m`.`id`,`p`.`id`,`v`.`value`,`v`.`is_configurable`,`v`.`is_required`,`v`.`sort`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m`
JOIN `__PREFIX__cpq_parameter_definition` `p` ON `p`.`code` IN ('CPQ-DEMO-PARAM-POWER-RATING','CPQ-DEMO-PARAM-VOLTAGE','CPQ-DEMO-PARAM-PROTECTION')
JOIN (
  SELECT 'CPQ-DEMO-PARAM-POWER-RATING' AS param_code,'120' AS `value`,0 AS is_configurable,1 AS is_required,10 AS `sort`
  UNION ALL SELECT 'CPQ-DEMO-PARAM-VOLTAGE','660',0,1,20
  UNION ALL SELECT 'CPQ-DEMO-PARAM-PROTECTION','IP65',1,0,30
) `v` ON `v`.`param_code` = `p`.`code`
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-B'
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updatetime`=UNIX_TIMESTAMP();

-- BOM 映射：EQUIPMENT-B 基础物料（表无业务唯一键，先删后插保证幂等）
DELETE `bm` FROM `__PREFIX__cpq_bom_mapping` `bm`
JOIN `__PREFIX__cpq_product_model` `m` ON `m`.`id` = `bm`.`model_id`
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-B' AND `bm`.`material_code`='FRAME-B' AND `bm`.`version`=1;

INSERT INTO `__PREFIX__cpq_bom_mapping`
  (`model_id`,`option_value_id`,`material_code`,`qty_formula`,`unit`,`substitute_material_code`,`loss_rate`,`version`,`status`,`createtime`,`updatetime`)
SELECT `id`,NULL,'FRAME-B','1','set','',0,1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-B';

-- BOM 映射：EQUIPMENT-A 基础物料与选项映射（数量公式引用配置）
DELETE `bm` FROM `__PREFIX__cpq_bom_mapping` `bm`
JOIN `__PREFIX__cpq_product_model` `m` ON `m`.`id` = `bm`.`model_id`
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A' AND `bm`.`material_code` IN ('FRAME-A','POWER-MODULE-HIGH','COOL-ENHANCED-KIT','FEATURE-MONITORING-BOARD') AND `bm`.`version`=1;

INSERT INTO `__PREFIX__cpq_bom_mapping`
  (`model_id`,`option_value_id`,`material_code`,`qty_formula`,`unit`,`substitute_material_code`,`loss_rate`,`version`,`status`,`createtime`,`updatetime`)
SELECT `m`.`id`,NULL,'FRAME-A','1','set','',0,1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m` WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A';

INSERT INTO `__PREFIX__cpq_bom_mapping`
  (`model_id`,`option_value_id`,`material_code`,`qty_formula`,`unit`,`substitute_material_code`,`loss_rate`,`version`,`status`,`createtime`,`updatetime`)
SELECT `m`.`id`,`ov`.`id`,'POWER-MODULE-HIGH','({configuration.quantity} * 1) + 0','item','POWER-MODULE-STD',0.05,1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m`
JOIN `__PREFIX__cpq_option_group` `og` ON `og`.`code`='power_level'
JOIN `__PREFIX__cpq_option_value` `ov` ON `ov`.`group_id`=`og`.`id` AND `ov`.`code`='high'
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A';

INSERT INTO `__PREFIX__cpq_bom_mapping`
  (`model_id`,`option_value_id`,`material_code`,`qty_formula`,`unit`,`substitute_material_code`,`loss_rate`,`version`,`status`,`createtime`,`updatetime`)
SELECT `m`.`id`,`ov`.`id`,'COOL-ENHANCED-KIT','1','item','',0,1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m`
JOIN `__PREFIX__cpq_option_group` `og` ON `og`.`code`='cooling_level'
JOIN `__PREFIX__cpq_option_value` `ov` ON `ov`.`group_id`=`og`.`id` AND `ov`.`code`='enhanced'
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A';

INSERT INTO `__PREFIX__cpq_bom_mapping`
  (`model_id`,`option_value_id`,`material_code`,`qty_formula`,`unit`,`substitute_material_code`,`loss_rate`,`version`,`status`,`createtime`,`updatetime`)
SELECT `m`.`id`,`ov`.`id`,'FEATURE-MONITORING-BOARD','1','item','',0,1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m`
JOIN `__PREFIX__cpq_option_group` `og` ON `og`.`code`='features'
JOIN `__PREFIX__cpq_option_value` `ov` ON `ov`.`group_id`=`og`.`id` AND `ov`.`code`='monitoring'
WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A';

-- 推荐配置模板：高温环境（高功率 + 增强散热）
INSERT INTO `__PREFIX__cpq_config_template`
  (`code`,`name`,`model_id`,`market_scope`,`customer_level`,`config_json`,`description`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-HIGH-TEMP','高温环境配置',`id`,'国内','标准客户',
  '{"power_level":"high","cooling_level":"enhanced","features":["monitoring"],"quantity":"1"}',
  '高温环境推荐配置：高功率并强制增强散热',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `config_json`=VALUES(`config_json`),`updatetime`=UNIX_TIMESTAMP();

-- ============================================================
-- M2 客户渠道与价格主数据演示（GYTAI-69，全部 CPQ-DEMO 前缀）
-- dimension_key 由服务端 PricePolicyService 在写入时计算，
-- 演示策略行置空串，仅用于页面与覆盖率演示。
-- ============================================================

-- 客户等级 / 代理等级
INSERT INTO `__PREFIX__cpq_customer_level`
  (`code`,`name`,`sort`,`default_discount`,`market_scope`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-LV-STRATEGIC','战略客户',10,0.900000,'all','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('CPQ-DEMO-LV-STANDARD','标准客户',20,1.000000,'all','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_agent_level`
  (`code`,`name`,`sort`,`default_discount`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-ALV-CORE','核心代理',10,0.850000,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

-- 销售区域树：中国 → 华东
INSERT INTO `__PREFIX__cpq_region`
  (`code`,`name`,`parent_id`,`path`,`level`,`default_currency`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-REGION-CN','中国',0,'/',1,'CNY','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();
UPDATE `__PREFIX__cpq_region` SET `path`=CONCAT('/',`id`,'/') WHERE `code`='CPQ-DEMO-REGION-CN';

INSERT INTO `__PREFIX__cpq_region`
  (`code`,`name`,`parent_id`,`path`,`level`,`default_currency`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-REGION-EAST','华东',`id`,'',2,'CNY','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_region` WHERE `code`='CPQ-DEMO-REGION-CN'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`parent_id`=VALUES(`parent_id`),`updatetime`=UNIX_TIMESTAMP();
UPDATE `__PREFIX__cpq_region` `r`
JOIN `__PREFIX__cpq_region` `p` ON `p`.`code`='CPQ-DEMO-REGION-CN'
SET `r`.`path`=CONCAT('/',`p`.`id`,'/',`r`.`id`,'/'),`r`.`level`=2
WHERE `r`.`code`='CPQ-DEMO-REGION-EAST';

-- 销售组织树：总部 → 华东销售部
INSERT INTO `__PREFIX__cpq_sales_org`
  (`code`,`name`,`parent_id`,`path`,`level`,`manager_id`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-ORG-HQ','销售总部',0,'/',1,0,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();
UPDATE `__PREFIX__cpq_sales_org` SET `path`=CONCAT('/',`id`,'/') WHERE `code`='CPQ-DEMO-ORG-HQ';

INSERT INTO `__PREFIX__cpq_sales_org`
  (`code`,`name`,`parent_id`,`path`,`level`,`manager_id`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-ORG-EAST','华东销售部',`id`,'',2,0,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_sales_org` WHERE `code`='CPQ-DEMO-ORG-HQ'
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`parent_id`=VALUES(`parent_id`),`updatetime`=UNIX_TIMESTAMP();
UPDATE `__PREFIX__cpq_sales_org` `o`
JOIN `__PREFIX__cpq_sales_org` `p` ON `p`.`code`='CPQ-DEMO-ORG-HQ'
SET `o`.`path`=CONCAT('/',`p`.`id`,'/',`o`.`id`,'/'),`o`.`level`=2
WHERE `o`.`code`='CPQ-DEMO-ORG-EAST';

-- 客户与代理商
INSERT INTO `__PREFIX__cpq_customer`
  (`code`,`name`,`name_en`,`type`,`credit_code`,`country_code`,`region_id`,`customer_level_id`,`default_currency`,`payment_terms`,`trade_terms`,`sales_org_id`,`status`,`remark`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-CUSTOMER-A','演示客户A','Demo Customer A','direct','DEMO91340000XXXXXX01','CN',
  (SELECT `id` FROM `__PREFIX__cpq_region` WHERE `code`='CPQ-DEMO-REGION-EAST'),
  (SELECT `id` FROM `__PREFIX__cpq_customer_level` WHERE `code`='CPQ-DEMO-LV-STANDARD'),
  'CNY','月结30天','EXW',
  (SELECT `id` FROM `__PREFIX__cpq_sales_org` WHERE `code`='CPQ-DEMO-ORG-EAST'),
  'normal','脱敏演示客户',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_agent`
  (`code`,`customer_id`,`agent_level_id`,`authorized_regions`,`authorized_lines`,`auth_start_date`,`auth_end_date`,`credit_limit`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-AGENT-A',`c`.`id`,
  (SELECT `id` FROM `__PREFIX__cpq_agent_level` WHERE `code`='CPQ-DEMO-ALV-CORE'),
  (SELECT CONCAT('[',`r`.`id`,']') FROM `__PREFIX__cpq_region` `r` WHERE `r`.`code`='CPQ-DEMO-REGION-EAST'),
  '["DEMO-LINE"]','2026-01-01','2026-12-31',1000000.0000,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_customer` `c` WHERE `c`.`code`='CPQ-DEMO-CUSTOMER-A'
ON DUPLICATE KEY UPDATE `agent_level_id`=VALUES(`agent_level_id`),`updatetime`=UNIX_TIMESTAMP();

-- 价格表（已发布）与条目
INSERT INTO `__PREFIX__cpq_price_book`
  (`code`,`name`,`company`,`business_unit`,`market_scope`,`currency`,`tax_mode`,`priority`,`effective_date`,`expiry_date`,`version`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-BOOK-2026','2026年国内标准价格表','DEMO公司','装备制造','domestic','CNY','tax_exclusive',10,'2026-01-01','2026-12-31',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_price_entry`
  (`price_book_id`,`target_type`,`target_id`,`amount`,`unit`,`min_qty`,`max_qty`,`createtime`,`updatetime`)
SELECT `b`.`id`,'model',`m`.`id`,120000.0000,'set',0,NULL,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_price_book` `b`
JOIN `__PREFIX__cpq_product_model` `m` ON `m`.`code`='CPQ-DEMO-EQUIPMENT-A'
WHERE `b`.`code`='CPQ-DEMO-BOOK-2026'
ON DUPLICATE KEY UPDATE `amount`=VALUES(`amount`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_price_entry`
  (`price_book_id`,`target_type`,`target_id`,`amount`,`unit`,`min_qty`,`max_qty`,`createtime`,`updatetime`)
SELECT `b`.`id`,'option',`ov`.`id`,8000.0000,'item',0,NULL,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_price_book` `b`
JOIN `__PREFIX__cpq_option_group` `og` ON `og`.`code`='power_level'
JOIN `__PREFIX__cpq_option_value` `ov` ON `ov`.`group_id`=`og`.`id` AND `ov`.`code`='high'
WHERE `b`.`code`='CPQ-DEMO-BOOK-2026'
ON DUPLICATE KEY UPDATE `amount`=VALUES(`amount`),`updatetime`=UNIX_TIMESTAMP();

-- 三层价格策略（指导价 >= 产线控制价 >= 公司控制价 >= 0）
INSERT INTO `__PREFIX__cpq_price_policy`
  (`code`,`name`,`dimension_key`,`company`,`business_unit`,`market_scope`,`region_code`,`customer_level`,`agent_level`,`customer_id`,`product_line`,`target_type`,`target_id`,`currency`,`unit`,`guide_price`,`line_floor`,`company_floor`,`cost`,`priority`,`effective_date`,`expiry_date`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-POLICY-A','演示设备A国内标准策略','','DEMO公司','装备制造','domestic','','','',NULL,'DEMO-LINE','model',`m`.`id`,
  'CNY','set',120000.0000,108000.0000,96000.0000,60000.0000,10,'2026-01-01','2026-12-31',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m` WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `guide_price`=VALUES(`guide_price`),`updatetime`=UNIX_TIMESTAMP();

-- 价格规则 / 汇率 / 税率 / 费用
INSERT INTO `__PREFIX__cpq_price_rule`
  (`code`,`name`,`condition_json`,`adjustment_type`,`adjustment_target`,`adjustment_value`,`can_stack`,`exclusive_group`,`priority`,`effective_date`,`expiry_date`,`version`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-RULE-STRATEGIC','战略客户折扣','{"all":[{"field":"customer_level","operator":"=","value":"CPQ-DEMO-LV-STRATEGIC"}]}','discount','subtotal',0.90000000,0,'',10,'2026-01-01','2026-12-31',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `adjustment_value`=VALUES(`adjustment_value`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_exchange_rate`
  (`source_currency`,`target_currency`,`rate`,`source`,`effective_date`,`status`,`createtime`,`updatetime`)
VALUES
  ('USD','CNY',7.10000000,'manual','2026-01-01','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `rate`=VALUES(`rate`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_tax_rule`
  (`code`,`country_code`,`region_code`,`product_type`,`rate`,`effective_date`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-VAT-CN','CN','','',0.130000,'2026-01-01','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `rate`=VALUES(`rate`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_fee_rule`
  (`code`,`name`,`fee_type`,`condition_json`,`calculation_type`,`value`,`currency`,`include_in_margin`,`include_in_floor`,`priority`,`effective_date`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-FREIGHT-CN','国内标准运费','freight','{}','fixed',500.00000000,'CNY',1,1,10,'2026-01-01','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),`updatetime`=UNIX_TIMESTAMP();

-- 价格表发布版本（已生效，不可变）
INSERT INTO `__PREFIX__cpq_release_version`
  (`object_type`,`object_id`,`version`,`content_hash`,`change_summary`,`affected_product_count`,`affected_customer_count`,`submitted_by`,`approved_by`,`planned_effective_at`,`effective_at`,`status`,`createtime`,`updatetime`)
SELECT 'cpq_price_book',`id`,1,SHA2(CONCAT('cpq_price_book:',`id`,':v1'),256),'2026年国内标准价格表首次发布',1,0,0,0,NULL,UNIX_TIMESTAMP(),'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_price_book` WHERE `code`='CPQ-DEMO-BOOK-2026'
ON DUPLICATE KEY UPDATE `change_summary`=VALUES(`change_summary`),`updatetime`=UNIX_TIMESTAMP();

-- ---------------------------------------------------------------------
-- M3 演示数据（GYTAI-75）：审批人账号、固定路径审批规则、默认报价模板
-- ---------------------------------------------------------------------

-- 演示审批人（产线/公司两级，与报价负责人 admin 职责分离；密码 Appr@123456）
INSERT INTO `__PREFIX__admin`
  (`username`,`nickname`,`password`,`salt`,`avatar`,`email`,`loginfailure`,`createtime`,`updatetime`,`token`,`status`)
SELECT 'cpq_approver','CPQ演示审批员-产线','a54e30920e210f565d231decb20d48e8','cpqdemo','','cpq_approver@demo.local',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'','normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__admin` WHERE `username`='cpq_approver');

INSERT INTO `__PREFIX__admin`
  (`username`,`nickname`,`password`,`salt`,`avatar`,`email`,`loginfailure`,`createtime`,`updatetime`,`token`,`status`)
SELECT 'cpq_approver2','CPQ演示审批员-公司','a54e30920e210f565d231decb20d48e8','cpqdemo','','cpq_approver2@demo.local',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'','normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__admin` WHERE `username`='cpq_approver2');

-- 角色用户组（组名与 CPQ 角色编码精确匹配；rules 覆盖 CPQ 菜单便于演示登录）
SET SESSION group_concat_max_len = 1000000;
INSERT INTO `__PREFIX__auth_group` (`pid`,`name`,`rules`,`createtime`,`updatetime`,`status`)
SELECT 0,'line_pricer',IFNULL((SELECT GROUP_CONCAT(x.id) FROM (SELECT `id` FROM `__PREFIX__auth_rule` WHERE `name` LIKE 'cpq/%') x),''),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group` WHERE `name`='line_pricer');

INSERT INTO `__PREFIX__auth_group` (`pid`,`name`,`rules`,`createtime`,`updatetime`,`status`)
SELECT 0,'company_pricer',IFNULL((SELECT GROUP_CONCAT(x.id) FROM (SELECT `id` FROM `__PREFIX__auth_rule` WHERE `name` LIKE 'cpq/%') x),''),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group` WHERE `name`='company_pricer');

INSERT INTO `__PREFIX__auth_group_access` (`uid`,`group_id`)
SELECT a.`id`, g.`id` FROM `__PREFIX__admin` a JOIN `__PREFIX__auth_group` g ON g.`name`='line_pricer'
WHERE a.`username`='cpq_approver'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group_access` ga WHERE ga.`uid`=a.`id` AND ga.`group_id`=g.`id`);

INSERT INTO `__PREFIX__auth_group_access` (`uid`,`group_id`)
SELECT a.`id`, g.`id` FROM `__PREFIX__admin` a JOIN `__PREFIX__auth_group` g ON g.`name`='company_pricer'
WHERE a.`username`='cpq_approver2'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group_access` ga WHERE ga.`uid`=a.`id` AND ga.`group_id`=g.`id`);

-- 演示审批人的产品线数据范围（fail-closed，仅 DEMO-LINE）
INSERT INTO `__PREFIX__cpq_admin_product_line` (`admin_id`,`product_line`,`createtime`,`updatetime`)
SELECT a.`id`,'DEMO-LINE',UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `__PREFIX__admin` a
WHERE a.`username` IN ('cpq_approver','cpq_approver2')
ON DUPLICATE KEY UPDATE `updatetime`=UNIX_TIMESTAMP();

-- 固定路径审批规则：DEMO-LINE 产线/公司节点（显式候选人 + SLA）
INSERT INTO `__PREFIX__cpq_approval_rule`
  (`code`,`name`,`node`,`approver_role`,`product_line`,`candidate_admin_ids`,`sla_hours`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-APR-LINE','演示产线价格审批','line_approval','','DEMO-LINE',CONCAT('[',a.`id`,']'),24,1,'enabled',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__admin` a WHERE a.`username`='cpq_approver'
ON DUPLICATE KEY UPDATE `candidate_admin_ids`=VALUES(`candidate_admin_ids`),`status`='enabled',`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_approval_rule`
  (`code`,`name`,`node`,`approver_role`,`product_line`,`candidate_admin_ids`,`sla_hours`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-APR-COMPANY','演示公司价格审批','company_approval','','DEMO-LINE',CONCAT('[',a.`id`,']'),24,1,'enabled',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__admin` a WHERE a.`username`='cpq_approver2'
ON DUPLICATE KEY UPDATE `candidate_admin_ids`=VALUES(`candidate_admin_ids`),`status`='enabled',`updatetime`=UNIX_TIMESTAMP();

-- 默认报价模板（中英文各一，已发布 + 全部市场默认）
INSERT INTO `__PREFIX__cpq_quote_template`
  (`code`,`name`,`name_en`,`language`,`market_scope`,`paper_size`,`is_default`,`content_json`,`allowed_variables`,`version`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-QT-ZH','演示标准报价模板（中文）','Demo Standard Quotation (ZH)','zh','all','A4',1,
   '{"show_cover":1,"show_company_info":1,"show_product_table":1,"show_technical_params":0,"show_terms":1,"show_signature":1,"show_watermark":0,"cover_title":"报价单 {{quote.code}}","cover_subtitle":"报价日期：{{quote.date}}　币种：{{quote.currency}}","header_text":"{{company.name}}","footer_text":"本报价单由 CPQ 系统生成，下载时校验文件哈希。","watermark_text":"报价单","signature_note":"","remark":""}',
   '["quote.code","quote.name","quote.customer_name","quote.agent_name","quote.sales_org_name","quote.currency","quote.date","quote.valid_until","quote.owner_name","quote.untaxed_amount","quote.tax_amount","quote.total_amount","quote.line_count","company.name","company.name_en","company.address","company.contact"]',
   1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('CPQ-DEMO-QT-EN','Demo Standard Quotation (EN)','Demo Standard Quotation (EN)','en','all','A4',1,
   '{"show_cover":1,"show_company_info":1,"show_product_table":1,"show_technical_params":0,"show_terms":1,"show_signature":1,"show_watermark":0,"cover_title":"Quotation {{quote.code}}","cover_subtitle":"Date: {{quote.date}}  Currency: {{quote.currency}}","header_text":"{{company.name_en}}","footer_text":"This quotation is generated by CPQ system. File hash verified on download.","watermark_text":"QUOTATION","signature_note":"","remark":""}',
   '["quote.code","quote.name","quote.customer_name","quote.agent_name","quote.sales_org_name","quote.currency","quote.date","quote.valid_until","quote.owner_name","quote.untaxed_amount","quote.tax_amount","quote.total_amount","quote.line_count","company.name","company.name_en","company.address","company.contact"]',
   1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `status`='published',`is_default`=1,`updatetime`=UNIX_TIMESTAMP();

-- ---------------------------------------------------------------------
-- M4 演示数据（GYTAI-78）：多角色账号、数据范围授权与演示报价
-- 用于浏览器验收：销售 owner-only / 销售经理组织子树 / 财务与审计全量只读
-- 密码统一为 Appr@123456（与 M3 演示审批人同一散列）
-- ---------------------------------------------------------------------

-- 演示账号：销售 / 销售经理 / 财务 / 审计
INSERT INTO `__PREFIX__admin`
  (`username`,`nickname`,`password`,`salt`,`avatar`,`email`,`loginfailure`,`createtime`,`updatetime`,`token`,`status`)
SELECT 'cpq_sales','CPQ演示销售','a54e30920e210f565d231decb20d48e8','cpqdemo','','cpq_sales@demo.local',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'','normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__admin` WHERE `username`='cpq_sales');

INSERT INTO `__PREFIX__admin`
  (`username`,`nickname`,`password`,`salt`,`avatar`,`email`,`loginfailure`,`createtime`,`updatetime`,`token`,`status`)
SELECT 'cpq_sales_mgr','CPQ演示销售经理','a54e30920e210f565d231decb20d48e8','cpqdemo','','cpq_sales_mgr@demo.local',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'','normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__admin` WHERE `username`='cpq_sales_mgr');

INSERT INTO `__PREFIX__admin`
  (`username`,`nickname`,`password`,`salt`,`avatar`,`email`,`loginfailure`,`createtime`,`updatetime`,`token`,`status`)
SELECT 'cpq_finance','CPQ演示财务','a54e30920e210f565d231decb20d48e8','cpqdemo','','cpq_finance@demo.local',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'','normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__admin` WHERE `username`='cpq_finance');

INSERT INTO `__PREFIX__admin`
  (`username`,`nickname`,`password`,`salt`,`avatar`,`email`,`loginfailure`,`createtime`,`updatetime`,`token`,`status`)
SELECT 'cpq_auditor','CPQ演示审计','a54e30920e210f565d231decb20d48e8','cpqdemo','','cpq_auditor@demo.local',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'','normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__admin` WHERE `username`='cpq_auditor');

-- 角色用户组（组名与 CPQ 角色编码精确匹配，rolesOfAdmin 依赖该约定）
INSERT INTO `__PREFIX__auth_group` (`pid`,`name`,`rules`,`createtime`,`updatetime`,`status`)
SELECT 0,'sales',IFNULL((SELECT GROUP_CONCAT(x.id) FROM (SELECT `id` FROM `__PREFIX__auth_rule` WHERE `name` LIKE 'cpq/%') x),''),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group` WHERE `name`='sales');

INSERT INTO `__PREFIX__auth_group` (`pid`,`name`,`rules`,`createtime`,`updatetime`,`status`)
SELECT 0,'sales_manager',IFNULL((SELECT GROUP_CONCAT(x.id) FROM (SELECT `id` FROM `__PREFIX__auth_rule` WHERE `name` LIKE 'cpq/%') x),''),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group` WHERE `name`='sales_manager');

INSERT INTO `__PREFIX__auth_group` (`pid`,`name`,`rules`,`createtime`,`updatetime`,`status`)
SELECT 0,'finance_reviewer',IFNULL((SELECT GROUP_CONCAT(x.id) FROM (SELECT `id` FROM `__PREFIX__auth_rule` WHERE `name` LIKE 'cpq/%') x),''),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group` WHERE `name`='finance_reviewer');

INSERT INTO `__PREFIX__auth_group` (`pid`,`name`,`rules`,`createtime`,`updatetime`,`status`)
SELECT 0,'auditor',IFNULL((SELECT GROUP_CONCAT(x.id) FROM (SELECT `id` FROM `__PREFIX__auth_rule` WHERE `name` LIKE 'cpq/%') x),''),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),'normal'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group` WHERE `name`='auditor');

-- 幂等重跑时刷新全部演示角色组的 rules 为最新 CPQ 菜单全集（新装菜单后仍可访问）
UPDATE `__PREFIX__auth_group` `g`
SET `g`.`rules`=IFNULL((SELECT GROUP_CONCAT(x.id) FROM (SELECT `id` FROM `__PREFIX__auth_rule` WHERE `name` LIKE 'cpq/%') x),''),`g`.`updatetime`=UNIX_TIMESTAMP()
WHERE `g`.`name` IN ('sales','sales_manager','finance_reviewer','auditor','line_pricer','company_pricer');

INSERT INTO `__PREFIX__auth_group_access` (`uid`,`group_id`)
SELECT a.`id`, g.`id` FROM `__PREFIX__admin` a JOIN `__PREFIX__auth_group` g ON g.`name`='sales'
WHERE a.`username`='cpq_sales'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group_access` ga WHERE ga.`uid`=a.`id` AND ga.`group_id`=g.`id`);

INSERT INTO `__PREFIX__auth_group_access` (`uid`,`group_id`)
SELECT a.`id`, g.`id` FROM `__PREFIX__admin` a JOIN `__PREFIX__auth_group` g ON g.`name`='sales_manager'
WHERE a.`username`='cpq_sales_mgr'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group_access` ga WHERE ga.`uid`=a.`id` AND ga.`group_id`=g.`id`);

INSERT INTO `__PREFIX__auth_group_access` (`uid`,`group_id`)
SELECT a.`id`, g.`id` FROM `__PREFIX__admin` a JOIN `__PREFIX__auth_group` g ON g.`name`='finance_reviewer'
WHERE a.`username`='cpq_finance'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group_access` ga WHERE ga.`uid`=a.`id` AND ga.`group_id`=g.`id`);

INSERT INTO `__PREFIX__auth_group_access` (`uid`,`group_id`)
SELECT a.`id`, g.`id` FROM `__PREFIX__admin` a JOIN `__PREFIX__auth_group` g ON g.`name`='auditor'
WHERE a.`username`='cpq_auditor'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__auth_group_access` ga WHERE ga.`uid`=a.`id` AND ga.`group_id`=g.`id`);

-- 产品线数据范围：销售/经理仅 DEMO-LINE（fail-closed），财务/审计全量
INSERT INTO `__PREFIX__cpq_admin_product_line` (`admin_id`,`product_line`,`createtime`,`updatetime`)
SELECT a.`id`,'DEMO-LINE',UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `__PREFIX__admin` a
WHERE a.`username` IN ('cpq_sales','cpq_sales_mgr')
ON DUPLICATE KEY UPDATE `updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_admin_product_line` (`admin_id`,`product_line`,`createtime`,`updatetime`)
SELECT a.`id`,'*',UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM `__PREFIX__admin` a
WHERE a.`username` IN ('cpq_finance','cpq_auditor')
ON DUPLICATE KEY UPDATE `updatetime`=UNIX_TIMESTAMP();

-- 销售组织成员：cpq_sales=华东销售（owner-only），cpq_sales_mgr=华东销售经理（组织子树）
INSERT INTO `__PREFIX__cpq_sales_org_member` (`org_id`,`admin_id`,`role`,`effective_date`,`expiry_date`,`status`,`createtime`,`updatetime`)
SELECT o.`id`,a.`id`,'sales',CURDATE(),NULL,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_sales_org` o JOIN `__PREFIX__admin` a ON a.`username`='cpq_sales'
WHERE o.`code`='CPQ-DEMO-ORG-EAST'
ON DUPLICATE KEY UPDATE `role`=VALUES(`role`),`status`=VALUES(`status`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_sales_org_member` (`org_id`,`admin_id`,`role`,`effective_date`,`expiry_date`,`status`,`createtime`,`updatetime`)
SELECT o.`id`,a.`id`,'sales_manager',CURDATE(),NULL,'normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_sales_org` o JOIN `__PREFIX__admin` a ON a.`username`='cpq_sales_mgr'
WHERE o.`code`='CPQ-DEMO-ORG-EAST'
ON DUPLICATE KEY UPDATE `role`=VALUES(`role`),`status`=VALUES(`status`),`updatetime`=UNIX_TIMESTAMP();

-- 华东销售部负责人与负责区域（组织/区域授权双路径派生）
UPDATE `__PREFIX__cpq_sales_org` `o`
JOIN `__PREFIX__admin` a ON a.`username`='cpq_sales_mgr'
SET `o`.`manager_id`=a.`id`, `o`.`updatetime`=UNIX_TIMESTAMP()
WHERE `o`.`code`='CPQ-DEMO-ORG-EAST';

UPDATE `__PREFIX__cpq_region` `r`
JOIN `__PREFIX__cpq_sales_org` o ON o.`code`='CPQ-DEMO-ORG-EAST'
SET `r`.`sales_org_id`=o.`id`, `r`.`updatetime`=UNIX_TIMESTAMP()
WHERE `r`.`code`='CPQ-DEMO-REGION-EAST';

-- 演示报价：DEMO-M4-Q-OWN（销售本人，已批准，含冻结价格快照）
-- 金额口径：指导单价 120000 × 2 台 × 手工折扣 0.9 = 未税 216000，13% 增值税后 244080
INSERT INTO `__PREFIX__cpq_quote`
  (`code`,`name`,`customer_id`,`sales_org_id`,`owner_id`,`product_line`,`currency`,`company`,`status`,`current_revision_no`,`submitted_at`,`approved_at`,`createtime`,`updatetime`)
SELECT 'DEMO-M4-Q-OWN','M4演示报价-销售本人',c.`id`,o.`id`,a.`id`,'DEMO-LINE','CNY','DEMO公司','approved',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_customer` c
JOIN `__PREFIX__cpq_sales_org` o ON o.`code`='CPQ-DEMO-ORG-EAST'
JOIN `__PREFIX__admin` a ON a.`username`='cpq_sales'
WHERE c.`code`='CPQ-DEMO-CUSTOMER-A'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote` q WHERE q.`code`='DEMO-M4-Q-OWN');

-- 演示报价：DEMO-M4-Q-ORG（同组织他人 owner=admin，审批中）
INSERT INTO `__PREFIX__cpq_quote`
  (`code`,`name`,`customer_id`,`sales_org_id`,`owner_id`,`product_line`,`currency`,`company`,`status`,`current_revision_no`,`submitted_at`,`createtime`,`updatetime`)
SELECT 'DEMO-M4-Q-ORG','M4演示报价-同组织他人',c.`id`,o.`id`,a.`id`,'DEMO-LINE','CNY','DEMO公司','submitted',1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_customer` c
JOIN `__PREFIX__cpq_sales_org` o ON o.`code`='CPQ-DEMO-ORG-EAST'
JOIN `__PREFIX__admin` a ON a.`username`='admin'
WHERE c.`code`='CPQ-DEMO-CUSTOMER-A'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote` q WHERE q.`code`='DEMO-M4-Q-ORG');

-- 报价行
INSERT INTO `__PREFIX__cpq_quote_line`
  (`quote_id`,`line_no`,`model_id`,`quantity`,`unit`,`configuration_json`,`configuration_hash`,`manual_discount`,`createtime`,`updatetime`)
SELECT q.`id`,1,m.`id`,2.0000,'set','{"power_level":"standard","quantity":"2"}',SHA2('DEMO-M4-Q-OWN:L1',256),0.900000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_quote` q JOIN `__PREFIX__cpq_product_model` m ON m.`code`='CPQ-DEMO-EQUIPMENT-A'
WHERE q.`code`='DEMO-M4-Q-OWN'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote_line` l WHERE l.`quote_id`=q.`id` AND l.`line_no`=1);

INSERT INTO `__PREFIX__cpq_quote_line`
  (`quote_id`,`line_no`,`model_id`,`quantity`,`unit`,`configuration_json`,`configuration_hash`,`manual_discount`,`createtime`,`updatetime`)
SELECT q.`id`,1,m.`id`,1.0000,'set','{"power_level":"standard","quantity":"1"}',SHA2('DEMO-M4-Q-ORG:L1',256),1.000000,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_quote` q JOIN `__PREFIX__cpq_product_model` m ON m.`code`='CPQ-DEMO-EQUIPMENT-A'
WHERE q.`code`='DEMO-M4-Q-ORG'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote_line` l WHERE l.`quote_id`=q.`id` AND l.`line_no`=1);

-- 报价版本（Q-OWN 已批准 / Q-ORG 审批中）
INSERT INTO `__PREFIX__cpq_quote_revision`
  (`quote_id`,`revision_no`,`status`,`price_hash`,`approval_level`,`submittable`,`snapshot_hash`,`created_by`,`frozen_at`,`submitted_at`,`createtime`,`updatetime`)
SELECT q.`id`,1,'approved',SHA2('DEMO-M4-Q-OWN:R1',256),'none',1,SHA2('DEMO-M4-Q-OWN:R1:SNAP',256),q.`owner_id`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_quote` q
WHERE q.`code`='DEMO-M4-Q-OWN'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote_revision` r WHERE r.`quote_id`=q.`id` AND r.`revision_no`=1);

INSERT INTO `__PREFIX__cpq_quote_revision`
  (`quote_id`,`revision_no`,`status`,`price_hash`,`approval_level`,`submittable`,`snapshot_hash`,`created_by`,`frozen_at`,`submitted_at`,`createtime`,`updatetime`)
SELECT q.`id`,1,'submitted',SHA2('DEMO-M4-Q-ORG:R1',256),'none',1,SHA2('DEMO-M4-Q-ORG:R1:SNAP',256),q.`owner_id`,UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_quote` q
WHERE q.`code`='DEMO-M4-Q-ORG'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote_revision` r WHERE r.`quote_id`=q.`id` AND r.`revision_no`=1);

-- 冻结价格快照（policy_snapshot_json 含 cost/company_floor，供 P91 毛利解析；分级 normal）
INSERT INTO `__PREFIX__cpq_quote_price_snapshot`
  (`revision_id`,`quote_line_id`,`model_id`,`model_code`,`quantity`,`pricing_currency`,`quote_currency`,`tax_mode`,`base_amount`,`unit_subtotal`,`goods_amount`,`goods_discounted`,`fees_amount`,`untaxed_amount`,`tax_amount`,`total_amount`,`manual_discount`,`control_unit_price`,`classification`,`approval_level`,`price_hash`,`policy_snapshot_json`,`price_trace_json`,`createtime`)
SELECT r.`id`,l.`id`,l.`model_id`,'CPQ-DEMO-EQUIPMENT-A',2.0000,'CNY','CNY','tax_exclusive',
  120000.0000,120000.0000,240000.0000,216000.0000,0.0000,216000.0000,28080.0000,244080.0000,
  0.900000,96000.0000,'normal','none',SHA2('DEMO-M4-Q-OWN:R1:L1',256),
  '{"guide_price":"120000","line_floor":"108000","company_floor":"96000","cost":"60000"}',
  '{"margin":{"cost":"60000"}}',UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_quote` q
JOIN `__PREFIX__cpq_quote_revision` r ON r.`quote_id`=q.`id` AND r.`revision_no`=1
JOIN `__PREFIX__cpq_quote_line` l ON l.`quote_id`=q.`id` AND l.`line_no`=1
WHERE q.`code`='DEMO-M4-Q-OWN'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote_price_snapshot` p WHERE p.`revision_id`=r.`id` AND p.`quote_line_id`=l.`id`);

INSERT INTO `__PREFIX__cpq_quote_price_snapshot`
  (`revision_id`,`quote_line_id`,`model_id`,`model_code`,`quantity`,`pricing_currency`,`quote_currency`,`tax_mode`,`base_amount`,`unit_subtotal`,`goods_amount`,`goods_discounted`,`fees_amount`,`untaxed_amount`,`tax_amount`,`total_amount`,`manual_discount`,`control_unit_price`,`classification`,`approval_level`,`price_hash`,`policy_snapshot_json`,`price_trace_json`,`createtime`)
SELECT r.`id`,l.`id`,l.`model_id`,'CPQ-DEMO-EQUIPMENT-A',1.0000,'CNY','CNY','tax_exclusive',
  120000.0000,120000.0000,120000.0000,120000.0000,0.0000,120000.0000,15600.0000,135600.0000,
  1.000000,96000.0000,'normal','none',SHA2('DEMO-M4-Q-ORG:R1:L1',256),
  '{"guide_price":"120000","line_floor":"108000","company_floor":"96000","cost":"60000"}',
  '{"margin":{"cost":"60000"}}',UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_quote` q
JOIN `__PREFIX__cpq_quote_revision` r ON r.`quote_id`=q.`id` AND r.`revision_no`=1
JOIN `__PREFIX__cpq_quote_line` l ON l.`quote_id`=q.`id` AND l.`line_no`=1
WHERE q.`code`='DEMO-M4-Q-ORG'
  AND NOT EXISTS (SELECT 1 FROM `__PREFIX__cpq_quote_price_snapshot` p WHERE p.`revision_id`=r.`id` AND p.`quote_line_id`=l.`id`);

-- ============================================================
-- M4 E2E 验收矩阵补充数据（GYTAI-77）
-- Q-005 国际市场 USD 报价 / Q-006 策略缺失维度 / Q-008 发布新版本后冻结快照不变
-- ============================================================

-- 顶级区域：北美（USD）/ 欧洲（EUR）
INSERT INTO `__PREFIX__cpq_region`
  (`code`,`name`,`parent_id`,`path`,`level`,`default_currency`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-REGION-NA','北美',0,'/',1,'USD','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('CPQ-DEMO-REGION-EU','欧洲',0,'/',1,'EUR','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();
UPDATE `__PREFIX__cpq_region` SET `path`=CONCAT('/',`id`,'/') WHERE `code` IN ('CPQ-DEMO-REGION-NA','CPQ-DEMO-REGION-EU') AND `parent_id`=0;

-- 国际市场客户：北美（US/USD，命中国际策略）/ 欧洲（DE/EUR，无策略 → Q-006）
INSERT INTO `__PREFIX__cpq_customer`
  (`code`,`name`,`name_en`,`type`,`credit_code`,`country_code`,`region_id`,`customer_level_id`,`default_currency`,`payment_terms`,`trade_terms`,`sales_org_id`,`status`,`remark`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-CUSTOMER-NA','演示客户北美','Demo Customer NA','direct','DEMO91340000XXXXXX10','US',
  (SELECT `id` FROM `__PREFIX__cpq_region` WHERE `code`='CPQ-DEMO-REGION-NA'),
  (SELECT `id` FROM `__PREFIX__cpq_customer_level` WHERE `code`='CPQ-DEMO-LV-STANDARD'),
  'USD','月结30天','FOB',
  (SELECT `id` FROM `__PREFIX__cpq_sales_org` WHERE `code`='CPQ-DEMO-ORG-HQ'),
  'normal','Q-005 国际报价演示',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_customer`
  (`code`,`name`,`name_en`,`type`,`credit_code`,`country_code`,`region_id`,`customer_level_id`,`default_currency`,`payment_terms`,`trade_terms`,`sales_org_id`,`status`,`remark`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-CUSTOMER-EU','演示客户欧洲','Demo Customer EU','direct','DEMO91340000XXXXXX11','DE',
  (SELECT `id` FROM `__PREFIX__cpq_region` WHERE `code`='CPQ-DEMO-REGION-EU'),
  (SELECT `id` FROM `__PREFIX__cpq_customer_level` WHERE `code`='CPQ-DEMO-LV-STANDARD'),
  'EUR','月结30天','CIF',
  (SELECT `id` FROM `__PREFIX__cpq_sales_org` WHERE `code`='CPQ-DEMO-ORG-HQ'),
  'normal','Q-006 策略缺失演示',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

-- Q-008 专用客户：客户专属策略发布新版本后验证冻结快照不变（与客户A隔离）
INSERT INTO `__PREFIX__cpq_customer`
  (`code`,`name`,`name_en`,`type`,`credit_code`,`country_code`,`region_id`,`customer_level_id`,`default_currency`,`payment_terms`,`trade_terms`,`sales_org_id`,`status`,`remark`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-CUSTOMER-Q8','演示客户Q8','Demo Customer Q8','direct','DEMO91340000XXXXXX12','CN',
  (SELECT `id` FROM `__PREFIX__cpq_region` WHERE `code`='CPQ-DEMO-REGION-EAST'),
  (SELECT `id` FROM `__PREFIX__cpq_customer_level` WHERE `code`='CPQ-DEMO-LV-STANDARD'),
  'CNY','月结30天','EXW',
  (SELECT `id` FROM `__PREFIX__cpq_sales_org` WHERE `code`='CPQ-DEMO-ORG-EAST'),
  'normal','Q-008 客户专属策略演示',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

-- 国际市场价格表（CNY 计价，Q-005 演示 USD 报价币种换算）
INSERT INTO `__PREFIX__cpq_price_book`
  (`code`,`name`,`company`,`business_unit`,`market_scope`,`currency`,`tax_mode`,`priority`,`effective_date`,`expiry_date`,`version`,`status`,`createtime`,`updatetime`)
VALUES
  ('CPQ-DEMO-BOOK-INTL','2026年国际标准价格表','DEMO公司','装备制造','international','CNY','tax_exclusive',10,'2026-01-01','2026-12-31',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_price_entry`
  (`price_book_id`,`target_type`,`target_id`,`amount`,`unit`,`min_qty`,`max_qty`,`createtime`,`updatetime`)
SELECT `b`.`id`,'model',`m`.`id`,120000.0000,'set',0,NULL,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_price_book` `b`
JOIN `__PREFIX__cpq_product_model` `m` ON `m`.`code`='CPQ-DEMO-EQUIPMENT-A'
WHERE `b`.`code`='CPQ-DEMO-BOOK-INTL'
ON DUPLICATE KEY UPDATE `amount`=VALUES(`amount`),`updatetime`=UNIX_TIMESTAMP();

INSERT INTO `__PREFIX__cpq_price_entry`
  (`price_book_id`,`target_type`,`target_id`,`amount`,`unit`,`min_qty`,`max_qty`,`createtime`,`updatetime`)
SELECT `b`.`id`,'option',`ov`.`id`,8000.0000,'item',0,NULL,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_price_book` `b`
JOIN `__PREFIX__cpq_option_group` `og` ON `og`.`code`='power_level'
JOIN `__PREFIX__cpq_option_value` `ov` ON `ov`.`group_id`=`og`.`id` AND `ov`.`code`='high'
WHERE `b`.`code`='CPQ-DEMO-BOOK-INTL'
ON DUPLICATE KEY UPDATE `amount`=VALUES(`amount`),`updatetime`=UNIX_TIMESTAMP();

-- 国际策略（区域钉住北美；欧洲客户无命中 → Q-006 缺失维度）
INSERT INTO `__PREFIX__cpq_price_policy`
  (`code`,`name`,`dimension_key`,`company`,`business_unit`,`market_scope`,`region_code`,`customer_level`,`agent_level`,`customer_id`,`product_line`,`target_type`,`target_id`,`currency`,`unit`,`guide_price`,`line_floor`,`company_floor`,`cost`,`priority`,`effective_date`,`expiry_date`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-POLICY-INTL','演示设备A国际北美策略','','DEMO公司','装备制造','international','CPQ-DEMO-REGION-NA','','',NULL,'DEMO-LINE','model',`m`.`id`,
  'CNY','set',120000.0000,108000.0000,96000.0000,60000.0000,10,'2026-01-01','2026-12-31',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m` WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `guide_price`=VALUES(`guide_price`),`updatetime`=UNIX_TIMESTAMP();

-- Q-008 客户专属策略 v1（发布 v2 后旧报价冻结快照不变、新试算用新价）
INSERT INTO `__PREFIX__cpq_price_policy`
  (`code`,`name`,`dimension_key`,`company`,`business_unit`,`market_scope`,`region_code`,`customer_level`,`agent_level`,`customer_id`,`product_line`,`target_type`,`target_id`,`currency`,`unit`,`guide_price`,`line_floor`,`company_floor`,`cost`,`priority`,`effective_date`,`expiry_date`,`version`,`status`,`createtime`,`updatetime`)
SELECT 'CPQ-DEMO-POLICY-Q8','演示设备A客户Q8专属策略','','DEMO公司','装备制造','domestic','','','',
  (SELECT `id` FROM `__PREFIX__cpq_customer` WHERE `code`='CPQ-DEMO-CUSTOMER-Q8'),
  'DEMO-LINE','model',`m`.`id`,
  'CNY','set',120000.0000,108000.0000,96000.0000,60000.0000,20,'2026-01-01','2026-12-31',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` `m` WHERE `m`.`code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `guide_price`=VALUES(`guide_price`),`updatetime`=UNIX_TIMESTAMP();

SET FOREIGN_KEY_CHECKS = 1;
