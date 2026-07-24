# Alex Partner Hub

CRM multi-tenant per Alex S.r.l. e i suoi partner rivenditori: catalogo prodotti
condiviso, preventivi, clienti, scadenzario, rapportini tecnici, presenze/ferie
e provvigioni. Backend Laravel 12 + pannello Filament 3, single-panel (`/admin`)
con multi-tenancy nativa di Filament (Alex è il "tenant master", i partner sono
tenant normali che vedono solo i propri dati).

Per il contesto di dominio, le decisioni architetturali e i dettagli del
modello dati, la fonte primaria è **`docs/architecture.md`** — questo README
copre solo "come far girare il progetto in locale".

## Stack

- PHP 8.2+ (Sail usa PHP 8.4), Laravel 12
- Filament 3 (`filament/filament`), `filament-shield` (RBAC via
  `spatie/laravel-permission` in modalità *teams*, un tenant = un team),
  `filament-breezy`, `filament-excel`
- `barryvdh/laravel-dompdf` per i PDF (preventivi, rapportini, ordini materiali)
- Database: SQLite in locale "veloce" (default di `.env.example`) oppure MySQL
  8.0 via Docker/Sail (parità con l'ambiente del vecchio progetto legacy)
- Vite + Tailwind 4 per gli asset del pannello
- Queue: driver `database` (tabella `jobs`), usata per PDF/email dei
  preventivi/rapportini — non sincroni nella request
- Test: PHPUnit (`tests/Unit`, `tests/Feature`), DB `sqlite :memory:` dedicato
  in `phpunit.xml`
- Static analysis: PHPStan/Larastan (`phpstan.neon`)

## Setup rapido (SQLite, senza Docker)

Il modo più veloce per partire in locale: `.env.example` è già configurato con
`DB_CONNECTION=sqlite`.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed
npm install
npm run build
```

Esiste anche uno script Composer che automatizza i primi passi
(install, copia `.env`, `key:generate`, `migrate --force`, `npm install`,
`npm run build`):

```bash
composer setup
```

Non crea il file `database/database.sqlite` né lancia i seeder — se parti da
zero con SQLite vanno fatti a mano prima (`touch database/database.sqlite` e
`php artisan db:seed`).

Poi in due terminali separati (oppure con `composer dev`, vedi sotto):

```bash
php artisan serve
npm run dev
```

Pannello disponibile su `http://localhost:8000/admin`.

### Utenti di test

`php artisan db:seed` crea (tramite `UserSeeder`) un utente per ciascuno dei
ruoli applicativi definiti in `App\Support\RolePermissions::roles()`
(`dipendente`, `amministrazione`, `partner`, `admin`), tutti
sul tenant master "Alex":

```
{ruolo}@test.it / password
```

es. `admin@test.it` / `password` — questo utente è anche `is_super_admin`
(può gestire tenant e ruoli, aree riservate allo staff Alex).

## Setup con Docker/Sail (parità con MySQL)

Serve soprattutto per il comando `import:legacy` (richiede MySQL, vedi sotto)
o per riprodurre un ambiente più vicino a produzione. `.env.example` ha in
fondo, commentati, i valori già pronti per questo repo (porte non standard
per poter girare in parallelo ad altri progetti Sail):

```bash
# In .env, scommenta/imposta:
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=multitenant_crm
DB_USERNAME=sail
DB_PASSWORD=password
APP_PORT=8092
VITE_PORT=5175
FORWARD_DB_PORT=3309
```

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
```

Pannello su `http://localhost:8092/admin` (o la porta impostata in
`APP_PORT`).

