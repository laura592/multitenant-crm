# CLAUDE.md — Istruzioni per Claude Code

Questo repository implementa il gestionale Alex S.r.l. descritto in `PRD.md`,
`ARCHITECTURE.md` e `DATABASE-SCHEMA.md`. Leggi sempre questi tre file prima di
iniziare a lavorare su una funzionalità.

## Regole generali

1. **Un solo Laravel/database condiviso** fra panel `/admin`, panel `/partner`
   e API per Flutter. Non creare database o servizi separati per queste parti.
2. **Global scope sempre via Eloquent.** Qualsiasi query che coinvolga il
   modello `Product` (catalogo, configuratore, slot) deve passare da Eloquent
   per rispettare lo scoping per brand del panel `/partner`. Non scrivere query
   raw/`DB::table()` su `products` all'interno del contesto partner.
3. **Snapshot, non riferimenti.** Prezzi e configurazioni scelte in un
   preventivo/ordine vanno sempre copiati (snapshot) nelle tabelle
   `quote_items`/`quote_item_components`, mai letti a runtime da
   `price_list_items`/`product_option_slot_items`. Un cambio di prezzo futuro
   non deve mai alterare un preventivo/ordine già confermato.
4. **Rapportini immutabili.** Nessun endpoint API né azione Filament deve
   permettere update di un `work_report` dopo che `signature_path` è valorizzato.
   Solo insert.
5. **Logica di business condivisa, non duplicata.** La creazione di un
   rapportino (da API Flutter o da Filament) deve passare sempre dalla stessa
   Action class (`App\Actions\CreateWorkReportAction`), che si occupa di:
   salvare il rapportino, chiudere la `deadline` collegata, generare lo scarico
   in `warehouse_movements`, dispatchare il job di generazione PDF/invio email.
6. **Job asincroni per PDF/email.** Mai generare PDF o inviare email in modo
   sincrono dentro la risposta di un endpoint API — sempre via job in coda,
   perché la sync può avvenire con connessione instabile lato tablet.
7. **UUID per i dati creati offline.** `work_reports` e `attendances` create da
   Flutter usano UUID generato client-side come chiave di idempotenza
   (`INSERT ... ON CONFLICT DO NOTHING` sull'UUID in sync).

## Convenzioni di codice

- Naming tabelle/colonne in italiano quando riflettono concetti di dominio
  (`deadlines`, `prossima_scadenza`, `ultima_esecuzione`) — segui lo schema in
  `DATABASE-SCHEMA.md` senza tradurre o rinominare.
- Un modello Eloquent per tabella, relazioni esplicite (niente accessor magici
  per relazioni non dichiarate).
- Ogni Filament Resource sensibile ai prezzi (`Product`, `PriceListItem`) deve
  avere il trait `LogsActivity` di spatie/laravel-activitylog.
- Permessi via `spatie/laravel-permission` + `filament-shield`: non hardcodare
  controlli di ruolo nei controller, usa Policy.

## Ordine di sviluppo consigliato

1. Migrazioni + modelli del catalogo (brands, categories, products, slots)
2. Listini + scoping per brand (global scope, testato con almeno 2 brand + Universale)
3. Panel Filament `/admin`: CRUD catalogo, listini, RBAC base
4. Panel Filament `/partner`: wizard configuratore preventivo, scoping partner
5. Clienti, impianti, mezzi, scadenzario polimorfico + job notifiche
6. Rapportini (modello + Action class + job PDF/email) — testabili da Filament
   prima ancora che esista l'app Flutter
7. Magazzino ricambi + scarico automatico da rapportino
8. HR: presenze, contratti, ferie, calcolo straordinari
9. API Sanctum per Flutter (sync-data, upload rapportini/presenze)
10. App Flutter: DB locale drift, sync, firma, notifiche push

## Cosa NON fare senza chiedere

- Non introdurre un frontend Vue/React separato per calendario o export CSV:
  usare `filament-fullcalendar` e le azioni di export native di Filament.
- Non creare listini per-partner: il listino è unico e globale, lo scoping è
  solo sulla visibilità dei prodotti per brand.
- Non aggiungere un flusso di richiesta/approvazione preventiva per gli
  straordinari: sono solo calcolo automatico a consuntivo.
