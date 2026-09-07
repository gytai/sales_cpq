# M2 六步报价向导与版本前端验证（GYTAI-70）

验证日期：2026-09-04。页面沿用 FastAdmin 原生 Bootstrap Table / Form / RequireJS 基线（GYTAI-79 模式）。

## 页面范围（P50-P58）

- P50-P55 六步向导 `cpq/quote/wizard`：① 客户基本信息（名称/产品线/币种/客户/代理商/销售组织/我方公司/市场范围）→ ② 选择产品（型号 selectpage + 数量/单位 + 多行明细）→ ③ 产品配置（逐行加载已发布 schema，单/多选/数量/文本，防抖服务端校验，错误/警告定位到配置组并点击跳转，动态显隐）→ ④ 价格与折扣（手工折扣+原因，「服务端试算」自动落库后同版本重算，行级分级徽标、整单最严格风险行高亮、阻断原因展示）→ ⑤ 商务条款（增删改，系统锁定条款只读）→ ⑥ 预览与提交（汇总预览 + 幂等键提交）。
- 草稿保存/恢复：任意步骤「保存草稿」（乐观锁）；编辑草稿/撤回态经向导恢复全部行/配置/条款；历史报价复制、修订均生成新草稿并打开向导。
- P56 报价列表：六组状态多视图（全部/待处理草稿/审批中/已批准/已发送·接受/已关闭）、关键字搜索、产品线/币种筛选、状态化行操作（详情/编辑/提交/撤回/修订/差异/复制）。
- P57 报价详情：整页只读（无可编辑输入），基本信息/版本与提交/明细行/条款/版本历史，按状态渲染操作按钮，服务端逐项重复校验。
- P58 版本差异：选择同一报价两个冻结版本（支持空基线 v0），汇总金额差异（Decimal 字符串原样）+ 行级新增/删除/修改字段差异高亮。

## 边界与原则

- 金额不经 JS 浮点计算（静态契约断言无 `parseFloat/toFixed/Math.round/Number(`）；试算/提交全部使用服务端同版本重算结果，前端交互仅作提示。
- 试算响应按管理员角色脱敏（`maskForRoles` + `SensitiveFieldService::rolesOfAdmin`），未授权成本/公司控制价不进入页面。
- 提交幂等键防重复；乐观锁冲突提示用户重新进入编辑；后端错误按业务码回到对应步骤（`CPQ_CONFIG_*`→第3步、`CPQ_PRICE_*`/`CPQ_EXCHANGE_*`→第4步、其余→第1步）并定位字段/行。
- 列表与写操作均受产品线数据范围约束（`ProductLineScopeService`，fail-closed）。

## 本次顺带修复（验收阻塞项，增量未覆盖他人成果）

- `QuoteRevisionService::buildPricingRequest`：`business_unit => ''` 空串阻断了 PricingService 向型号所属系列的回退，导致报价试算/提交必现 `CPQ_PRICE_BOOK_MISSING`；改为不传该键由服务端回退。
- 报价定价上下文补齐 P50 步骤一字段：`cpq_quote` 新增 `company`/`market_scope` 列（升级脚本 `2026090403_m2_quote_context_dims.sql`，install.sql 同步），新建/更新/复制/修订携带，市场范围缺省按客户国家推导（CN→domestic）。
- `tests/cpq/upgrade.php`：场景 B 回退 SQL 增补报价表 DROP（修复 GYTAI-71 报价表外键导致的既有回退失败），脚本清单与断言扩展至 6 个升级脚本。

## 自动化结果

- `php tests/cpq/frontend_m2_quote.php`：PASS（49 assertions，新增静态契约）；
- `php tests/cpq/frontend_m2.php`：PASS（42 assertions）；
- `tests/cpq/quote.php`：PASS（51 assertions）；`pricing.php`：PASS（145）；`price_channel.php`：PASS（85）；`master_data.php`：PASS（66）；`rule_analysis.php`：PASS（33）；`rule_engine.php`：PASS（26）；`upgrade.php`：PASS（45）；`integration.php`：PASS；`run.php` / `poc.php`：PASS；
- 全部 CPQ PHP `php -l`、全部 CPQ 页面脚本与浏览器脚本 `node --check`：PASS；
- `php think cpq:upgrade` 复跑幂等（Nothing to upgrade）。

## 浏览器验收（`tests/cpq/browser/m2_quote_check.js`，23 断言全过，Console 零错误、无 5xx）

- Q-001 主路径：六步向导建单 → 服务端试算总额 `136165.0000`（与 P36 模拟器同口径）、无需审批、允许提交 → 幂等提交成功，版本 v1 冻结；
- Q-004：0.75 折扣试算即「禁止提交/禁止」；提交被服务端硬阻断（`CPQ_PRICE_BELOW_COMPANY_FLOOR`），错误回到第 4 步并标红；
- P53：详情页整页只读、版本历史含 v1、已提交态呈现撤回/修订/差异/复制；
- Q-010：已提交报价创建修订 → 原报价只读（已修订），新草稿在向导中恢复明细行、乐观锁从 v1 开始；
- P58：v0→v1 差异显示新增行与汇总金额差异；
- 运行方式：`NODE_PATH=runtime/temp/cpq_browser/node/node_modules node tests/cpq/browser/m2_quote_check.js`（puppeteer-core 隔离安装在 runtime/temp，已 gitignore）。

截图证据：随工单附件（列表多视图、Q-001 试算/提交、Q-004 阻断、详情、差异）。

## 已知限制

- Q-002/Q-003/Q-005/Q-006 的审批链路等级展示依赖 M3 审批后端（GYTAI-72），当前提交后状态停在「已提交」；价格分级与阻断已在第 4 步完整呈现。
- 并发版本变化（乐观锁/幂等）与越权（数据范围/未授权客户）由 `tests/cpq/quote.php` 51 断言覆盖；浏览器侧以乐观锁提示呈现。
- 附件上传、PDF 预览属 M3 范围，本任务未包含。
