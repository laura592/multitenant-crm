#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

echo "==> Git pull"
git pull

echo "==> Composer install"
composer install --no-interaction --prefer-dist

echo "==> NPM install + build"
npm install
npm run build

echo "==> Migrazioni database"
php artisan migrate --force

echo "==> Pulizia cache"
php artisan optimize:clear

echo "==> Rebuild cache (config, route, view, event)"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Filament optimize"
php artisan filament:optimize

echo "==> Riavvio queue worker (se attivo)"
php artisan queue:restart

echo "Fatto."
