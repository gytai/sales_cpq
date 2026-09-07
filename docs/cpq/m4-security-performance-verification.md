# M4 安全与性能验证报告（GYTAI-76）

> 执行环境：`sales_cpq-app-1` 容器（PHP 7.4.33 / MySQL / Redis，见 `docs/adr/0003-docker-runtime-baseline.md`）。
> 复现命令：`docker exec sales_cpq-app-1 php /var/www/html/tests/cpq/regression.php`（19 套件汇总，任一失败整体非零退出）。
> 本文档数据全部为本次收口实测；测试数据均为 `CPQ-*-TEST`/`CPQ-SEC-`/`CPQ-PERF-` 前缀的临时库构造，不含真实客户/价格数据。

## 1. 回归总览

| 套件 | 验收覆盖 | 断言数 | 结果 |
| --- | --- | --- | --- |
| run.php | C-001~C-004 配置规则服务端校验 | — | PASS |
| rule_analysis.php | C-005 冲突/循环发布拦截 + 500ms | 33 | PASS |
| rule_engine.php | C-005~C-007 规则引擎/BOM/快照 | 26 | PASS |
| master_data.php | M1 版本/状态机/引用保护 | 66 | PASS |
| price_channel.php | P-001~P-012 渠道价格主数据 + 脱敏 | 85 | PASS |
| pricing.php | Q-001~Q-008 三层控制价/汇率/缺失维度 | 145 | PASS |
| quote.php | Q-007~Q-010 快照/修订/乐观锁/幂等 | 55 | PASS |
| m3_approval.php | 审批链路/职责分离/PDF 篡改审计 | 75 | PASS |
| m3_platform.php | 集成平台/凭证保险箱/事件重试 | 39 | PASS |
| m4_reporting.php | 报表筛选/数据范围/脱敏审计 | 60 | PASS |
| security.php | 注入/XSS/越权/上传/日志/下载权限 | 102 | PASS |
| performance.php | 四项性能目标 | 16 | PASS |
| upgrade.php | 空库安装/已有库升级/幂等复跑 | 65 | PASS |
| frontend_m2/m2_quote/m3/m4 | 前端静态契约 | 42/56/79/81 | PASS |
| integration.php | C-007 服务端集成（演示库） | — | PASS |
| poc.php | 队列/PDF/Excel 组件 | — | PASS |

汇总：19 套件全绿。composer 入口：`test:cpq-regression`（单套件入口见 composer.json `scripts`）。

## 2. 安全验证明细（tests/cpq/security.php，102 断言）

- **SQL 注入**：3 组恶意载荷（`' OR '1'='1`、`1; DROP TABLE …`、`UNION SELECT`）走精确绑定与 like 查询，不报错、零越界返回、表结构完好；六个 API 控制器全部结构化 where，未发现拼接输入进 SQL 的点。
- **XSS**：`<script>`/`<img onerror>` 自由文本经草稿/条款落库回读结构完整；渲染侧断言：报价详情接口 `JSON_HEX_*` 编码（`application/admin/controller/cpq/Quote.php:497`）、前端 `escapeHtml`、PDF 条款 `htmlspecialchars`（`QuoteDocumentService.php:398`）。
- **CSRF**：登录表单 `__token__` 校验与 Backend token 能力存在；框架无全局 CSRF 中间件，见第 5 节残余风险。
- **越权**：脱敏四档（sales/line_pricer/company_pricer/无角色）与 `rolesOfAdmin` fail-closed；**区域/组织/负责人维度单条读取兜底**（本次修复，见第 4 节）13 断言；PDF 生成/下载叠加同一口径，华东销售对华南报价 createJob/prepareDownload 均被拒。
- **文件上传**：报价附件无写入入口（仅模型关联）；主数据 Excel 导入 6 个控制器统一 `xlsx/xls/csv` 扩展名白名单；nginx 对 uploads/assets 禁 PHP、拒绝隐藏文件（`docker/nginx/fastadmin.conf`）。
- **敏感日志**：审计 detail 递归脱敏（password/secret/token/credential_/apikey/Authorization → `[REDACTED]`），落库无明文；CPQ 代码目录零 `Log::/trace(` 敏感词命中。
- **凭证与令牌**：集成凭证 AES-256-GCM 落库只存密文/nonce/tag，`publicConfig` 永不回显；错密钥/坏密文解密即抛；HMAC 验签含 ±300s 重放窗口、sha256/sha512 白名单；导出下载令牌 sha256 落库、仅本人可领、首次下载后烧毁。

