#!/bin/sh
set -eu

cd /var/www/html

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    database_path="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    mkdir -p "$(dirname "$database_path")"
    touch "$database_path"
    chown www-data:www-data "$(dirname "$database_path")" "$database_path"
fi

mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
cp -a /opt/afterfeed-public/. public/
chown -R www-data:www-data storage bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY must be set to a persistent Laravel application key." >&2
    echo "Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

php artisan migrate --force
php artisan storage:link --relative >/dev/null 2>&1 || true
php artisan config:cache
php artisan view:cache

exec "$@"
