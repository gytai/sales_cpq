# CPQ 开发环境 PHP 镜像
# 基线：FastAdmin 1.6.5.20260602 / ThinkPHP 5.0.28 / PHP 7.4.33
# 说明：tag 精确锁定，禁止顺手升级（见 docs/adr/0003-docker-runtime-baseline.md）
FROM php:7.4.33-fpm-bullseye

# 系统依赖 + PHP 扩展编译所需头文件
# - pdo_mysql/bcmath/zip/gd：FastAdmin 与 PhpSpreadsheet、mpdf 所需
# - redis(pecl 5.3.7)：think-queue 1.1.6 Redis 驱动要求 ext-redis
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        unzip \
        git \
        fonts-wqy-zenhei \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        zip \
        gd \
        opcache \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis

# Composer 固定 2.2 LTS（PHP 7.4 支持的最后主线）
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 依赖层单独缓存：composer 文件不变时不重复安装
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist \
    && rm -rf /root/.composer/cache

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-cpq.ini
