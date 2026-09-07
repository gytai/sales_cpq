# CPQ 验收用例

> 仓库上下文文档。上游需求：`docs/FastAdmin-CPQ开发功能与实施方案.md` §12。
> 测试数据统一 `CPQ-DEMO` 前缀；每条用例须落为可执行测试（tests/cpq/ 或接口测试）后方可勾选。

## 1. 产品测试数据

| 编码 | 说明 |
| --- | --- |
| `CPQ-DEMO-EQUIPMENT-A` | 单选、多选、数量型、只读计算型配置组齐全的标准设备（demo.sql 已含） |
| `CPQ-DEMO-EQUIPMENT-B` | 不同功率、材质、尺寸和电气制式的可配置设备 |
| `CPQ-DEMO-MODULE-A` | 可独立报价，也可作为设备选项依赖的功能模块 |
| `CPQ-DEMO-ACCESSORY-A` | 有数量上下限的通用配件 |
| `CPQ-DEMO-SOFTWARE-A` | 按期限或数量计价的软件授权 |
| `CPQ-DEMO-SERVICE-A` / `CPQ-DEMO-SERVICE-B` | 安装调试服务 / 培训或质保延长服务 |
| 推荐配置模板 ×3 | 标准环境、高温环境、海外市场 |

## 2. 客户测试数据

| 编码 | 属性 |
| --- | --- |
| `CPQ-DEMO-CUSTOMER-01` | 直销、华东、国内、CNY |
| `CPQ-DEMO-AGENT-01` | 一级代理、华东、国内、CNY |
| `CPQ-DEMO-CUSTOMER-02` | 战略客户、华南、国内、CNY |
| `CPQ-DEMO-AGENT-02` | 一级代理、东南亚、国际、USD |

## 3. 核心报价验收场景（M2）

| 用例 | 场景 | 预期结果 |
| --- | --- | --- |
| `Q-001` | 报价等于或高于指导价 | 正常，可批准 |
| `Q-002` | 低于指导价但不低于产线控制价 | 必须进入产线审批 |
| `Q-003` | 低于产线控制价但不低于公司控制价 | 必须填写理由并进入公司审批 |
| `Q-004` | 低于公司控制价 | 前端和 API 均阻止提交 |
| `Q-005` | 国际客户、USD 价格表 | 使用有效汇率快照，进入正确审批层级 |
| `Q-006` | 缺少指定区域价格策略 | 阻止提交并指出缺失维度 |
| `Q-007` | 多行报价包含不同风险等级 | 整单取最严格审批等级 |
| `Q-008` | 价格发布后打开历史报价 | 历史报价金额和轨迹不变化 |
| `Q-009` | 审批过程中修改报价 | 原任务失效，必须提交新版本 |
| `Q-010` | 已批准报价修订 | 创建新版本，旧版保持只读 |

浏览器流程验证（GYTAI-70，`tests/cpq/browser/m2_quote_check.js`，23 断言全过、Console 零错误）：Q-001 六步向导建单/试算/幂等提交（总额 `136165.0000` 与 P36 同口径）、Q-004 低于公司控制价试算禁止 + 提交服务端硬阻断并回到第 4 步、Q-010 修订生成新草稿且旧版只读、P50 列表多视图、P53 详情只读与状态化操作、P58 版本差异。证据与截图：`docs/cpq/m2-quote-frontend-verification.md`。

## 4. 配置规则验收场景（M1）

| 用例 | 场景 | 预期结果 | 覆盖测试 |
| --- | --- | --- | --- |
| `C-001` | 必选项未选择 | 阻止进入价格步骤并定位配置组 | tests/cpq/run.php（CPQ_CONFIG_REQUIRED）✅ |
| `C-002` | 选择依赖项 | 自动选择或要求选择被依赖项 | run.php（CPQ_CONFIG_REQUIRES）✅ |
| `C-003` | 选择互斥项 | 禁用冲突项并说明规则来源 | run.php（CPQ_CONFIG_EXCLUDES）✅ |
| `C-004` | 数量超过上限 | 阻止保存并显示允许范围 | run.php（CPQ_CONFIG_MIN_MAX / MAX_SELECT）✅ |
| `C-005` | 新规则形成循环依赖 | 规则发布失败 | rule_analysis.php + rule_engine.php（循环/永真冲突/不可达均在发布期拦截）✅ |
| `C-006` | 配置规则版本更新 | 历史报价仍可按旧快照还原 | rule_engine.php（v1 行冻结 + 旧快照重放哈希不变；报价侧快照落库在 M2）✅ |
| `C-007` | 前端绕过规则直接调用 API | 服务端拒绝非法配置 | integration.php 覆盖服务端校验 ✅；rule_engine.php 覆盖未发布/不存在型号与非法选项分支 ✅ |

补充回归（M1 已发现并固定的行为，须保持）：

- 等价配置（键序不同、数值格式 `2.0000`/`1e3`）必须生成相同 configuration_hash；
- 整数末尾的零不得被截断（`1000` 保持 `1000`）；
- 存在已发布 BOM 映射时按映射生成 BOM，数量公式与损耗率精确计算（`2*2+1` 含 0.1 损耗率 → `5.5`）；
- 性能目标：60 配置组 / 240 规则的一次完整服务端校验 < 500ms（rule_analysis.php ✅）；
- 影响 BOM 的选项若无选项物料编码且无已发布映射、且未标记 `no_material`，必须被缺失映射校验检出（rule_engine.php ✅）。

