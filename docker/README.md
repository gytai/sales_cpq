# CPQ Docker 开发环境

可复现的 M0 运行环境基线。版本锁定决策见 `docs/adr/0003-docker-runtime-baseline.md`，PoC 结论见 `docs/cpq/m0-poc.md`。

## 组成

| 服务 | 镜像 | 用途 |
| --- | --- | --- |
| `nginx` | nginx:1.27-alpine | Web 入口，默认 `http://localhost:8080` |
| `app` | 本仓库 `docker/Dockerfile`（php:7.4.33-fpm-bullseye） | FastAdmin / PHP-FPM |
| `worker` | 同 app 镜像 | think-queue Redis 队列消费 |
| `mysql` | mysql:8.0 | 业务数据库，宿主端口 33061 |
| `redis` | redis:7.2-alpine | 缓存 / 队列，宿主端口 63791 |

端口可用环境变量覆盖：`CPQ_HTTP_PORT`、`CPQ_MYSQL_PORT`、`CPQ_REDIS_PORT`。

## 一次启动（空库）

```bash
docker compose build
docker compose up -d
docker compose exec app bash docker/init.sh
```

`init.sh` 会：生成 `.env` → 等待 MySQL → 执行 `php think install`（输出随机后台入口与管理员密码）→ `php think cpq:install --demo` → 运行 `tests/cpq/run.php` 与 `tests/cpq/integration.php`。

重复执行是安全的：检测到 `fa_admin` 表已存在即跳过安装；`FORCE=1 docker compose exec -e FORCE=1 app bash docker/init.sh` 强制清空重装（会丢失数据）。

## 空库验证流程

```bash
docker compose down -v          # 连数据卷一起删除，回到空库
docker compose up -d
docker compose exec app bash docker/init.sh
```

通过标准：初始化脚本全部步骤成功，两个测试文件输出 PASS，后台可登录且 CPQ 菜单可见。

## 常用命令

```bash
docker compose logs -f worker                 # 队列消费日志
docker compose exec app composer test:cpq              # 纯 PHP 单元测试
docker compose exec app composer test:cpq-integration  # 数据库集成测试
docker compose exec app php tests/cpq/poc.php          # 队列 / PDF / Excel PoC
```

## 前置要求

- Docker 24+ 与 Docker Compose v2（已在 Docker 29.x / Compose v5 验证）；
- 首次构建需访问 Docker Hub、PECL 与 Packagist。