**Non verificato in questa sessione**: `docker-compose.yml` è stato solo
letto, non è stato avviato davvero nessun container (nessun Docker
disponibile nell'ambiente di questo lavoro). I valori sopra vengono da lì e
da `.env.example`, ma vanno controllati de visu la prima volta che si usa
Sail su questo repo.

## Comandi di sviluppo

```bash
composer dev      # server + queue:listen + pail (log live) + vite, tutto insieme
php artisan serve
php artisan queue:listen --tries=1 --timeout=0   # se non usi `composer dev`:
                                                  # PDF/email dei preventivi passano da coda
php artisan pail   # log in tempo reale
npm run dev        # Vite in watch
```

Se lavori su una funzionalità che genera PDF o invia email (preventivi,
rapportini, ordini materiali), **serve un worker di coda attivo** — altrimenti
il job resta accodato in `jobs` e non succede nulla finché non lo processi.

## Test e qualità

```bash
composer test      # php artisan test (config:clear prima, DB sqlite :memory:)
composer analyse   # PHPStan/Larastan, --memory-limit=1G
composer verify    # test + analyse insieme — è il gate da lanciare prima di un commit/PR importante
```

Verificati entrambi lanciandoli davvero in questa sessione: `composer test`
passa tutti gli 88 test. `composer analyse` riporta 7 errori, tutti su
`app/Models/AuditLog.php` e `app/Providers/AuditLogServiceProvider.php`
(lavoro non ancora committato, non collegato alla rimozione del calendario
appuntamenti). Vedi anche `docs/checklist-rilascio.md`.

## Multi-tenancy e tenant master

Schema DB **unico e condiviso**, niente `stancl/tenancy`: lo scoping è fatto
con la multi-tenancy nativa di Filament (`Panel::tenant()`) più una colonna
`tenant_id` e global scope Eloquent. Motivazione estesa in
`docs/architecture.md` §3.

Punti chiave:

- **Alex è il tenant master** (`tenants.is_master = true`, seedato da
  `TenantSeeder` con slug `alex`). Gli altri tenant sono partner rivenditori
  (es. Gifar).
- **Utenti**: un utente appartiene a **un solo tenant** (`users.tenant_id`,
  nullable) oppure è `is_super_admin = true` (staff Alex), nel qual caso non
  appartiene a nessun tenant e bypassa lo scope, vedendo/gestendo tutti i
  tenant.
- **Catalogo condiviso**: `categories`/`products`/`product_families` hanno
  `tenant_id` **nullable**. `NULL` = catalogo condiviso con tutti i partner
  (es. listino Franke ufficiale); valorizzato = prodotto privato di quel
  tenant (o del master).
- **Tutto il resto è del tenant**: `customers`, `quotes`,
  `information_requests`, `comodato_macchine` e simili hanno `tenant_id`
  **NOT NULL** — un cliente/preventivo appartiene sempre a un solo tenant, ed
  è quello che il global scope filtra per l'utente corrente.
- **Permessi**: `filament-shield` + `spatie/laravel-permission` in modalità
  *teams* con `tenant_id` come team id (`config/permission.php`) — ruoli e
  permessi di un tenant non sono visibili/assegnabili da un altro tenant.
  5 ruoli applicativi (vedi sopra), definiti in un solo posto
  (`App\Support\RolePermissions`) e usati sia dal seeder di produzione sia
  dai test, per non farli divergere. Dopo aver aggiunto/rimosso una Resource
  Filament o un permesso, vedi `docs/checklist-rilascio.md`.

Per il modello dati completo (tabelle, colonne, motivazioni di ogni scelta)
non è duplicato qui: leggi `docs/architecture.md` §4 (modello dati) e §5.3
(ruoli e permessi), che restano la fonte aggiornata.

## Import dati legacy

Il progetto nasce dalla migrazione di un gestionale preventivi precedente
(dump MySQL `nbalexca_app_preventivi.sql`). Il comando

```bash
php artisan import:legacy [--force]
```

(`app/Console/Commands/ImportLegacyData.php`) legge da una connessione DB
dedicata e sola-lettura chiamata `legacy` (richiede MySQL — vedi setup
Docker/Sail sopra — e la variabile `LEGACY_DB_DATABASE` in `.env`, di default
`legacy_preventivi`) e scrive nel nuovo schema multi-tenant: catalogo →
`tenant_id = NULL` (condiviso), tutto il resto (clienti, preventivi, email,
richieste informazioni, comodato macchine) → tenant master Alex. Rifiuta di
girare se ci sono già clienti nel DB di destinazione, a meno di `--force`
(rischio duplicati). Dettagli del mapping legacy→nuovo schema in
`docs/architecture.md` §8.1–§8.2; è un comando pensato per un import
una-tantum, non per sincronizzazioni ricorrenti.

## Struttura del progetto (punti di interesse)

- `app/Filament/Resources` — CRUD del pannello admin, una Resource per
  entità di dominio
- `app/Models/Concerns` — trait condivisi (scoping tenant, ecc.)
- `app/Support/RolePermissions.php` — unica fonte dei permessi per ruolo
- `app/Policies` — autorizzazioni generate/estese da Shield
- `database/seeders` — `TenantSeeder`/`UserSeeder`/`RolesAndPermissionsSeeder`
  danno un ambiente locale utilizzabile da subito; gli altri seeder popolano
  dati demo per i vari moduli (scadenzario, presenze, ecc.)
- `docs/architecture.md` — documento tecnico esteso (dominio, modello dati,
  decisioni, piano di rilascio) — **da leggere prima di lavorare su una
  funzionalità non banale**
- `docs/roadmap-tickets.md` — backlog tecnico in corso, diviso per epic

## Cose da sapere prima di aprire una PR

- Dopo aver aggiunto/tolto una Resource Filament o cambiato permessi,
  rigenera Shield e risincronizza i ruoli — vedi
  `docs/checklist-rilascio.md`.
- Non introdurre `stancl/tenancy` o database separati per tenant: è una
  decisione architetturale deliberata, vedi `docs/architecture.md` §3.
- Prezzi/configurazioni scelte in un preventivo vanno sempre salvate come
  snapshot sulle righe del preventivo, mai lette a runtime dal listino al
  momento della visualizzazione: un cambio prezzo futuro non deve alterare
  un preventivo già emesso.
- Lancia `composer verify` prima di aprire una PR importante (vedi sopra i
  4 fallimenti noti attuali, da non confondere con regressioni introdotte
  dalla tua modifica).
