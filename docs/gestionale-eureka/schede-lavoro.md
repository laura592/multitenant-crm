# Schede lavoro — `/schedelavoro/`

Il documento che rappresenta un intervento tecnico lato Eureka (equivalente
al nostro `ServiceReport`), usato per la fatturazione. È l'unico endpoint con
operazioni di scrittura.

## Creazione — `POST /schedelavoro/`

Campi principali (vedi `ServiceReport::toGestionalePayload()` per come li
costruiamo noi):

- `objectId` — chiave di idempotenza: chiamando di nuovo con lo stesso valore
  (+ `?checkObjectId=true` nella query string) si **aggiorna** il documento
  invece di duplicarlo. Noi usiamo `"CRM-{id del rapportino}"`.
- `intestatario.id_eureka` — **sempre il locale dove si esegue l'intervento**
  (mai il pagatore).
- `destinazione` (opzionale) — il pagatore, se diverso dall'intestatario.
  Se presente, `id_eureka` è obbligatorio secondo il fornitore: se lasciato a
  0 la fattura finirebbe intestata per errore al locale invece che al vero
  pagatore, senza nessun avviso lato Eureka. Noi lo popoliamo automaticamente
  da `ServiceReport::invoiceRecipient()` solo quando differisce dal cliente
  dove si lavora.
- `sl_articolo.id_eureka` — la macchina/bene su cui si interviene.
- `sl_matricola` — matricola (stringa, non id); se presente deve essere una
  matricola valida di `sl_articolo` (Eureka valida il confronto senza
  distinguere maiuscole/minuscole).
- `sl_tariffa.id_eureka` — vedi la discrepanza sulla tariffa "FISSA"/MAN in
  [articoli-e-tariffe.md](articoli-e-tariffe.md).
- `sl_sintomo` (obbligatorio), `sl_lavorazione` — problema riscontrato /
  lavoro svolto.
- `dettaglio[]` — ricambi usati, ognuno con `id_articolo`, `descrizione`,
  `um`, `quantita`, opzionalmente `matricole: [...]` se il ricambio stesso è
  tracciato a matricola.

La scrittura (testata + dettaglio) è **atomica**: se una riga fallisce non
si salva nulla.

## Lettura — `GET /schedelavoro/(id)`, `?id_codice_f15=`, `?data_da=&data_a=`, `?matricola=`

**Aggiornamento importante**: nei primi test (per singolo cliente) risultava
sempre vuoto, e si pensava che l'endpoint di scrittura non fosse ancora
attivo (il fornitore aveva comunicato l'attivazione per lunedì 2026-08-03).
**Non è così**: interrogando per intervallo di date (`?data_da=&data_a=`,
senza filtrare per cliente) sono emersi **centinaia di documenti reali e
recentissimi** (fino al 2026-07-23) — l'endpoint è quindi già popolato e
attivo, semplicemente i clienti testati singolarmente all'inizio non avevano
ancora schede lavoro proprie.

⚠️ **`data_da`/`data_a` vogliono un datetime ISO8601 completo**, non solo la
data: `data_da=2026-01-01` da solo dà `HTTP 500` ("DateTime parameters must
be formatted in ISO8601, e.g. 2010-10-12T10:12:23"). Serve
`data_da=2026-01-01T00:00:00&data_a=2026-07-30T23:59:59`.

Ogni documento reale visto aveva `id_tariffa_t61: 2` — vedi
[articoli-e-tariffe.md](articoli-e-tariffe.md) per la discussione sulla
tariffa "FISSA"/MAN, ora corroborata da questo storico vero.

## Modifica — `PUT /schedelavoro/(id)`

Full-replace, stesso body della creazione.

## Cancellazione

Esiste ma **non è documentata apposta dal fornitore** — non va usata. La
nostra integrazione non la implementa.

## Nota su `numero_doc`

Il fornitore avverte che `numero_doc` può cambiare tra una chiamata e
l'altra anche a parità di `objectId` — non va mai usato come riferimento
stabile per capire se un invio è già stato fatto. Usare sempre `id` (quello
che noi salviamo in `ServiceReport.gestionale_scheda_lavoro_id`).

## Collegamento con questo CRM

Il pulsante "Invia a gestionale" su un rapportino firmato
(`ServiceReportResource`) chiama `EurekaClient::inviaSchedaLavoro()` con il
payload di `ServiceReport::toGestionalePayload()`. Prima di chiamare l'API,
`ServiceReport::gestionaleValidationErrors()` controlla che cliente,
prodotto/macchina e (se serve) pagatore abbiano tutti un `gestionale_code`
— altrimenti blocca l'invio con un messaggio chiaro invece di scoprire
l'errore dalla risposta HTTP.
