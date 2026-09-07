# M0 技术 PoC 结论

对应方案 §9.2 / §13（M0 技术验证与项目基线），验收清单见 `docs/cpq/acceptance-tests.md` 第 6 节。本文件只记录真实跑过的验证与结论，未验证项明确标注。

## 1. 锁定的兼容组合

| 组件 | 锁定版本 | 验证方式 | 结论 |
| --- | --- | --- | --- |
| FastAdmin | 1.6.5.20260602（`application/config.php`） | 空库安装 + 后台入口 302 跳登录 + API 鉴权 401 | 可用 |
| ThinkPHP | 5.0.28（`thinkphp/base.php`） | 同上 | 可用 |
| PHP | 7.4.33（本机 CLI 与 `php:7.4.33-fpm-bullseye` 容器一致） | 全量测试与 PoC 均在 7.4.33 执行 | 可用 |
| Composer | 2.2 LTS | `composer validate` + `composer install --dry-run` 零差异 | 可用 |
| MySQL | 8.0（`mysql_native_password` + `utf8mb4/utf8mb4_general_ci`） | 空库 `php think install` + `cpq:install --demo` 成功 | 可用 |
| Redis | 7.2（alpine） + phpredis 5.3.7 | 队列 PoC 真实推送/消费 | 可用 |
| 队列 | topthink/think-queue 1.1.6（Redis 驱动，配置环境变量化） | `tests/cpq/poc.php` 队列段 | 可用 |
| PDF | mpdf/mpdf 8.2.7 + 文泉驿正黑（容器 `fonts-wqy-zenhei`） | `tests/cpq/poc.php` PDF 段，校验中文字体真实嵌入 | 可用 |
| Excel | phpoffice/phpspreadsheet 1.30.1 | `tests/cpq/poc.php` Excel 段中文写读回环 | 可用 |

依赖锁定：`composer.lock` 入库（ADR 0002），相比 M0 前仅新增 mpdf 及其 5 个依赖（`mpdf/mpdf 8.2.7`、`mpdf/psr-*`、`setasign/fpdi 2.6.4`、`myclabs/deep-copy 1.13.3`、`paragonie/random_compat`），其余包零变动。

## 2. 可复现环境验证记录（2026-09-03，Docker 29.3.1 / Compose v5.1.1）

```bash
cp docker/compose.env.example .env.docker
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker exec app bash docker/init.sh
```

空库（`down -v` 后）一次启动结果：

- FastAdmin 安装成功，输出随机后台入口与管理员密码；
- `php think cpq:install --demo` 建表与演示数据成功，复跑幂等通过；
- `php tests/cpq/run.php` → `ConfigurationService tests: PASS`；
- `php tests/cpq/integration.php` → `CPQ database integration tests: PASS`；
- 前台 `GET /` → 200；后台入口 → 302 跳登录（登录/RBAC 链路通）；`GET /api/cpq/v1/product-models` 未登录 → 401（API 鉴权生效）；
- 队列 PoC：推送 `CpqSmokeJob` 后经 `queue:work` 消费成功（标记文件 token 校验一致）；
- PDF PoC：生成合法 PDF 且嵌入 WenQuanYi 中文字体；Excel PoC：中文单元格写读一致。

## 3. 本机直跑（非容器）结论

- `php tests/cpq/run.php`（纯单元）本机 PHP 7.4.33 直接通过；
- PDF/Excel 本机可跑：vendor 从镜像还原后 `php tests/cpq/poc.php --skip-queue` PASS；本机队列段需 ext-redis，留容器验证；
- 已知限制：macOS 自带 TTC 中文字体（STHeiti/冬青黑体）mpdf 无法解析，本机 PDF 验证需从镜像提取 `wqy-zenhei.ttc` 到 `runtime/temp/fonts/`（命令见 `docker/README.md` 故障排查）。

## 4. 踩坑与处置（均已修复/记录）

1. **mpdf 分发下载**：阿里云 composer 镜像无 mpdf dist（404），GitHub codeload 在本机网络超时——处置为镜像内完成 `composer install`（容器网络可达），本机 vendor 与镜像对齐；锁文件一致性与 PoC 不受影响。
2. **Docker Desktop 嵌套卷失效**：本机 Docker Desktop（fakeowner/virtiofs）对 host bind 挂载点下的匿名卷（vendor/thinkphp/runtime）不生效，容器直接看到宿主 `vendor/`。compose 保留匿名卷声明（Linux/CI 上有效），本机开发要求先完成依赖安装（`composer install` 或从镜像同步 vendor）。
3. **init.sh 探测假阳性**：PDO 默认 ERRMODE_SILENT 导致「已初始化」探测恒真，已改为显式 `ERRMODE_EXCEPTION`，并将 FastAdmin 与 CPQ 表的探测拆开。
4. **`php think install` 冲掉 `.env` 队列段**：install 命令全局正则替换 `hostname/hostport/password` 等行，会误伤 `[queue]` 段；init.sh 改为在 install 之后落盘最终 `.env`，CI 同样在安装后修正该段。
5. **nginx pathinfo 未传**：后台随机入口 `/adminXXX.php/cpq/...` 被当成模块名导致 500，站点配置已补 `fastcgi_split_path_info` + `PATH_INFO`。
6. **端口冲突**：本机 8080/8081 被占用，HTTP 端口经 `CPQ_HTTP_PORT` 覆盖（当前用 8082）。

## 5. 明确不做/遗留

- 未升级任何框架/依赖主版本（ADR 0001）；
- mpdf 字体选型为 PoC 级（文泉驿正黑），正式报价单模板字体在 M3 打印模板任务中定；
- CI（`.github/workflows/cpq-ci.yml`）按本文件已验证命令编写，首次推送后以实际运行结果为准。
