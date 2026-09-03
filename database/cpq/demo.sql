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
SELECT 'CAPACITY-FORMULA','容量计算','FORMULA',`id`,'DEMO-LINE','{}',
  '[{"action":"formula","target":"calculated_capacity","operation":"multiply","operands":[{"field":"configuration.quantity"},{"value":2.5}],"scale":4}]',
  70,'blocking','',1,'published',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `__PREFIX__cpq_product_model` WHERE `code`='CPQ-DEMO-EQUIPMENT-A'
ON DUPLICATE KEY UPDATE `action_json`=VALUES(`action_json`),`updatetime`=UNIX_TIMESTAMP();

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
  ('CPQ-DEMO-SERVICE-A','安装调试','安装调试服务','Installation Service','service','DEMO-LINE','SERVICE',0,'通用安装调试演示服务','normal',UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`),`updatetime`=UNIX_TIMESTAMP();

SET FOREIGN_KEY_CHECKS = 1;
