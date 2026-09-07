# Sales CPQ

Sales CPQ 是一个基于 FastAdmin 二次开发的制造业通用 CPQ（Configure、Price、Quote）系统，用于管理可配置产品、价格策略、销售报价和报价审批。
项目不绑定某一种设备或特定企业。产品系列、型号、技术参数、配置组、选项、配件、服务和 BOM 均通过主数据与规则灵活定义，可适配包括美亚光电在内的装备制造企业。

## 核心能力

### 产品配置

- 维护产品系列、型号、可扩展技术参数、配件与服务；
- 支持单选、多选、数量、数值、文本和只读计算值等配置类型；
- 使用受控规则 DSL 定义必选、依赖、互斥、数量、可见性、默认值和计算规则；
- 校验配置合法性，生成稳定的配置快照、配置指纹和 BOM；
- 通过配置模板复用标准场景、市场或客户等级的推荐配置。

### 定价

- 按公司、业务板块、市场、区域、产品线、客户或代理等级等维度维护价格表；
- 计算型号基础价、选项增量价、配件服务价、费用、税额和汇率；
- 支持固定价、加减金额、折扣率和系数等价格规则；
- 校验指导价、产品线控制价和公司控制价；
- 保存完整价格执行轨迹，保证相同输入和相同规则版本得到相同结果。

### 报价与审批

- 通过报价向导完成客户选择、产品选择、产品配置、价格折扣、商务条款和提交；
- 保存报价版本及完整快照，已提交或已批准版本不可覆盖修改；
- 根据价格等级和条款偏差自动确定报价审批路径；
- 支持待办、批准、驳回、退回、撤回、加签、转交和审批留痕；
- 生成中英文、多币种报价 PDF，并保留正式文件版本和哈希。

### 主数据与治理

- 管理客户、代理商、销售区域、销售组织和数据权限；
- 基于 FastAdmin RBAC 控制菜单、操作和业务数据范围；
- 对成本、控制价、导出、审批、打印和接口调用进行审计；
- 预留 CRM、ERP 基础接口，支持 Excel 导入导出和异步任务。

## MVP 范围

本次 MVP 包括：

- 产品主数据、通用配置模型、配置规则和 BOM 映射；
- 价格表、价格规则、价格试算和三级价格控制；
- 客户与渠道主数据；
- 报价草稿、版本、审批、PDF 和审计；
- CRM/ERP 基础接口预留及手工导入导出。

本次 MVP 不包括：

- 合同、合同预审、合同条款库和合同系统集成；
- CAD/三维可视化、工艺路线、库存预占、采购与生产计划；
- 经销商门户、移动端独立应用和 CRM/ERP 双向实时深度集成；
- 由 AI 自动决定价格或绕过确定性规则执行审批。

## 核心业务流程

```text
客户与销售上下文
        ↓
选择产品与配置模板
        ↓
配置规则校验 → 生成配置快照与BOM
        ↓
价格规则计算 → 生成价格轨迹与控制价结果
        ↓
创建报价版本 → 提交报价审批
        ↓
批准 → 生成正式报价PDF → 发送并记录客户反馈
```

前端规则仅用于交互提示。保存、试算和提交时，后端必须使用同一规则版本重新校验配置、价格、权限和状态。

## 技术基线

| 类别 | 当前技术 |
| --- | --- |
| 后端框架 | FastAdmin `1.6.5.20260602`、ThinkPHP `5.0.28` |
| PHP | `>= 7.4`，以 `composer.json` 为准 |
| 后台前端 | Bootstrap 3、jQuery、RequireJS、Bootstrap Table、FastAdmin Form |
| 数据库 | MySQL，默认表前缀 `fa_`；CPQ 物理表使用 `fa_cpq_*` |
| 缓存与任务 | ThinkPHP Cache、ThinkPHP Queue；MVP 部署时接入 Redis |
| 文件与报表 | FastAdmin 附件体系、PhpSpreadsheet；PDF 组件在 M0 选型验证 |
| 构建工具 | npm、Grunt |

MVP 沿用当前 FastAdmin 后台技术栈，不新增 Vue、React、独立 SPA 或另一套后端框架。

## 目录说明

