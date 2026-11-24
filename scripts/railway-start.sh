#!/usr/bin/env sh
set -eu

if [ -z "${APP_URL:-}" ] || [ "$APP_URL" = "http://localhost" ]; then
    if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
        export APP_URL="https://${RAILWAY_PUBLIC_DOMAIN}"
        export ASSET_URL="${APP_URL}"
    fi
fi

if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
    export APP_URL="${APP_URL:-https://${RAILWAY_PUBLIC_DOMAIN}}"
    export ASSET_URL="${ASSET_URL:-${APP_URL}}"
fi

rm -f public/hot

php artisan config:clear
php artisan route:clear
php artisan view:clear

php artisan migrate --force
php artisan storage:link >/dev/null 2>&1 || true
php artisan optimize

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
