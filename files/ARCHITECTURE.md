# ARCHITETTURA — Gestionale Alex S.r.l.

## 1. Stack tecnologico

| Componente | Tecnologia | Note |
|---|---|---|
| Backend/API | Laravel (ultima LTS) | Unico database, source of truth |
| Admin panel interno | Filament (panel `/admin`) | |
| Portale partner | Filament (panel `/partner`) | Stesso Laravel, panel separato, brand/logo/colori personalizzabili |
| Auth API (Flutter) | Laravel Sanctum | Token persistente sul device |
| RBAC | spatie/laravel-permission + filament-shield | |
| Audit log | spatie/laravel-activitylog | Su modelli sensibili (prezzi, listini) |
| Calendario in-panel | filament-fullcalendar | No frontend separato |
| Export CSV | Filament export actions / maatwebsite/excel | No frontend separato |
| App tecnici | Flutter (Android + iOS, stessa codebase) | Offline-first |
| DB locale Flutter | drift (SQLite) | Query type-safe, migrazioni gestite |
| HTTP client Flutter | dio | |
| Stato rete Flutter | connectivity_plus | |
| Storage token Flutter | flutter_secure_storage | |
| Firma digitale | package `signature` | PNG salvato localmente poi sincronizzato |
| Notifiche push | Firebase Cloud Messaging + firebase_messaging | |
| Generazione PDF rapportino | Laravel (dompdf o spatie/laravel-pdf) | Lato server, in coda |
| Invio email | Laravel Mail + queue | Asincrono, mai bloccante sulla risposta API |

## 2. Perché questa architettura

- **Un solo Laravel, un solo database**: catalogo/listini/clienti sono condivisi
  fra pannello interno, portale partner e API Flutter — zero duplicazione dati.
- **Filament multi-panel invece di un frontend custom per i partner**: i
  requisiti di branding (logo + colori) sono coperti nativamente da Filament
  (`brandLogo()`, `colors()`), risparmiando lo sviluppo di un'app separata.
- **Flutter invece di PWA**: richiesta di offline reale con firma/allegati e uso
  su tablet sia Android che iOS — Flutter dà storage nativo affidabile (drift)
  e un'unica codebase per entrambe le piattaforme.
- **Job/queue per PDF ed email**: la sincronizzazione dal tablet non deve
  dipendere dalla durata di una connessione instabile — l'API risponde subito
  "ricevuto", il resto (PDF, email) lo gestisce il server con retry automatici.

## 3. Struttura repository

Due repository separati:

```
alex-gestionale-laravel/     # Laravel + Filament (backend + 2 panel + API)
alex-tecnici-flutter/        # App Flutter per tablet tecnici
```

## 4. Global scope multi-tenant (panel /partner)

- `Product`: global scope attivo solo nel contesto panel `/partner`, filtra
  `whereIn('brand_id', $partner->allowedBrandIds())`
- **Attenzione**: il global scope funziona solo se le query passano da Eloquent
  (`Product::query()->...`). Query raw (`DB::table('products')->join(...)`)
  bypassano lo scope — da evitare nel configuratore, o riapplicare il filtro
  esplicitamente.
- `Quote`/`Order`: global scope + Policy per `where('partner_id', ...)`, sia per
  filtrare le liste sia per bloccare accessi diretti via ID

## 5. Flusso di sincronizzazione Flutter

```
PULL (dati di riferimento, sola lettura in locale)
  GET /api/technician/sync-data
  → clienti/impianti/scadenze assegnati al tecnico per il periodo corrente
  → salvati in tabelle *_cache locali (drift)

PUSH (dati creati offline)
  work_reports_local, attendances_local (sync_status: pending|synced|error)
  → UUID generato client-side (mai ID autoincrementale, evita collisioni)
  → al ritorno rete: upload multipart (JSON + firma PNG + eventuali foto)
  → idempotente lato server (INSERT ... ON CONFLICT DO NOTHING sullo UUID)
  → successo: sync_status = synced; fallimento: resta pending, retry automatico
```

## 6. Regola di immutabilità rapportini

Un rapportino firmato non è più modificabile, né lato Flutter né lato Filament.
La sync è quindi sempre un INSERT, mai un UPDATE — nessuna gestione di conflitti
di modifica da prevedere.

## 7. Logica condivisa (evitare doppia implementazione)

Poiché i rapportini possono nascere sia da Flutter (via API) sia da Filament
(fallback da ufficio), la logica di business (salva rapportino → aggiorna
scadenza collegata → scarico magazzino → dispatch job PDF/email) va scritta
**una sola volta** in una Action class Laravel (es. `App\Actions\CreateWorkReportAction`),
richiamata da entrambi i punti di ingresso. Non duplicare questa logica nel
controller API e nella Filament resource.