```text
application/
├── admin/                  # FastAdmin后台控制器、模型、验证器、视图和语言包
├── api/                    # API控制器
└── common/                 # 公共控制器、模型、服务和业务基础能力
addons/                     # FastAdmin插件目录
public/
├── assets/js/backend/      # RequireJS后台页面模块
├── assets/css/             # 后台样式
└── index.php               # Web入口
docs/
└── FastAdmin-CPQ开发功能与实施方案.md
thinkphp/                   # ThinkPHP框架代码，不承载CPQ业务修改
```

CPQ 开发时使用以下扩展位置：

- `application/admin/controller/cpq/`
- `application/admin/model/cpq/`
- `application/admin/validate/cpq/`
- `application/admin/view/cpq/`
- `application/admin/lang/zh-cn/cpq/`
- `application/api/controller/cpq/`
- `application/common/service/cpq/`
- `application/common/library/cpq/`
- `public/assets/js/backend/cpq/`
- `database/cpq/`
- `tests/cpq/`

## 本地环境

### 环境要求

- PHP `>= 7.4`；
- MySQL 5.7+ 或兼容版本；
- Composer；
- Node.js 与 npm，仅在安装或重新构建前端资源时需要；
- Nginx/Apache 与 PHP-FPM，站点根目录必须指向 `public/`；
- PHP 扩展：JSON、cURL、PDO、BCMath，以及依赖组件要求的其他扩展。

Redis、队列 Worker 和 PDF 组件在对应 CPQ 功能进入开发或部署时启用。

### 安装依赖

```bash
composer install
npm ci
npm run build
```

### 配置与安装

1. 以 `.env.sample` 创建本地 `.env`，配置数据库连接；不要提交 `.env` 或真实凭证。
2. 创建空数据库，将 Web 根目录配置为仓库的 `public/`。
3. 使用 FastAdmin 安装页面完成首次安装，或先运行 `php think help install` 查看命令行安装参数。
4. 安装完成后记录随机后台入口，立即设置强密码并限制管理端访问。
5. CPQ 数据库脚本加入后，按 `database/cpq/` 中的版本顺序执行安装或升级。

不要在已有业务数据库上重复执行 FastAdmin 安装命令，也不要直接修改 `application/admin/command/Install/fastadmin.sql`。

### 常用命令

```bash
# 查看ThinkPHP/FastAdmin命令
php think

# 重新构建后台和前台静态资源
npm run build
```

## 开发约定

- 不直接修改 FastAdmin 或 ThinkPHP 核心代码；
- 后台 Controller 继承 `app\common\controller\Backend`，API Controller 继承 `app\common\controller\Api`；
- 单表 CRUD 优先复用 FastAdmin 能力，跨表配置、定价、审批和版本逻辑放入 Service；
- 后台列表使用 Bootstrap Table，表单使用 FastAdmin Form，页面脚本使用 RequireJS；
- 金额使用 Decimal/BCMath，不使用浮点数进行价格计算；
- 配置规则和价格规则使用受控 JSON DSL，禁止执行 `eval` 或用户输入代码；
- 前端校验不能替代服务端权限、数据范围、状态机和价格底线校验；
- 已发布规则、价格版本和正式报价快照不可直接修改；
- 新增或修改的源代码注释默认使用中文；
- 每个功能应同步提交数据库升级脚本、测试、API 说明和页面文档。

## 实施阶段

| 阶段 | 目标 |
| --- | --- |
| M0 | 完成运行时兼容性、依赖、数据库脚本、测试和 CI 基线验证 |
| M1 | 完成产品主数据、配置规则、FastAdmin 原生配置器和 BOM 模拟 |
| M2 | 完成价格引擎、客户渠道、报价向导和报价版本 |
| M3 | 完成报价审批、待办、PDF 和审计日志 |
| M4 | 完成安全测试、业务验收、部署与试运行 |

## 文档

- [CPQ 开发功能与实施方案](docs/FastAdmin-CPQ开发功能与实施方案.md)
- [FastAdmin 官方文档](https://doc.fastadmin.net)
- [FastAdmin 官方仓库](https://github.com/fastadminnet/fastadmin)

## 许可证

项目许可证及第三方组件版权信息以 [LICENSE](LICENSE) 和各依赖包声明为准。FastAdmin 原始代码遵循 Apache-2.0 许可证。
