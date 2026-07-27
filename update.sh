#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

# Su hosting condiviso (cPanel) il `php`/`composer` di default in PATH puo'
# non essere la versione richiesta da composer.json (vedi docs/deploy-cutover.md,
# Fase 1). Sovrascrivi con: PHP_BIN=php8.4 ./update.sh
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

echo "==> PHP in uso: $($PHP_BIN -v | head -1)"

echo "==> Git pull"
git pull

echo "==> Composer install"
"$PHP_BIN" "$(command -v "$COMPOSER_BIN")" install --no-interaction --prefer-dist

echo "==> NPM install + build"
npm install
npm run build

echo "==> Migrazioni database"
"$PHP_BIN" artisan migrate --force

# I permessi di ogni ruolo sono dati in role_has_permissions, non solo
# codice: senza questo passo, una modifica a App\Support\RolePermissions
# resta senza effetto online finche' non si ri-sincronizzano manualmente
# (successo il 2026-07-27 con "dipendente" e i preventivi).
echo "==> Sincronizzazione ruoli/permessi"
"$PHP_BIN" artisan db:seed --class=RolesAndPermissionsSeeder --force
"$PHP_BIN" artisan permission:cache-reset

echo "==> Pulizia cache"
"$PHP_BIN" artisan optimize:clear

echo "==> Rebuild cache (config, route, view, event)"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

echo "==> Filament optimize"
"$PHP_BIN" artisan filament:optimize

echo "==> Riavvio queue worker (se attivo)"
"$PHP_BIN" artisan queue:restart

echo "Fatto."
