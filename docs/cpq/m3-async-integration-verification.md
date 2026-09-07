# M3 异步任务、导入导出与集成平台验证

覆盖 GYTAI-73 / P94 / P100-P105。

## 交付契约

- `NumberRuleService` 使用唯一计数器行与事务行锁生成 `{YYYY}/{MM}/{DD}/{SCOPE}/{SEQn}` 编号；新周期首请求的唯一键竞争会安全重读。
- 被 `cpq_dictionary_reference` 引用的字典值禁止改码、改名和删除，只允许改为 `disabled`。
- `cpq_job` 统一承载 PDF、Excel 导入导出、ERP 同步、邮件与定时发布状态；幂等创建、原子抢占、失败脱敏、受控重试、错误报告均由服务层执行。行数大于 5,000 时 `requiresAsync()` 强制返回 true。
- 导入分为 preview 与 confirm 两阶段；预览只落临时批次，不写业务表，确认令牌使用 SHA-256 保存且有时效。
- 报价明细 XLSX 在服务端应用产品线范围和敏感字段掩码；下载要求创建人或审计管理员、短期令牌、有效期和 SHA-256 校验，每次成功/拒绝都落下载审计。
- CRM/ERP 凭证使用 AES-256-GCM 保存，配置响应只返回 `credentials_configured`；HMAC 含时间窗，OAuth2 只通过 Authorization header 使用。Outbox 对来源事件去重、原子抢占、指数退避并最终进入 dead。
- `php think cpq:schedule` 幂等处理定时发布、报价过期和到期的集成重试，不内置任何真实 ERP 写入。

## 验证命令

```bash
docker exec sales_cpq-app-1 sh -c 'cd /var/www/html && php tests/cpq/upgrade.php && php tests/cpq/m3_platform.php'
```

专项测试包含 12 路并发编号、重复事件、失败重试/死信、导入预览确认、越权导出、下载审计、凭证不回显及 HMAC 防篡改。
