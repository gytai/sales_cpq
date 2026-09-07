# CPQ 部署与运维手册（M4 收口，GYTAI-76）

> 适用范围：CPQ 报价系统（FastAdmin/ThinkPHP5 + MySQL + Redis + think-queue）。
> 开发态日常操作见 `docker/README.md`；本文档覆盖测试/生产环境的部署、迁移、备份恢复、密钥、发布与回滚。
> 全程不使用真实客户/价格数据；演示数据仅限 `database/cpq/demo.sql`（`CPQ-DEMO-*` 前缀，已脱敏）。

## 1. 环境拓扑

| 环境 | 形态 | 说明 |
| --- | --- | --- |
| 开发 | `docker compose --env-file .env.docker up -d --build`（nginx + app + worker + mysql + redis） | 见 `docker/README.md` |
| 测试 | 与开发同一套 compose 文件，独立 `.env`（独立库名、独立密钥、独立端口） | 演示数据通过 `php think cpq:install --demo` 注入 |
| 生产 | 同一 `docker/Dockerfile` 构建的镜像；MySQL/Redis 可用托管实例 | 不开 `--demo`；后台入口随机文件名仅内部知悉 |

运行时版本基线（PHP 7.4.33 / MySQL / Redis / 扩展清单）见 `docs/adr/0003-docker-runtime-baseline.md`，升级运行时必须新建 ADR，不允许随业务任务顺带升级。

## 2. 首次部署（空库）

```bash
# 1. 准备密钥与配置（见第 5 节）
cp docker/compose.env.example .env.docker   # 编辑：端口、库名、root 密码

# 2. 构建启动
docker compose --env-file .env.docker up -d --build

# 3. 初始化（写应用 .env → 等待 MySQL → FastAdmin 安装 → CPQ 建表）
docker compose --env-file .env.docker exec app bash docker/init.sh
# 测试环境需要演示数据时：
docker compose --env-file .env.docker exec app php think cpq:install --demo
```

`cpq:install` 幂等：已初始化环境重复执行不重复造数（验证见 `tests/cpq/upgrade.php` 场景 A/C）。

## 3. 数据库迁移（已有库升级）

增量脚本位于 `database/cpq/upgrades/`，按文件名版本号序执行，由 `php think cpq:upgrade` 应用：

```bash
# 0. 升级前必须备份（见第 4 节）
# 1. 查看待执行脚本（只读）
docker compose --env-file .env.docker exec app php think cpq:upgrade --list
# 2. 应用
docker compose --env-file .env.docker exec app php think cpq:upgrade
# 3. 验证：--list 应无输出；复跑应幂等空操作
```

升级语义已在 `tests/cpq/upgrade.php`（65 断言）验证：空库安装、已有库逐版本升级、复跑幂等。新结构变更必须新增带递增版本号的升级脚本，禁止改已发布的脚本。

## 4. 备份与恢复

### 备份（建议每日 + 发布前）

```bash
# 数据库（容器内执行，备份文件落在宿主机）
docker compose --env-file .env.docker exec mysql \
  mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines <库名> \
  > backup/cpq-$(date +%Y%m%d-%H%M%S).sql

# 上传文件与生成文档（public/uploads 含报价 PDF）
tar czf backup/uploads-$(date +%Y%m%d-%H%M%S).tgz public/uploads
```

`backup/` 目录不入库（已 gitignore 管理之外请自行确保）；生产备份应另存对象存储并加密。

### 恢复

```bash
# 1. 停应用与 worker，保留 mysql
docker compose --env-file .env.docker stop app worker nginx

# 2. 恢复数据库
docker compose --env-file .env.docker exec -T mysql \
  mysql -u root -p"$MYSQL_ROOT_PASSWORD" <库名> < backup/cpq-<时间戳>.sql

# 3. 恢复上传文件
tar xzf backup/uploads-<时间戳>.tgz

# 4. 起服务并验证
docker compose --env-file .env.docker up -d
docker compose --env-file .env.docker exec app composer test:cpq-regression
```

恢复演练口径：空库恢复 = 第 2 节全新部署 + 导入备份；已有库恢复 = 直接导入备份（先 drop 同名库）。演练记录进运维台账。

