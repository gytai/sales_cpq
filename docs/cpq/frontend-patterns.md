# CPQ 前端基线与页面模式

本文档固化 FastAdmin 原生技术栈（Bootstrap 3、jQuery、RequireJS、Bootstrap Table、FastAdmin Form）下 CPQ 页面的目录结构、交互模式与验证方式，供 P10–P106 页面开发复用。基线实现见 `application/admin/controller/cpq/`、`application/admin/view/cpq/`、`public/assets/js/backend/cpq/`。

## 1. 目录与命名

| 层 | 路径 | 约定 |
| --- | --- | --- |
| 控制器 | `application/admin/controller/cpq/ProductSeries.php` | 继承 `app\common\controller\Backend`，只做参数接收与响应；单表 CRUD 复用 `Backend` trait，关联展开用 `CpqRelationIndex`，版本流用 `CpqVersioned` |
| 模型 | `application/admin/model/cpq/ProductSeries.php` | `protected $name = 'cpq_product_series'`，时间戳 `createtime/updatetime`，状态字典经 `getStatusList()` 与 `getStatusTextAttr` 输出 |
| 校验 | `application/admin/validate/cpq/ProductSeries.php` | ThinkPHP Validate，中文错误文案，`add`/`edit` 场景；业务边界（如日期区间）用自定义校验函数 |
| 视图 | `application/admin/view/cpq/product_series/{index,add,edit,form}.html` | `add.html`/`edit.html` 仅 `{include file="cpq/product_series/form" /}`，表单集中在 `form.html` |
| 页面脚本 | `public/assets/js/backend/cpq/product_series.js` | RequireJS `define([...], function (...) { ... return Controller; })`，按 `index/add/edit` 动作导出 |
| 共享组件 | `public/assets/js/backend/cpq/common.js` | 跨页面复用的徽标、按钮、格式化函数统一放这里 |
| 菜单/权限 | `application/common/service/cpq/MenuRuleService.php` | `php think install` 安装时写入、`php think cpq:menu` 幂等补同步 `fa_auth_rule`，新增页面在此登记菜单与操作节点 |

URL、视图目录、JS 文件名三段保持同一蛇形命名（`cpq/product_series` ↔ `view/cpq/product_series/` ↔ `backend/cpq/product_series.js`）。

## 2. 列表页模式（Bootstrap Table）

```js
Table.api.init({extend: {
    index_url: 'cpq/product_series/index' + location.search,
    add_url: 'cpq/product_series/add',
    edit_url: 'cpq/product_series/edit',
    del_url: 'cpq/product_series/del',
    multi_url: 'cpq/product_series/multi',
    table: 'cpq_product_series'
}});
```

- 文本列：`{field: 'code', title: '系列编码', operate: 'LIKE'}` 即得筛选框；
- 字典列：`searchList: Config.statusList`（由控制器 `assignconfig` 下发）；
- 时间列：`formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange'`；
- 多值筛选：控制器 `$multiFields = 'status'`；
- 模糊搜索字段：控制器 `$searchFields`。

## 3. 状态徽标

统一使用 `Table.api.formatter.status` + `CpqCommon.statusCustom` 配色（`common.js`）：

```js
{field: 'status', title: '状态', searchList: Config.statusList,
 custom: CpqCommon.statusCustom, formatter: Table.api.formatter.status}
```

映射：`draft→gray`、`pending→warning`、`published→success`、`expired→danger`、`normal→success`、`hidden→gray`。布尔列用 `CpqCommon.booleanFormatter`（是/否徽标）。

## 4. 表单模式（FastAdmin Form）

- `form.html` 根节点 `<form class="form-horizontal" role="form" data-toggle="validator">`；
- 必填用 `data-rule="required"`，框架即时校验并以红色中文提示定位到字段；
- 日期用 `datetimepicker` + `data-date-format`；上传用 `faupload` 按钮；
- 提交按钮固定 `layer-footer` 内 `<button type="submit" class="btn btn-primary btn-embossed disabled">`；
- JS 侧仅一行绑定：`Form.api.bindevent($('form[role=form]'))`；
- 隐藏字段保留状态：`row[status]` 由控制器 `$cpqFormDefaults` 提供默认值。

