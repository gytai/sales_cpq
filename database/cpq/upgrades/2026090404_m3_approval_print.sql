-- M3 审批状态机、委托代理、报价模板与打印记录（GYTAI-72/GYTAI-75，方案 §4.5/§4.6/§5.2、P59-P60、P70-P75）。
--
-- 内容：
--  1. 报价主表 status 枚举增加 returned（审批退回修改，回到可编辑态，重新提交产生新版本）；
--  2. 审批实例/任务/动作三表（固定路径状态机，动作与任务迁移同事务）；
--  3. 审批规则（P74：固定路径的节点/角色/候选人/产品线/SLA）；
--  4. 委托与代理（P75：不突破代理人原有数据权限，职责分离仍有效）；
--  5. 报价模板（P59：中英文、市场默认、变量白名单、版本状态）；
--  6. 报价打印记录（P60：异步 PDF 任务、文件哈希、下载计数；成功文件不可覆盖）。
--
-- 回滚参考（如需撤销本脚本）：
--  ALTER TABLE `__PREFIX__cpq_quote` MODIFY COLUMN `status` ENUM('draft','submitted','approved','sent','accepted','rejected','withdrawn','cancelled','expired','revised') NOT NULL DEFAULT 'draft' COMMENT '报价状态';
--  DROP TABLE IF EXISTS `__PREFIX__cpq_quote_document`;
--  DROP TABLE IF EXISTS `__PREFIX__cpq_quote_template`;
--  DROP TABLE IF EXISTS `__PREFIX__cpq_approval_delegation`;
--  DROP TABLE IF EXISTS `__PREFIX__cpq_approval_rule`;
--  DROP TABLE IF EXISTS `__PREFIX__cpq_approval_action`;
--  DROP TABLE IF EXISTS `__PREFIX__cpq_approval_task`;
--  DROP TABLE IF EXISTS `__PREFIX__cpq_approval_instance`;

-- ---------------------------------------------------------------------
-- 1. 报价状态：审批退回 returned
-- ---------------------------------------------------------------------
ALTER TABLE `__PREFIX__cpq_quote`
  MODIFY COLUMN `status` ENUM('draft','submitted','approved','sent','accepted','rejected','withdrawn','returned','cancelled','expired','revised') NOT NULL DEFAULT 'draft' COMMENT '报价状态';

-- ---------------------------------------------------------------------
-- 2. 审批实例/任务/动作（方案 §5.2 状态机）
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

-- ---------------------------------------------------------------------
-- 3. 审批规则（P74 固定路径：正常价→销售确认；产线；产线→公司）
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- 4. 委托与代理（P75）
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- 5. 报价模板（P59）
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- 6. 报价打印记录（P60 异步 PDF 任务）
-- ---------------------------------------------------------------------
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
