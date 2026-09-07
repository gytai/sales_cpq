#!/usr/bin/env bash
# CPQ 开发环境一键初始化（在 app 容器内运行）
# 用法：docker compose exec app bash docker/init.sh
# 可重复执行：已初始化的数据库默认跳过，FORCE=1 时强制重装
set -euo pipefail

cd /var/www/html

DB_HOST="${CPQ_DB_HOST:-mysql}"
DB_PORT="${CPQ_MYSQL_PORT_INNER:-3306}"
DB_NAME="${CPQ_MYSQL_DATABASE:-fastadmin}"
DB_USER="${CPQ_MYSQL_USER:-root}"
DB_PASS="${CPQ_MYSQL_ROOT_PASSWORD:-root}"
FORCE="${FORCE:-0}"

# 生成应用 .env（指向容器服务 ${DB_HOST} / ${CPQ_REDIS_HOST:-redis}）
# 注意：必须在 php think install 之后再写——install 命令会全局正则替换 .env 中
# hostname/hostport/password 等行，会把 [queue] 段冲掉
write_env() {
    cat > .env <<EOF
[app]
debug = true
trace = false

[database]
hostname = ${DB_HOST}
database = ${DB_NAME}
username = ${DB_USER}
password = ${DB_PASS}
hostport = ${DB_PORT}
prefix = fa_

[queue]
connector = Redis
hostname = ${CPQ_REDIS_HOST:-redis}
hostport = 6379
password =
select = 0
EOF
}

# 等待 MySQL 可连接（最长 60 秒）
echo "==> 等待 MySQL 就绪"
for i in $(seq 1 30); do
    if php -r 'try { new PDO("mysql:host=".$argv[1].";port=".$argv[2], $argv[3], $argv[4]); echo "ok"; } catch (Exception $e) { exit(1); }' \
        "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}" 2>/dev/null | grep -q ok; then
        break
    fi
    [ "$i" -eq 30 ] && { echo "MySQL 连接超时"; exit 1; }
    sleep 2
done

# 判断是否已初始化：FastAdmin（fa_admin）与 CPQ（fa_cpq_product_model）分开判定，
# 前者已装但 CPQ 表缺失时仍补齐 CPQ（cpq:install 幂等）
ALREADY=$(php -r 'try { $p = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3], $argv[4], $argv[5]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->query("SELECT 1 FROM ".$argv[6]."admin LIMIT 1"); echo "1"; } catch (Exception $e) { echo "0"; }' \
    "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" 'fa_')
CPQ_READY=$(php -r 'try { $p = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3], $argv[4], $argv[5]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->query("SELECT 1 FROM ".$argv[6]."cpq_product_model LIMIT 1"); echo "1"; } catch (Exception $e) { echo "0"; }' \
    "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" 'fa_')

if [ "${ALREADY}" = "1" ] && [ "${FORCE}" != "1" ]; then
    echo "==> FastAdmin 已初始化（FORCE=1 可强制重装，会清空重建）"
else
    echo "==> 执行 FastAdmin 安装（随机后台密码见输出）"
    php think install \
        --hostname "${DB_HOST}" \
        --hostport "${DB_PORT}" \
        --database "${DB_NAME}" \
        --username "${DB_USER}" \
        --password "${DB_PASS}" \
        --prefix fa_ \
        --force=true
fi

# install 会重写 .env 并冲掉 [queue] 段，这里统一在之后落盘最终版
echo "==> 生成应用 .env（指向容器服务 ${DB_HOST} / ${CPQ_REDIS_HOST:-redis}）"
write_env

if [ "${CPQ_READY}" = "1" ] && [ "${FORCE}" != "1" ]; then
    echo "==> CPQ 表已存在，跳过安装（升级走 database/cpq/upgrades/）"
else
    echo "==> 安装 CPQ 表与演示数据"
    php think cpq:install --demo
fi

echo "==> 运行 CPQ 测试"
php tests/cpq/run.php
php tests/cpq/integration.php

echo ""
echo "初始化完成。后台入口：http://localhost:${CPQ_HTTP_PORT:-8080}/ 后台随机文件名见上方安装输出；"
echo "或 docker compose exec app ls public/ 查看入口文件名。"
