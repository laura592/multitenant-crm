#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

# PHP_BIN non serve piu' sulla VPS: /usr/bin/php e' gia' php8.5, ed e'
# l'unica versione installata. Serviva su cPanel, dove il `php` in PATH era
# una versione piu' vecchia di quella che chiede composer.json. Resta
# sovrascrivibile per quel caso, ma non va scritto a ogni deploy -- e se la
# versione fosse sbagliata lo script si ferma da solo, due righe sotto.
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

# Sulla VPS si installa senza le dipendenze di sviluppo:
#     COMPOSER_FLAGS="--no-dev --optimize-autoloader" ./update.sh
# Senza --no-dev, composer rimette in produzione laravel/pail e compagnia, e
# bootstrap/cache torna a citare un service provider che in produzione non ci
# deve stare: e' il 500 su ogni pagina del 28/09/2026.
COMPOSER_FLAGS="${COMPOSER_FLAGS:-}"

echo "==> PHP in uso: $($PHP_BIN -v | head -1)"

# Meglio fermarsi qui che a meta' di un composer install. La versione
# minima e' quella di composer.json ("php": "^8.2").
PHP_MINIMA="8.2"
if ! "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, $argv[1], ">=") ? 0 : 1);' "$PHP_MINIMA"; then
    echo "   PHP troppo vecchio: serve almeno $PHP_MINIMA." >&2
    echo "   Indica quello giusto con PHP_BIN=phpX.Y ./update.sh" >&2
    exit 1
fi

echo "==> Git pull"
git pull

echo "==> Composer install"
"$PHP_BIN" "$(command -v "$COMPOSER_BIN")" install --no-interaction --prefer-dist $COMPOSER_FLAGS

echo "==> NPM ci + build"
# `npm ci`, non `npm install`: install riscrive package-lock.json con quello
# che trova installato, e il lock e' un file tracciato. Risultato, il 03/11/2026
# il deploy si e' fermato su "Your local changes to the following files would be
# overwritten by merge: package-lock.json", con dentro solo rumore generato
# (il nome del pacchetto e le voci libc). ci installa esattamente quello che
# dice il lock e non lo tocca mai.
npm ci
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
