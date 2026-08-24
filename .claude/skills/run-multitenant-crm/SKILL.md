---
name: run-multitenant-crm
description: Avvia, esegue, guida e fa screenshot del CRM multitenant (Laravel 12 + Filament 3 in Docker Sail). Usala per lanciare l'app, fare login nel pannello, aprire una risorsa, leggere una tabella o un form, catturare screenshot, interrogare il database o far girare i test. Parole chiave - run, start, launch, avvia, screenshot, drive, smoke test, pannello admin, Filament.
---

# Eseguire il CRM multitenant

App Laravel 12 + Filament 3 in Docker Sail. Il pannello e' **l'unica**
superficie applicativa: non esistono route API, e `/` reindirizza su
`/admin`.

Si guida con **`.claude/skills/run-multitenant-crm/driver.mjs`**, un driver
Playwright headless che fa login, risolve da solo il prefisso di tenant
nelle route e fa screenshot.

Tutti i path sono relativi alla root del repo.

## Prerequisiti

Docker Desktop attivo e Node 20+. Playwright vive **fuori dal repo** (il
progetto non lo ha fra le dipendenze) e si installa una volta sola:

```bash
mkdir -p ~/.cache/multitenant-crm-driver
(cd ~/.cache/multitenant-crm-driver && npm i playwright && npx playwright install chromium)
```

## Avviare l'app

```bash
WWWUSER=$(id -u) WWWGROUP=$(id -g) docker compose up -d
until curl -sf -o /dev/null http://localhost:8092/up; do sleep 2; done; echo "pronta"
```

**Il loop di attesa non e' facoltativo.** Dopo `up -d` il container risulta
`Started` ma PHP-FPM non accetta ancora connessioni: `curl` subito dopo
ritorna `000` e il driver muore con `ERR_CONNECTION_RESET`. Servono ~20-30s.

Porta e URL vengono da `.env` (`APP_PORT=8092`).

## Guidare l'app (percorso agente)

```bash
node .claude/skills/run-multitenant-crm/driver.mjs smoke
node .claude/skills/run-multitenant-crm/driver.mjs open information-requests
node .claude/skills/run-multitenant-crm/driver.mjs form information-requests/create
node .claude/skills/run-multitenant-crm/driver.mjs shot /admin/alex/customers
```

| Comando | Cosa fa |
|---|---|
| `smoke` | `/up` + pagina di login + login + screenshot della dashboard |
| `open <risorsa>` | Apre l'elenco, stampa titolo, colonne, prima riga e paginazione |
| `form <risorsa>/create` | Apre il form, stampa i campi e quali sono obbligatori |
| `shot <path>` | Solo screenshot di un path qualsiasi |

Screenshot in `/tmp/crm-shots/`. **Guardali**: se sono bianchi, il login
non e' passato.

Esce `0` se tutto ok, `1` su HTTP non-2xx o app irraggiungibile, `2` se
Playwright non e' installato.

Override via env: `CRM_URL`, `CRM_EMAIL`, `CRM_PASSWORD`, `CRM_SHOTS`,
`PW_MODULES`.

Output reale di `open information-requests`:

```
· login OK, prefisso tenant: /admin/alex
· aperto: http://localhost:8092/admin/alex/information-requests → 200
· titolo: Richieste Informazioni
· colonne: Numero | Cliente | Email | Telefono | Stato | Appuntamento | ...
· righe visibili: 10
· paginazione: Mostrati da 1 a 10 di 102 risultati
· screenshot: /tmp/crm-shots/open-information-requests.png
· errori console JS: nessuno
```

### Credenziali

Utenze di sviluppo documentate nel README: `{ruolo}@test.it` / `password`.
`admin@test.it` e' `is_super_admin` ed e' il default del driver. Sono
credenziali locali: **non cambiarle e non resettarle.**

## Database

```bash
docker compose exec -T mysql mysql -usail -ppassword multitenant_crm \
  --default-character-set=utf8mb4 -e "SELECT COUNT(*) FROM customers;"
```

`--default-character-set=utf8mb4` non e' opzionale: senza, i dati accentati
tornano come `caff�`.

## Percorso umano

Apri <http://localhost:8092/admin> nel browser e fai login. Utile per
esplorare, inutile in headless.

## Test

```bash
WWWUSER=$(id -u) WWWGROUP=$(id -g) docker compose exec -T laravel.test \
  php artisan test --testsuite=Feature
```

~60s. Alla stesura di questa skill: **35 passati, 1 fallito, 139 pending**.
Il fallimento e' in `tests/Feature/CustomerLavaggiRelationManagerTest.php:68`
ed e' preesistente — verifica che sia ancora quello prima di inseguirlo.

## Gotchas

- **Le route sono tenant-scoped.** `/admin/information-requests` da 404;
  quella vera e' `/admin/alex/information-requests`. Il prefisso e' il path
  su cui atterri dopo il login — il driver lo scopre da solo, e `open`/`form`
  accettano il nome nudo della risorsa.
- **Non cliccare i link della sidebar.** In headless la sidebar e' collassata:
  Playwright trova l'`<a>` ma lo considera invisibile e va in timeout dopo 30s
  con "element is not visible". Naviga sempre per URL.
- **`getByRole('button', { name: /Crea/ })` sul form di creazione prende il
  bottone sbagliato.** Colpisce "Crea nuovo cliente" dentro la select Cliente
  e apre il modale di anagrafica inline, non il submit. Il submit vero e'
  "Salva" in fondo alla pagina.
- **Playwright non si carica con `NODE_PATH`.** Non funziona per gli import
  ESM: il driver usa `createRequire` con un path esplicito (`PW_MODULES`).
- **`docker compose` senza `WWWUSER`/`WWWGROUP`** stampa due warning e, alla
  prima `up`, **ricrea il container** perche' cambia l'hash della config.
  Innocuo ma sposta i log; passa sempre le due variabili.
- **Filament e' Livewire**: dopo una navigazione serve far sedimentare il DOM
  (il driver lo fa in `settle()`). Asserire subito da' falsi negativi.
- **Ogni riga di tabella inizia con la label screen-reader della checkbox**
  di selezione massiva, UUID incluso. Il driver la ripulisce.

## Troubleshooting

| Sintomo | Fix |
|---|---|
| `✗ Playwright non trovato in ...` (exit 2) | Esegui l'installazione nei Prerequisiti |
| `net::ERR_CONNECTION_REFUSED` su `/up` | Container giu': `docker compose up -d` + loop di attesa |
| `net::ERR_CONNECTION_RESET` o `curl` che da `000` | Container su ma PHP non ancora pronto: hai saltato il loop di attesa |
| Timeout "element is not visible" su un link | Stai cliccando la sidebar collassata: naviga per URL |
| Screenshot bianco | Login non passato: controlla `CRM_EMAIL`/`CRM_PASSWORD` |
| `HTTP 404` da `open <risorsa>` | Nome risorsa sbagliato: prendilo dall'`href` in `smoke` |
| Testo con `�` dal DB | Manca `--default-character-set=utf8mb4` |
