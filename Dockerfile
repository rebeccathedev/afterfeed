# syntax=docker/dockerfile:1.7

FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

FROM composer:2 AS php-dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM php:8.4-fpm-bookworm AS runtime

ARG AFTERFEED_VERSION=dev
ARG GITHUB_REPOSITORY=rebeccathedev/afterfeed
LABEL org.opencontainers.image.title="Afterfeed" \
      org.opencontainers.image.description="A private, searchable home for exported social-media history" \
      org.opencontainers.image.source="https://github.com/${GITHUB_REPOSITORY}" \
      org.opencontainers.image.version="${AFTERFEED_VERSION}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfcgi-bin \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" exif gd intl opcache pdo_mysql pdo_pgsql zip \
    && apt-get purge -y libicu-dev libjpeg62-turbo-dev libpng-dev libpq-dev libwebp-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=php-dependencies /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY . .
COPY docker/php.ini /usr/local/etc/php/conf.d/afterfeed.ini
COPY docker/www.conf /usr/local/etc/php-fpm.d/zz-afterfeed.conf
COPY docker/entrypoint.sh /usr/local/bin/afterfeed-entrypoint

RUN chmod +x /usr/local/bin/afterfeed-entrypoint \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && mkdir -p /opt/afterfeed-public \
    && cp -a public/. /opt/afterfeed-public/ \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/database/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database

VOLUME ["/var/www/html/database", "/var/www/html/storage/app", "/var/www/html/public"]
EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD SCRIPT_FILENAME=/var/www/html/public/index.php SCRIPT_NAME=/up REQUEST_URI=/up REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q "Status: 200"

ENTRYPOINT ["afterfeed-entrypoint"]
CMD ["php-fpm", "-F"]