前端校验只做即时提示，业务边界由后端 Validate/Service 重复校验。

## 5. 操作按钮与权限

- 工具栏：`{:build_toolbar('refresh,add,edit,del')}`；
- 行内编辑/删除权限：`data-operate-edit="{:$auth->check('cpq/product_series/edit')}"`，详情按钮对应 `data-operate-detail`；版本操作权限经 `data-auth-submit/publish/expire/copy` 注入并在 JS 中用 `CpqCommon.readAuth(table, [...])` 读取；
- 版本流按钮统一用 `CpqCommon.versionButtons(baseUrl, auth)`（提交/发布/停用/复制新版本，按 `row.status` 显隐）；免审批类型传第三个参数 `true`；行内编辑/删除守卫用 `CpqCommon.versionGuardButtons()`（已发布/已失效隐藏编辑，仅草稿可删）；删除统一用 `CpqCommon.operateEvents()`，删除失败时弹窗展示服务端返回的引用来源；
- 只读详情页：`detail.html` 复用 `form.html`，JS `detail` 动作先 `Form.api.bindevent()` 再 `CpqCommon.bindDetail()` 禁用全部输入；
- 按钮隐藏只是体验层，服务端必须重复校验（见方案 2.1）。

## 5.1 配置规则编辑器（P18，config_rule）

- 条件/动作用结构化编辑器（`config_rule.js`）：条件支持 all/any/整体 NOT + 叶子行（field/operator 白名单下拉/value/单条取反），动作按类型渲染受控附加字段（value、min/max、operation/operands/scale）；
- 结构化编辑与 `condition_json`/`action_json` 两个 JSON 预览双向同步，提交始终以 JSON 字段为准，保存/发布的最终合法性由后端 `RuleDsl` 白名单校验；
- 「运行测试」调 `cpq/config_rule/testrule`（只读试跑候选规则，返回命中状态/错误/警告/试算配置）；「冲突检测」调 `cpq/config_rule/analyze`（与发布门禁共用 `MasterDataLifecycleService::analyzeRuleSet`，结构化返回循环依赖/永真冲突/不可达选项）；
- 选择适用型号后，字段与目标经 `cpq/configurator/schema` 提供 datalist 候选提示。

## 6. 错误态与空态

- 列表空数据沿用 Bootstrap Table 默认中文提示；
- 业务面板空态：中文说明 + `text-muted`（配置器"请选择产品型号并加载配置结构"、模拟 BOM"暂无数据"）；
- 操作失败：`Toastr.error('中文原因')`；
- 后端校验失败：返回 `errors/warnings`，结构为 `{code, path, message, rule_code}`，前端负责定位展示（见下节）。

## 7. 配置器交互骨架（P52 复用）

`view/cpq/configurator/index.html` + `backend/cpq/configurator.js`，验证单选、多选、数量、文本、只读计算值五种控件与分组导航、错误定位。**JS 不含任何业务规则**，合法性、公式、BOM 均以后端 `ConfigurationService` 返回为准。

- 分组导航：左侧 `#cpq-group-nav` 由 schema 动态生成，点击滚动定位并闪烁目标面板；
- 后端错误定位：`issue.path` 解析为配置组编码 → 面板标 `panel-danger/panel-warning`、导航加"错误/警告"角标、错误列表项可点击跳转，首个错误自动滚动到位；
- 隐藏组：`hidden_groups` 同步隐藏面板与导航项；
- 配置摘要与 `configuration_hash` 实时展示，供与后端结果比对；
- 选择变化后防抖（600ms）自动调用服务端校验，实时给出依赖/互斥与动态显隐提示；「BOM 模拟」按钮独立调 `cpq/configurator/bom`（与 `/api/cpq/v1/configurations/bom` 同口径，配置非法时服务端拒绝生成）。

契约：后端 issue 必须携带可映射到配置组 `code` 的 `path`，前端不得自行推断规则。

