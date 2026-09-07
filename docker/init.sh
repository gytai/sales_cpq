#!/usr/bin/env bash
# CPQ 开发环境一键初始化（在 app 容器内运行）
# 用法：docker compose exec app bash docker/init.sh
# 可重复执行：已初始化的数据库默认跳过，FORCE=1 时清库重装
set -euo pipefail

cd /var/www/html

DB_HOST="${CPQ_DB_HOST:-mysql}"
DB_PORT="${CPQ_MYSQL_PORT_INNER:-3306}"
DB_NAME="${CPQ_MYSQL_DATABASE:-fastadmin}"
DB_USER="${CPQ_MYSQL_USER:-root}"
DB_PASS="${CPQ_MYSQL_ROOT_PASSWORD:-root}"
FORCE="${FORCE:-0}"

# 先落盘应用 .env（指向容器服务 ${DB_HOST} / ${CPQ_REDIS_HOST:-redis}）；
# php think install 只改写 [database] 节，[queue]/[cpq] 等其余节保持不变
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

[cpq]
; 至少 32 字节随机值；各环境必须不同，只用于 AES-256-GCM 凭证加密
integration_key =
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

# 判断是否已初始化：php think install 一步建齐 FastAdmin 基础表 + CPQ 表，
# fa_admin 存在即视为整套已装
ALREADY=$(php -r 'try { $p = new PDO("mysql:host=".$argv[1].";port=".$argv[2].";dbname=".$argv[3], $argv[4], $argv[5]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->query("SELECT 1 FROM ".$argv[6]."admin LIMIT 1"); echo "1"; } catch (Exception $e) { echo "0"; }' \
    "${DB_HOST}" "${DB_PORT}" "${DB_NAME}" "${DB_USER}" "${DB_PASS}" 'fa_')

if [ "${ALREADY}" = "1" ] && [ "${FORCE}" != "1" ]; then
    echo "==> 数据库已初始化（FORCE=1 可强制清库重装，会删除全部数据）"
else
    # 合并基线为裸 CREATE TABLE（非幂等），FORCE 重装前必须先清库
    if [ "${ALREADY}" = "1" ]; then
        echo "==> FORCE=1：清空数据库重建"
        php -r '$p = new PDO("mysql:host=".$argv[1].";port=".$argv[2], $argv[3], $argv[4]); $p->exec("DROP DATABASE IF EXISTS ".$argv[5]);' \
            "${DB_HOST}" "${DB_PORT}" "${DB_USER}" "${DB_PASS}" "${DB_NAME}"
    fi
    echo "==> 生成应用 .env（指向容器服务 ${DB_HOST} / ${CPQ_REDIS_HOST:-redis}）"
    write_env
    echo "==> 一步安装 FastAdmin + CPQ 表与演示数据（随机后台密码见输出）"
    php think install \
        --hostname "${DB_HOST}" \
        --hostport "${DB_PORT}" \
        --database "${DB_NAME}" \
        --username "${DB_USER}" \
        --password "${DB_PASS}" \
        --prefix fa_ \
        --force=true \
        --demo
fi

echo "==> 运行 CPQ 测试"
php tests/cpq/run.php
php tests/cpq/integration.php

echo ""
echo "初始化完成。后台入口：http://localhost:${CPQ_HTTP_PORT:-8080}/ 后台随机文件名见上方安装输出；"
echo "或 docker compose exec app ls public/ 查看入口文件名。"
