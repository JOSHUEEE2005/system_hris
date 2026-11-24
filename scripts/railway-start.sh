#!/usr/bin/env sh
set -eu

php artisan config:clear
php artisan route:clear
php artisan view:clear

php artisan migrate --force
php artisan storage:link >/dev/null 2>&1 || true
php artisan optimize

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