## 8. 中文文案

视图标签、按钮、确认框、Toastr、Validate 消息、徽标文字一律中文；字段编码列保留英文 `code`。状态文字由模型 `getStatusList()` 单一来源下发，不在 JS 里硬编码字典。

## 9. P10–P106 复用模式清单

| 页面 | 复用模式 |
| --- | --- |
| P10 产品系列、P12 产品型号、P14 配置项、P16 配件与服务、P20 BOM 映射、P40–P45 客户与渠道列表、P100–P106 系统管理列表 | 标准列表 + 表单弹窗（§2/§4），状态列用 §3 |
| P11/P13/P15 详情页 | 表单模式 + 页内子表（Bootstrap Table 局部刷新），沿用 `form.html` include 结构 |
| P17 配置规则、P19 配置模板、P33 价格规则、P74 审批规则 | 列表 + 版本流按钮 `CpqCommon.versionButtons`（§5） |
| P18 规则编辑器 | 配置器骨架（§7）：schema 驱动渲染 + 服务端校验 + 错误定位 |
| P30–P37 价格中心 | 列表模式 + 金额只读展示（后端计算），矩阵页用 Bootstrap Table 列冻结 |
| P50–P55 六步报价向导 | 每步一个 `form.html` 模式表单，步骤条用 Bootstrap nav，提交前统一走服务端校验（§7 契约） |
| P52 配置器 | 直接复用 §7 |
| P56/P57 报价列表与详情、P70–P73 审批 | 列表模式 + 状态徽标（§3），审批动作为弹窗表单（意见+原因分类+候选人）+ 幂等键 POST JSON，禁用/失效由服务端 `can_act`/`version_stale` 驱动 |
| P02 待办 | 分类页签（待处理/已处理/我发起的/抄送我的）+ `refreshOptions` 切换列结构；SLA 用 `CpqCommon.slaFormatter`（超时红色）；批量批准默认禁用，批量转交逐条服务端复校 |
| P59 报价模板 | 结构化板块/文案编辑器 ↔ `content_json` 双向组装，变量白名单点击插入；预览 iframe 渲染服务端 HTML |
| P60 打印记录 | 异步任务列表 + 排队/生成中 5s 自动轮询；受控下载（哈希校验+计数+审计）、哈希验证、失败重试按钮按状态显隐 |
| P90–P94 报表 | 列表模式 + `operate: 'RANGE'` 时间筛选，图表后续按 FastAdmin echarts 惯例另议 |

## 10. 浏览器验证方式

本地验证环境（M0 PoC 已按此跑通）：

```bash
# 1. 依赖：worktree 需有 thinkphp/ 与 vendor/（composer install 或软链主检出）
# 2. 空库一步安装：FastAdmin + CPQ 表、菜单权限与演示数据
#    （会随机生成后台入口文件名与管理员密码，注意记录输出）
php think install -a localhost -o 13306 -d fastadmin_cpq_poc -r fa_ -u root -p root -f true --demo
# 3. 启动服务
php -S 127.0.0.1:8899 -t public public/router.php
```

浏览器验证清单：

1. 登录后台，左侧出现"CPQ产品中心"菜单组（12 个页面）；
2. 打开产品系列列表：筛选、排序、状态徽标、工具栏权限按钮正常；
3. 新增弹窗直接提交空表单：必填字段出现红色中文校验提示；
4. 打开产品配置器：选择"CPQ-DEMO-EQUIPMENT-A"加载 → 6 个配置组 + 左侧分组导航，单选/多选/数量/文本/只读控件齐全；
5. 构造非法配置（高功率不配增强散热、远程+离线同选、数量 11）→ 服务端校验 → 错误面板标红、导航角标、错误列表可点击定位；
6. DevTools Console 无任何 RequireJS/JS 报错。

自动化脚本（puppeteer-core + 本机 Chrome）见 M0 验证记录：登录、列表、表单校验、配置器合法/非法两态、控制台错误收集。服务层测试：`php tests/cpq/run.php`（单元）、`php tests/cpq/integration.php`（数据库集成）。
