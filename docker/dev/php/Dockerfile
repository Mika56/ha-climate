FROM php:8.4-cli-alpine

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache git unzip bash \
    && install-php-extensions intl pdo_sqlite pcov opcache zip pcntl
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN { echo 'memory_limit=-1'; echo 'opcache.enable_cli=0'; } > /usr/local/etc/php/conf.d/zz-dev.ini
ENV COMPOSER_HOME=/workspace/.cache/composer \
    COMPOSER_CACHE_DIR=/workspace/.cache/composer/cache
WORKDIR /workspace/app
