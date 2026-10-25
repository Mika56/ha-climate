# syntax=docker/dockerfile:1
#
# HA Climate add-on image.
#
# BUILD CONTEXT = REPOSITORY ROOT (the image needs both app/ and frontend/), for example:
#   docker build -f ha_climate/Dockerfile \
#     --build-arg BUILD_FROM=ghcr.io/home-assistant/amd64-base:3.24-2026.10.1 -t ha-climate:dev .
# The Supervisor's own local build uses the add-on folder as context and therefore cannot build this file:
# published releases ship a prebuilt image (`image:` key in config.yaml, added by the release pipeline).

ARG BUILD_FROM=ghcr.io/home-assistant/amd64-base:3.24-2026.10.1

# --- (a) front end, built natively on the builder's platform (output is architecture independent) ---------
FROM --platform=$BUILDPLATFORM node:22.12.0-alpine AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

# --- (b) PHP dependencies (needs the extensions Composer checks at install time) ---------------------------
FROM php:8.4-cli-alpine AS vendor
COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
# hadolint ignore=DL3018
RUN apk add --no-cache unzip && install-php-extensions intl pcntl
WORKDIR /build
COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --prefer-dist
COPY app/bin ./bin
COPY app/config ./config
COPY app/migrations ./migrations
COPY app/public ./public
COPY app/src ./src
COPY app/translations ./translations
COPY app/.env ./.env
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts \
    && rm -rf /build/var

# --- (c) runtime --------------------------------------------------------------------------------------------
# hadolint ignore=DL3006
FROM ${BUILD_FROM}

ARG BUILD_VERSION=0.1.0
ARG BUILD_ARCH=amd64

# PHP 8.4 comes from Alpine's php84 packages; the list matches `composer check-platform-reqs`.
# hadolint ignore=DL3018
RUN apk add --no-cache \
        nginx \
        php84 php84-fpm php84-opcache \
        php84-ctype php84-dom php84-iconv php84-intl php84-mbstring php84-openssl php84-pcntl php84-pdo \
        php84-pdo_sqlite php84-phar php84-posix php84-session php84-simplexml php84-sqlite3 \
        php84-tokenizer php84-xml php84-xmlreader php84-xmlwriter \
    && ln -s /usr/bin/php84 /usr/bin/php \
    && rm -f /etc/nginx/http.d/default.conf /etc/php84/php-fpm.d/www.conf \
    && adduser -D -H -u 1000 -s /sbin/nologin app

COPY ha_climate/rootfs/ /
COPY --from=frontend /build/dist /opt/ha-climate/frontend
COPY --from=vendor /build /opt/ha-climate/app

# Only file paths and public settings: the secrets are generated at start-up by init-app (DL3064 is a false positive).
# hadolint ignore=DL3064
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    DATABASE_URL="sqlite:////data/app.db" \
    ENGINE_TOKEN_FILE=/data/engine.token \
    ENGINE_PORT=8100 \
    SYMFONY_TRUSTED_PROXIES=172.30.32.2 \
    APP_SHARE_DIR=/opt/ha-climate/app/var/share

# Executable s6 scripts, writable runtime dirs, and a prod cache warmed at build time (init-app refreshes it).
WORKDIR /opt/ha-climate/app
RUN find /etc/s6-overlay/s6-rc.d \( -name run -o -name finish \) -exec chmod 755 {} + \
    && mkdir -p var/cache var/share /run/php \
    && APP_SECRET=build-time-only php bin/console cache:warmup --env=prod --no-debug \
    && chown -R app:app var

LABEL io.hass.name="HA Climate" \
      io.hass.description="Room-by-room heating and air-conditioning scheduler" \
      io.hass.type="addon" \
      io.hass.arch="${BUILD_ARCH}" \
      io.hass.version="${BUILD_VERSION}" \
      org.opencontainers.image.title="HA Climate" \
      org.opencontainers.image.description="Home Assistant add-on driving heating and air conditioning" \
      org.opencontainers.image.source="https://github.com/OWNER/ha-climate" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${BUILD_VERSION}"

EXPOSE 8099
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:8099/api/health"]
