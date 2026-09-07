# CPQ Docker 开发环境

方案 §10 的开发态实现：nginx + app(PHP-FPM) + worker(队列) + mysql + redis 五个容器。运行时版本基线见 `docs/adr/0003-docker-runtime-baseline.md`。

## 前置条件

- Docker Engine 20.10+ 与 Docker Compose v2（`docker compose version` 可查到即可）
- 端口 `8080`（HTTP）、`33061`（MySQL）、`63791`（Redis）未被占用，可用环境变量覆盖

## 首次启动

```bash
# 1. 准备 compose 变量文件（各变量均有默认值，此步可跳过；
#    但仓库根目录的 .env 是 FastAdmin 的 INI 格式，会被 compose 误读，
#    所以显式指定 --env-file 是推荐姿势）
cp docker/compose.env.example .env.docker   # .env.docker 已 gitignore，可放本机密码

# 2. 构建并启动
docker compose --env-file .env.docker up -d --build

# 3. 一键初始化：写应用 .env → 等待 MySQL → php think install → cpq:install --demo → 跑测试
docker compose --env-file .env.docker exec app bash docker/init.sh

# 4. 访问
#    前台 http://localhost:8080/
#    后台入口为安装时输出的随机文件名，可用以下命令查看：
docker compose --env-file .env.docker exec app ls public/
```

`docker/init.sh` 幂等：数据库已初始化时跳过安装，只跑测试；`FORCE=1 docker compose exec app -e FORCE=1 app bash docker/init.sh` 可强制清空重建。

## 常用命令

```bash
docker compose --env-file .env.docker ps            # 状态（mysql 需 healthy）
docker compose --env-file .env.docker logs -f app   # 应用日志
docker compose --env-file .env.docker logs -f worker # 队列 worker 日志
docker compose --env-file .env.docker exec app composer test:cpq              # 单元测试
docker compose --env-file .env.docker exec app composer test:cpq-integration  # 数据库集成测试
docker compose --env-file .env.docker down          # 停止（数据卷保留）
docker compose --env-file .env.docker down -v       # 停止并清空数据卷（空库重来）
```

## 空库验证流程

`database/cpq/` 的安装/升级约定见 `docs/cpq/data-model.md` 第 3 节。验证空库可装：

```bash
docker compose --env-file .env.docker down -v && docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker exec app bash docker/init.sh   # 应完整走一遍安装+测试
```

升级 SQL 验证：在已初始化环境执行 `docker compose exec app php think cpq:install`，应幂等通过、不重复造数据。

## 目录说明

| 路径 | 作用 |
| --- | --- |
| `docker/Dockerfile` | PHP 7.4.33-fpm 镜像，编译 pdo_mysql/bcmath/zip/gd/opcache 与 pecl redis-5.3.7 |
| `docker/php.ini` | 容器与本地共用的 PHP 基线配置 |
| `docker/nginx/fastadmin.conf` | 站点配置：PHP-FPM 反代、uploads/assets 禁 PHP、隐藏文件拒绝 |
| `docker/init.sh` | 容器内一键初始化（应用 .env、FastAdmin 安装、CPQ 表与演示数据、测试） |
| `docker/compose.env.example` | compose 变量模板（端口、数据库名、root 密码），复制为 `.env.docker` 使用 |

## 故障排查

- **端口被占用**：本机 8080/8081 常被其他服务占用，改 `.env.docker` 里的 `CPQ_HTTP_PORT`（如 8082）后 `up -d`。
- **容器里 vendor 与宿主不一致（部分 Docker Desktop）**：某些 Docker Desktop 文件共享实现下，compose 里保护 `vendor/`、`thinkphp/`、`runtime/` 的匿名卷不会真正挂载，容器直接看到宿主目录。此时需保证宿主依赖完整——若宿主 `composer install` 因网络装不上 mpdf，可从镜像同步：
  ```bash
  docker create --name cpq-vendor-extract sales_cpq-app
  docker cp cpq-vendor-extract:/var/www/html/vendor ./runtime/temp/vendor-docker
  docker rm cpq-vendor-extract
  rsync -a --delete runtime/temp/vendor-docker/ vendor/ && rm -rf runtime/temp/vendor-docker
  ```
- **本机跑 PDF PoC 报「没有可用中文字体」**：macOS 自带 TTC 字体 mpdf 无法解析，从镜像提取文泉驿字体到本机临时目录即可（不入库）：
  ```bash
  mkdir -p runtime/temp/fonts
  docker cp sales_cpq-app-1:/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc runtime/temp/fonts/
  ```
