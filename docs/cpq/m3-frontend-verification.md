# M3 待办、审批与打印前端验证（GYTAI-75）

验证日期：2026-09-05。页面沿用 FastAdmin 原生 Bootstrap Table / Form / RequireJS 基线（GYTAI-79 模式，M2 向导/弹层交互惯例）。

## 页面范围（P02、P59-P60、P70-P75）

- **P02 我的待办**（`cpq/todo`）：待处理/已处理/我发起的/抄送我的四分类页签（任务类与实例类列结构经 `refreshOptions` 切换），节点/产品线/关键字筛选，SLA 剩余倒计时与超时红色徽标，代理来源标记；单条处理/催办/转交，批量转交（逐条服务端复校），批量批准按钮默认禁用（title 说明需逐条进详情核实）。
- **P70 我的待审批**（`cpq/approval_task`）：待处理/已处理/抄送我的，金额、审批等级、SLA、代理标记；进入审批、催办。
- **P71 报价审批详情**：内嵌服务端脱敏 JSON（`maskForRoles`，未授权成本/公司控制价不进入页面）；报价摘要 + 审批路径（节点任务状态/会签标记）+ SLA；配置与明细行（冻结价格快照）、价格（分级徽标 + Decimal 原样）、条款、风险项（等级/分级/折扣原因/条款偏差）、历史审批时间线（代理标记）；操作区仅批准/驳回/退回/加签/转交五动作（弹窗含意见 + 原因分类 + 候选人下拉，幂等键 + 版本校验，服务端逐项重复校验）；版本差异（v(n-1)→v(n) 汇总与行级差异高亮）；规则执行轨迹（节点任务表）。`can_act=0` 或 `version_stale=1` 时操作按钮禁用并提示任务失效。
- **P72 我发起的**（`cpq/approval_instance`）：当前节点/处理人/停留时间/SLA 超时/催办次数，催办（当前节点最小待办任务）、撤回（进行中）、完整流程视图（节点任务 + 动作时间线）。
- **P73 审批记录**（`cpq/approval_record`）：单号/产品线/节点/动作徽标/处理人（代理显示委托人）/意见/原因分类/动作前后哈希/来源 IP/时间；动作、产品线、时间范围（datetimerange）、关键字检索。
- **P74 审批规则**（`cpq/approval_rule`）：节点/角色/产品线/显式候选人（selectpage 多选 ↔ JSON 数组）/SLA/版本；仅停用可编辑/删除（服务端复校），启停切换（启用前候选人可解析校验）；规则模拟（等级+产品线 → 固定路径各节点命中规则/SLA/候选人）。
- **P75 委托与代理**（`cpq/approval_delegation`）：委托列表（展示态过期识别）、新增委托（代理人候选来自 `cpq/approval_delegation/candidates`，仅暴露 id/昵称/账号；时间窗 datetimepicker）、批准/拒绝（仅委托审批角色可见，服务端复校）、撤销；代理操作记录（动作 + 委托人标记）。
- **P59 报价模板**（`cpq/quote_template`）：中英文/市场/纸张/默认标记；结构化板块编辑器（封面/公司信息/产品表/技术参数/条款/签章/水印开关 + 文案），变量白名单点击插入，提交组装 `content_json`（服务端板块+变量白名单复校）；预览（测试数据渲染 + 缺失变量提示，iframe 隔离）；发布/停用/设为市场默认/复制新版本（已发布不可直接修改，服务端复校）。
- **P60 报价打印记录**（`cpq/quote_document`）：任务列表（单号/版本/模板/语言/状态/哈希/大小/下载次数/生成人/生成时间/失败原因）；排队/生成中 5s 自动轮询；生成 PDF 弹窗（已提交报价候选 + 语言 + 可选模板）；受控下载（哈希校验+计数+审计）、哈希验证弹窗（记录哈希 vs 实算哈希）、失败重试（仅失败任务）。

## 本次顺带修复（验收阻塞项，增量未覆盖他人成果）

