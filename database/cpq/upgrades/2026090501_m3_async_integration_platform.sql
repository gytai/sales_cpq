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
