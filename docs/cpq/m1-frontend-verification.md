# M1 产品中心与配置器前端验证记录（GYTAI-65）

对应方案 P10-P20 / P52，验收用例见 `docs/cpq/acceptance-tests.md` 第 4 节（C-001～C-007）。
本文件只记录真实跑过的验证与结果。

## 1. 环境

- Docker Compose 本地栈（nginx `127.0.0.1:8082`，PHP 7.4.33 容器，MySQL 8.0，库 `fastadmin`）；
- 浏览器自动化：puppeteer-core + 本机 Chrome，脚本 `tests/cpq/browser/check.js`；
- 复跑命令：

```bash
docker compose --env-file .env.docker up -d        # 环境已就绪可跳过
docker exec sales_cpq-app-1 php /var/www/html/think cpq:install   # 同步菜单权限节点
NODE_PATH=runtime/temp/cpq_browser/node_modules node tests/cpq/browser/check.js
```

注意：本轮为便于自动化登录，将本地开发库 admin 密码重置为 `Admin@123456`（仅 Docker 开发库，不影响其他环境）。

## 2. 浏览器验收结果（19 PASS / 0 FAIL / 0 Console 错误）

| 项 | 结果 |
| --- | --- |
| 登录后台并展示 CPQ产品中心菜单 | ✅ |
| 发布不可编辑：已发布系列行隐藏编辑/删除按钮（服务端 `assertEditable` 重复拦截） | ✅ |
| 版本操作：已发布行展示详情/停用/复制新版本按钮 | ✅ |
| 详情页只读渲染（14 个输入全部禁用、提交栏隐藏） | ✅ |
| C-006 复制新版本：已发布系列复制出 v2 草稿，草稿可删除清理 | ✅ |
| 新增表单空提交出现必填中文校验提示 | ✅ |
| 配置器加载：6 个配置组 + 分组导航（单选/多选/数量/文本/只读计算齐全） | ✅ |
| C-002/C-003/C-004 依赖、互斥、数量上限由服务端检出并定位（REQUIRE-COOLING / EXCLUDE-OFFLINE / QUANTITY-RANGE） | ✅ |
| 服务端错误定位：面板标红 + 导航角标 + 错误列表可点击跳转 | ✅ |
| C-001 必选项未选择被阻止并定位配置组 | ✅ |
| 合法配置：校验通过 + 配置摘要含 configuration_hash + BOM 模拟生成（5 行物料） | ✅ |
| C-007 绕过前端直调服务端被拒绝（`配置不合法，不能生成 BOM：高功率型号必须配置增强散热`） | ✅ |
| 规则编辑器：结构化编辑双向同步 JSON 预览 | ✅ |
| C-005 冲突检测：循环依赖结构化展示（calculated_capacity → quantity → calculated_capacity） | ✅ |
| 单规则测试：命中并返回试算配置 | ✅ |
| C-005 新规则形成循环依赖发布失败（发布门禁与编辑器检测同一套 RuleAnalyzer） | ✅ |
| 删除被引用配置组：弹窗展示引用来源（features 被 型号配置结构 引用（1 条）） | ✅ |

截图随任务评论附件提交（`01-series-list` ~ `08-reference-blocked`）。

## 3. 后端回归（容器内，全部 PASS）

```
tests/cpq/run.php           ConfigurationService 单元测试 PASS
tests/cpq/integration.php   数据库集成测试 PASS
tests/cpq/rule_analysis.php 规则静态分析 PASS（含 60 组/240 规则 < 500ms 性能断言）
tests/cpq/rule_engine.php   规则引擎 PASS
tests/cpq/master_data.php   主数据治理 PASS（66 assertions）
tests/cpq/upgrade.php       升级脚本 PASS（18 assertions）
tests/cpq/price_channel.php 价格渠道 PASS
tests/cpq/poc.php           M0 组件 PoC PASS
```

## 4. 本轮修复的前序任务遗留缺陷（浏览器验证中发现，最小修改）

1. **全部 26 个 CPQ 后台控制器 trait 属性冲突 500**：`CpqRelationIndex` 声明 `$cpqScopeType`、`CpqVersioned` 声明 `$cpqRequiresApproval`/`$cpqVersionedStatuses`，与控制器同名属性默认值不同，PHP trait 组合直接 fatal（GYTAI-67 交付时仅 lint 未跑 HTTP 层）。修复：trait 移除属性声明，全部由控制器声明。
2. **`CpqVersioned::copy` 成功但响应 500**：`$this->success($msg, ['id'=>..])` 把数组传进了 ThinkPHP `success($msg, $url, $data)` 的 `$url` 位（Jump.php strpos 报错），草稿已创建但前端收到 500。修复：改为 `success($msg, null, $data)`。
3. **同类签名 bug ×6**（GYTAI-69 的导入预览接口）：`TaxRule`/`PricePolicy`/`Customer`/`FeeRule`/`PriceEntry`/`ExchangeRate` 的 `success('预览完成', $result)` 同样把数组传到 `$url` 位，已一并修复为 `success('预览完成', null, $result)`。
4. **配置器 BOM 按钮 id 冲突**：新增「BOM 模拟」按钮 id `cpq-bom` 与 BOM 表格 tbody 的 id 重复，导致 jQuery `#cpq-bom` 选中按钮。修复：按钮改名 `cpq-bom-run`。
5. **演示数据 CAPACITY-FORMULA 永真无守卫**：数量为空时公式操作数非数值导致整体验证报错。修复：`database/cpq/demo.sql` 该规则条件改为 `configuration.quantity not_empty`（同步更新了运行库），不影响规则引擎语义与既有测试。

## 5. 交付物清单

- 规则编辑器：`application/admin/view/cpq/config_rule/form.html` + `public/assets/js/backend/cpq/config_rule.js`（结构化条件/动作编辑、JSON 预览双向同步、单规则测试 `cpq/config_rule/testrule`、冲突检测 `cpq/config_rule/analyze`）；
- 配置器增强：`configurator.js` 选择变化防抖自动校验（实时依赖/互斥/动态显隐提示）、独立「BOM 模拟」按钮（`cpq/configurator/bom`，与 `/api/cpq/v1/configurations/bom` 同口径，配置非法服务端拒绝）；
- 公共组件 `public/assets/js/backend/cpq/common.js`：详情按钮、版本守卫（发布/失效隐藏编辑、仅草稿可删）、版本生命周期按钮组（提交/发布/停用/复制新版本，权限感知）、删除引用弹窗、详情只读模式；
- 12 个实体页面：`detail.html` 只读详情、列表 `data-auth-*` 权限注入、operate 列接入新按钮组；
- 后端：`CpqRelationIndex::detail`、`ConfigRule::testrule/analyze`、`Configurator::bom`、`MasterDataLifecycleService::collectEffectiveRuleSet/analyzeRuleSet`（从发布门禁提取，逻辑不变）；
- 菜单权限：`CpqInstall` 新增 detail/testrule/analyze/bom 节点（已在运行库重装生效）；
- 浏览器验收脚本：`tests/cpq/browser/check.js`（19 断言 + Console 错误收集）。

## 6. 未覆盖项

- P11/P13/P15 详情页采用「复用表单 + 只读禁用」实现，未做独立排版设计；
- 权限按钮的服务端越权用例（如删除按钮 DOM 被篡改后直发请求）由后端产品线范围校验覆盖（master_data.php 已断言），浏览器端未单独模拟；
- M2/M3 页面（价格中心、报价向导、审批）不在本任务范围。