浏览器流程验证（GYTAI-65，`tests/cpq/browser/check.js`，19 断言全过、Console 零错误）：C-001~C-004 配置器端到端（错误定位标红/导航角标/点击跳转）、C-005 规则编辑器冲突检测 + 发布拦截、C-006 列表复制新版本、C-007 绕过前端直调服务端 BOM 被拒。证据与截图：`docs/cpq/m1-frontend-verification.md`。

## 5. 权限与审计验收（M2/M3）

- [x] 销售不能通过列表、详情、导出或 API 获取成本和公司控制价（SensitiveFieldService 字段级脱敏，price_channel.php ✅；接口/页面全链路在 M2 前端与 M4 收口继续验证）；
- [x] 华东销售不能查看无授权的其他区域报价（查询层收窄 + 单条读取兜底 `QuoteDataScopeService::assertQuoteAccess`，security.php B5 ✅）；
- [x] 产线审批人只能审批授权产品线（价格策略发布范围校验在 price_channel.php ✅；审批链路在 m3_approval.php ✅ 与 m3_approval_check.js ✅ 覆盖：候选人按产品线数据范围过滤，越权动作被 RuntimeException 拒绝）；
- [x] 报价人不能批准自己的公司级特价（职责分离：act() 拒绝报价负责人/提交人处理特批节点，m3_approval.php 与浏览器直调验证 ✅）；
- [x] 每次查看敏感价格、导出和正式 PDF 下载均产生审计记录（敏感查看/导出审计在 price_channel.php ✅；PDF 请求/生成/下载/篡改审计在 m3_approval.php ✅）；
- [x] 删除前端按钮或篡改请求不能绕过服务端权限（服务端硬校验：提交硬阻断 quote.php ✅、越权 act 拒绝 m3_approval.php ✅、PDF/详情单条越权拒绝 security.php ✅）。

## 5.1 客户渠道与价格主数据验收（M2，price_channel.php）

| 用例 | 场景 | 预期结果 | 覆盖 |
| --- | --- | --- | --- |
| `P-001` | 客户/区域/价格版本编码重复 | 唯一键拒绝 | ✅ |
| `P-002` | 区域/组织树新增、移动 | path/level 自动维护，子树级联重建 | ✅ |
| `P-003` | 父节点设为自身后代 | 判环拒绝 | ✅ |
| `P-004` | 删除有子节点或被引用的区域/等级/客户 | 删除保护拦截并给出引用来源 | ✅ |
| `P-005` | 指导价/产线控制价/公司控制价关系 | `指导价 >= 产线控制价 >= 公司控制价 >= 0` 强制（bccomp，禁 float） | ✅ |
| `P-006` | 价格策略同维度时间重叠发布 | 拒绝 | ✅ |
| `P-007` | 价格表同范围同优先级时间重叠发布 | 拒绝 | ✅ |
| `P-008` | 价格规则同互斥组同优先级且条件可能同时命中 | 禁止发布 | ✅ |
| `P-009` | 已发布价格表/策略/规则修改 | 版本不可变，只能复制新版本 | ✅ |
| `P-010` | 发布版本生效后修改/撤回 | 拒绝；未生效可撤回；回滚=重发旧内容新版本 | ✅ |
| `P-011` | 在售型号价格覆盖率 | 覆盖率报告列出缺失型号 | ✅ |
| `P-012` | 导入预览 | 错误行契约（row/field/code/message），不写库 | ✅ |

## 6. 工程基线验收（M0，本文档建立时核验）

- [x] 新环境按 `docker/README.md` 一次启动成功（Docker 29.x）；
- [x] 登录（后台入口 + 随机管理员密码）、RBAC 菜单（cpq:install 写入菜单规则）；
- [x] 后台 CRUD（FastAdmin Backend trait + CPQ 控制器）；
- [x] RequireJS 页面脚本加载（后台 cpq 模块）；
- [x] 队列（think-queue 1.1.6 + Redis 驱动 + phpredis，容器内验证）；
- [x] PDF（mpdf 8.2 中英文渲染）；
- [x] Excel（PhpSpreadsheet 1.30 读写）；
- [x] 依赖锁定（composer.lock 入库）与测试命令固定（composer test:cpq / test:cpq-integration）。

详细证据：`docs/cpq/m0-poc.md`。

## 7. M4 收口验收（GYTAI-76）

- [x] 集成回归一键串联：`tests/cpq/regression.php`（composer `test:cpq-regression`），19 套件全绿，覆盖 Q-001~Q-010、C-001~C-007、P-001~P-012 与权限/审计全场景；
- [x] 安全：`tests/cpq/security.php`（102 断言）覆盖 SQL 注入、XSS、CSRF 基线、字段脱敏、区域/组织/负责人越权（含单条读取兜底修复）、文件上传面、敏感日志脱敏、凭证保险箱与 PDF/导出下载权限；
- [x] 性能：`tests/cpq/performance.php`（16 断言）实测配置校验 0.3ms（<500ms）、100 行试算 44ms（<2s）、中文 PDF 88ms（<30s）、列表 2.1ms（<2s）；
- [x] 升级：`tests/cpq/upgrade.php`（65 断言）空库安装、已有库逐版本升级、复跑幂等；
- [x] 运维交付：`docs/cpq/deployment-runbook.md`（test/prod Docker、迁移、备份恢复、密钥、发布、回滚），安全/性能报告 `docs/cpq/m4-security-performance-verification.md`；
- [x] 修复缺陷：区域/组织/负责人维度单条读取越权缺口（详见验证报告第 4 节）；未升级框架、未扩大 MVP。