## 5. 密钥与敏感配置

| 密钥 | 位置 | 要求 |
| --- | --- | --- |
| MySQL root / 业务库密码 | `.env.docker`（gitignore） | 每环境独立，禁止入库 |
| FastAdmin `app_debug` | 应用 `.env` | 生产必须 `false` |
| `cpq.integration_key` | 应用 `.env` `[cpq]` 段 | 64 位随机串；集成凭证 AES-256-GCM 加解密根密钥，**轮换即旧密文不可解密**，轮换前必须先导出/重录凭证 |
| 后台入口文件名 | `php think install` 随机生成 | 仅内部知悉，泄露后重命名并排查日志 |
| 集成系统凭证（HMAC secret / OAuth2 token） | `fa_cpq_integration_config` 密文列 | 永不落日志/审计明文（`AuditLogService` 自动脱敏 + 凭证保险箱，security.php C8/C9 验证） |

轮换流程：生成新密钥 → 更新 `.env` → 重启 app/worker → 重录集成凭证（integration_key 轮换时）→ 验证集成连通。

## 6. 发布流程

```bash
# 1. 发布前检查
git status                            # 确认无意外改动
composer test:cpq-regression          # 19 套件全绿（容器内执行）
php think cpq:upgrade --list          # 确认本次发布包含的迁移脚本

# 2. 备份（第 4 节）

# 3. 部署代码 + 重建镜像
docker compose --env-file .env.docker up -d --build

# 4. 执行迁移（第 3 节）

# 5. 验证
docker compose --env-file .env.docker exec app composer test:cpq
docker compose --env-file .env.docker exec app composer test:cpq-integration
# 队列 worker 存活：docker compose ps（worker Up）；日志无 ERROR

# 6. 记录发布版本：发布单号、git 提交、迁移脚本清单、备份文件路径
```

## 7. 回滚流程

| 场景 | 操作 |
| --- | --- |
| 代码问题、无结构迁移 | 切回上一版本代码 → `up -d --build` → 回归冒烟 |
| 含结构迁移且新结构兼容旧代码（本项目的强制约定） | 同上，数据库不回滚 |
| 必须回滚数据库 | 停 app/worker → 导入发布前备份（第 4 节恢复流程）→ 切回旧代码 → 起服务验证 |

约定：升级脚本只允许**后向兼容**的变更（新增表/列/索引、扩枚举；不改列类型、不删列、不改存量语义），使"回滚代码不回滚库"成为默认路径。破坏性变更必须拆成两次发布（先兼容双写，后清理）。

## 8. 队列与计划任务

- 队列 worker：`worker` 容器常驻 `php think queue:work`（think-queue + Redis 驱动）；发布重启后确认 `docker compose ps` 中 worker 为 Up，日志无异常。
- 计划任务：`php think cpq:schedule`（`application/admin/command/CpqSchedule.php`）由容器/宿主机 cron 每分钟触发，负责价格发布/失效调度与异步作业扫描。
- 异步导出/PDF 作业失败可在后台「系统管理 → 异步作业」重试；错误明细落 `runtime/cpq_job_errors/`（该目录不入库）。

## 9. 监控与日志

- 应用日志：`runtime/log/`（按日期分文件）；审计日志：`fa_cpq_audit_log`（只增不删，含 trace_id）。
- 安全相关告警点：`hash_mismatch`（PDF 篡改）、`download`/`view_sensitive`/`export` 频次异常、集成事件连续失败（`fa_cpq_integration_event`）。
- 性能基线（performance.php 实测，见 `docs/cpq/m4-security-performance-verification.md`）：配置校验 <500ms、100 行试算 <2s、PDF <30s、列表 <2s，明显劣化时先查 MySQL 慢查询与队列积压。

## 10. 已知残余风险（M4 收口结论）

详见 `docs/cpq/m4-security-performance-verification.md` 第 5 节：CSRF 依赖登录态 + 随机后台入口（框架无全局 CSRF 中间件）；主数据派生文本在部分列表模板未管道转义（自由文本字段已转义，主数据仅管理员可维护）；`api/cpq/v1/quotes*` 文档注释与 route.php 注册清单的表述偏差。