## 3. 性能验证明细（tests/cpq/performance.php，16 断言）

| 目标 | 实测（warmup 后正式值） | 阈值 | 结论 |
| --- | --- | --- | --- |
| 60 组/240 规则配置校验 | 0.3 ms | < 500 ms | PASS |
| 100 行报价试算 | 44.0 ms | < 2 s | PASS |
| 中文 PDF 生成（wqy 字体） | 88.1 ms | < 30 s | PASS |
| 报价列表（1200 行种子 + 数据范围分页 + count） | 2.1 ms | < 2 s | PASS |

列表查询同时断言数据范围收窄正确性：可见/隐藏各 600 行，返回恰为授权集合且无交集。所有场景 warmup + 多轮正式取最大值，排除一次性开销。

## 4. 本次修复的后端缺陷

**区域/组织/负责人维度单条读取越权缺口**：此前 `assertScoped`（admin/api 两个 Quote 控制器）与 PDF 生成/下载只校验产品线维度，华东销售可凭 quote id 直读同产品线的华南报价详情/下载其 PDF（列表查询层有收窄，单条入口没有）。修复：

- `QuoteDataScopeService::assertQuoteAccess()`（`application/common/service/cpq/QuoteDataScopeService.php:402`）：单条报价的组织 ∩ 区域 ∩ 负责人兜底，与 `applyToQuoteQuery` 同一口径，无可用范围 fail-closed；
- 接入 `application/admin/controller/cpq/Quote.php:374`、`application/api/controller/cpq/Quote.php:202`、`QuoteDocumentService::createJob`（:62）与 `prepareDownload`（:328）。

修复后全量回归（19 套件）无回归；对应验收项「华东销售不能查看无授权的其他区域报价」「删除前端按钮或篡改请求不能绕过服务端权限」由 security.php B5/B6 覆盖。

## 5. 残余风险（明确不阻塞，建议后续迭代处理）

1. **CSRF**：FastAdmin 该版本无全局 CSRF 中间件，CPQ 后台写接口依赖登录态 + 随机后台入口 + 会话 cookie。建议后续在 Backend 基类为 CPQ 写操作统一加 `$this->token()` 校验。
2. **主数据派生文本的模板转义**：约 78 处列表/表单模板对枚举与主数据派生变量（公司名、产品线名等）使用未加管道的 `{$var}` 输出（`default_filter` 为空）。自由文本字段（备注/条款/客户名）已全部转义，主数据仅管理员可维护，风险面有限；建议统一补 `|htmlentities` 或设 `default_filter`（需全量回归后台页面）。
3. **HMAC 大写 hex 归一化**：`IntegrationAuthService::verifyHmac` 对入参签名 `strtolower` 后 `hash_equals` 比较，不削弱安全性，属规格偏差。
4. **路由文档偏差**：`api/cpq/v1/quotes*` 未在 `application/route.php` 显式注册（默认调度可达），与 `application/api/controller/cpq/Quote.php` 头部注释清单表述不一致，建议下版本对齐注释或注册显式路由。

## 6. 升级与运维验证

- 升级脚本：`tests/cpq/upgrade.php` 65 断言覆盖空库安装语义、已有库逐版本升级、`applyAll` 幂等空操作；`php think cpq:upgrade --list` 提供只读预检。
- 部署/迁移/备份恢复/密钥/发布/回滚手册：`docs/cpq/deployment-runbook.md`。
- 未升级任何框架/运行时版本，未扩大 MVP 范围。