- `ApprovalService`：`myTaskList`/`initiatedList`/`actionRecords` 的 `$where[] = [字段, 操作符, 值]` 三元组在别名查询下报 `where express error`（本仓库 TP5 的 `where(array)` 仅支持关联数组），改为关联数组写法；`initiatedList` 增补 `current_task_id`（催办入口）。
- `ApprovalDelegationService`：`myList` 同上；`delegatedActions` 与 `myList` 的 `(clone $base)->count()` 在本环境 PDO 下丢绑定参数（2031），计数改为独立查询。
- `Quote`（admin/api）控制器列表筛选同类三元组问题一并修复（M2 遗留的筛选即 500 隐患）。
- `Quote` 模型补齐 `STATUS_*` 常量（GYTAI-72 的 `ApprovalService::act` 引用 `QuoteModel::STATUS_SUBMITTED` 等，此前必现 Fatal）。
- `QuoteTemplateService::renderHtml`：`__terms_html` 未传时（预览）未定义索引，补默认值。
- `QuoteRevisionService` 提交联动审批后，M2 的 `tests/cpq/quote.php` 临时库补最小 `fa_admin`/`fa_auth_group` 表与超管组（销售确认节点需负责人存在）。
- `tests/cpq/upgrade.php`：脚本清单扩展至 M3（`2026090404_m3_approval_print.sql`），回退/前滚断言覆盖 M3 七表与 `cpq_quote.status` 枚举 `returned`。
- 报价列表/详情的编辑与提交入口状态白名单补 `returned`（审批退回后可编辑再提交）。
- 演示数据（`demo.sql`）：`cpq_approver`/`cpq_approver2` 演示审批人（密码 `Appr@123456`，line_pricer/company_pricer 组 + DEMO-LINE 数据范围）、产线/公司节点审批规则、中英文默认已发布模板；`group_concat_max_len` 会话调大。
- `CpqInstall`：注册 CPQ审批中心（待办/待审批/我发起的/审批记录/审批规则/委托与代理）与报价中心下报价模板/打印记录菜单与操作节点。

## 自动化结果

- `tests/cpq/m3_approval.php`：**96 断言全过**（三条固定路径、职责分离、幂等/重复操作、驳回/退回/转交/加签会签、版本失效、委托代理全链路、SLA、模板白名单/发布/默认唯一、打印任务幂等/哈希/下载计数/篡改拒绝）。2026-09-06 面向 GYTAI-72 验收补齐后端缺口：审批中撤回（非发起人拒绝、在途待办/实例联动取消）、英文 + USD 多币种 PDF（美元价目/策略/汇率、英文默认模板匹配、币种落库、`-en-` 文件名）、PDF 失败重试（模板失效 → failed 落库 + `pdf_failed` 审计 → 重试复用记录 `retry_count=1` → 成功后不再覆盖正式文件）。
- `tests/cpq/frontend_m3.php`：**79 断言全过**（页面入口、菜单节点、无 JS 浮点、五动作白名单、幂等键、HEX 转义内嵌、SLA/失效提示、批量批准禁用、演示数据）。
- 回归（2026-09-06 全量 19 套件 0 失败）：quote(55)、pricing(145)、price_channel(85)、master_data(66)、rule_analysis(33)、rule_engine(26)、upgrade(65)、frontend_m2(42)、frontend_m2_quote(56)、integration、poc、run 全部 PASS；`performance.php` 实测中文 PDF 87.7ms（目标 <30s）、100 行试算 47.7ms（<2s）、列表 2.0ms（<2s）。
- 全部 CPQ PHP `php -l`、全部 CPQ 页面脚本与浏览器脚本 `node --check`：PASS。

## 浏览器验收（`tests/cpq/browser/m3_approval_check.js`，59 断言全过，Console 零错误、无 5xx）

- 三条固定路径端到端：正常价格（销售确认→批准）、产线审批（0.93 折扣→产线批准）、公司审批（0.85 折扣→产线→公司两级）；
- 职责分离：负责人待办不含特批节点任务；从流程视图取任务 id 直调动作接口被拒（「无权处理该审批任务」）；
- 重复操作：已处理任务再次动作被拒（CPQ_APPROVAL_FORBIDDEN）；
- 委托代理：委托人创建 → 无权者不见批准按钮 → 有权角色批准生效 → 代理人待办带「代理」标记 → 代理批准 → 代理操作记录留痕；
- P59 模板预览渲染测试数据、已发布模板复制新版本草稿；
- P60 生成 → 轮询至已生成 → 哈希验证通过 → 受控下载返回 PDF（31860 bytes）→ 下载计数 +1；
- 版本失效：撤回后旧任务详情显示失效提示且操作全部禁用；
- 运行方式：`NODE_PATH=runtime/temp/cpq_browser/node/node_modules node tests/cpq/browser/m3_approval_check.js`。

截图证据：随工单附件（待办、审批详情、规则模拟、委托、记录、打印验证等 17 张）。

## 已知限制

- 审批人无权修改报价由「页面无编辑入口 + 服务端职责分离」双重保证；报价编辑能力本身仍由报价中心角色控制。
- 批量转交候选人取自首条任务的同节点候选人，服务端对每条任务逐校验，失败项在结果中列明。
- 浏览器脚本重跑幂等：委托步骤会先撤销本人未完成委托；模板复制新版本每跑一次生成一个 `-V*` 草稿（正式数据可按需清理）。
- 事项验收「PR 链接」按项目规则不提供（in_place 模式直接在 master 增量开发，不建分支/PR）。
