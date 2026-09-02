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

# QUI NON SI TOCCANO RUOLI E PERMESSI. Fino al 2026-09-02 questo script
# lanciava `db:seed --class=RolesAndPermissionsSeeder`, che riallineava ogni
# ruolo di ogni tenant a App\Support\RolePermissions: risultato, ogni
# aggiornamento cancellava i permessi concessi a mano dalla pagina Ruoli del
# pannello. In produzione ruoli e permessi li decide chi amministra, non il
# deploy.
#
# Serve portare online una modifica a RolePermissions (o dare i ruoli a un
# tenant appena creato)? E' un gesto separato, da fare quando lo decidi tu:
#
#     php artisan ruoli:sincronizza --dry-run        # cosa cambierebbe
#     php artisan ruoli:sincronizza                  # applica, dopo conferma
#     php artisan ruoli:sincronizza --crea-mancanti  # tenant nuovo, ruoli da creare
#
# Il reset della cache resta: non cambia nessun permesso, ricarica solo quelli
# gia' in tabella dopo il deploy.
echo "==> Reset cache permessi (ruoli e permessi NON vengono modificati)"
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
