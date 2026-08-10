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
- `sl_articolo.id_eureka` — **solo** il bene/macchina principale su cui si
  interviene, mai un ricambio. Confermato dal fornitore (2026-08-06): se ci
  si mette un ricambio invece della macchina, e si passa anche
  `sl_matricola`, la validazione della matricola contro quell'articolo fa
  fallire la chiamata con `422`; e comunque falserebbe lo storico interventi
  per macchina. I ricambi/componenti vanno **sempre** in `dettaglio[]` (vedi
  sotto), tramite il loro `id_articolo`. Macchine e ricambi condividono lo
  stesso catalogo articoli lato Eureka (anche l'app tablet li mostra insieme)
  — è solo il ruolo nel documento (testata vs dettaglio) a distinguerli, non
  un flag sull'articolo stesso.
  Per interventi generici non legati a un bene specifico (es. sopralluoghi),
  ad oggi **non esiste un articolo predefinito lato Eureka**: il fornitore
  suggerisce di farne creare uno ("INTERVENTO GENERICO") come articolo di
  magazzino — ma va creato da loro o dall'amministrazione direttamente nel
  gestionale, perché il nostro accesso API agli articoli è di **sola
  lettura** (vedi perimetro concordato in fondo a questo file).
- `sl_matricola` — matricola (stringa, non id); se presente deve essere una
  matricola valida di `sl_articolo` (Eureka valida il confronto senza
  distinguere maiuscole/minuscole).
- `sl_tariffa.id_eureka` — sempre `2` per ALEX srl, confermato dal fornitore
  (vedi [articoli-e-tariffe.md](articoli-e-tariffe.md)).
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
stabile per capire se un invio è già stato fatto. Usare sempre `id_eureka`
(quello che noi salviamo in `ServiceReport.gestionale_scheda_lavoro_id`).

⚠️ **La risposta di `POST /schedelavoro/` identifica il documento con
`id_eureka`, non `id`** — stessa insidia già nota su `GET /schedelavoro/(id)`
(vedi `EurekaClient.php`). Bug reale trovato il 2026-08-06:
`SendServiceReportToGestionaleJob.php` leggeva `$result['id']` (sempre
assente) invece di `$result['id_eureka']`, quindi ogni invio riuscito
salvava comunque `gestionale_scheda_lavoro_id = NULL` in locale. Corretto.

## `stato_documento` — stato del documento lato Eureka

Non documentato dal fornitore, dedotto testando dal vivo il 2026-08-06.
Valori osservati finora:

| `stato_documento` | `stato_documento_descr` | Significato |
|---|---|---|
| 3 | Ricevuto | Non ancora archiviato/chiuso |
| 7 | Evaso | Appena creato via API (visto sulla prima scheda lavoro inviata dal CRM) |
| 10 | Archiviato | Documento chiuso/definitivo — la stragrande maggioranza dello storico reale importato (565/583) |

Usato da `ImportEurekaServiceReports::mapStatus()` per decidere lo stato
locale del rapportino importato: `10` (Archiviato) → `inviato` (il cliente
ha già il documento), qualunque altro valore → `bozza` (non ancora chiuso).

**Bug reale trovato e corretto il 2026-08-06**: la condizione era invertita
fin dall'inizio — un commento assumeva che `10` significasse "documento
aperto/in corso" (quindi `bozza`), ma `stato_documento_descr` conferma il
contrario (`10` = "Archiviato" = chiuso). Risultato: 567 rapportini su 583
importati risultavano in "Bozza" invece che "Inviato". Corretto e
ribackfillato su tutto lo storico.

## Collegamento con questo CRM

Il pulsante "Invia a gestionale" su un rapportino firmato
(`ServiceReportResource`) chiama `EurekaClient::inviaSchedaLavoro()` con il
payload di `ServiceReport::toGestionalePayload()`. Prima di chiamare l'API,
`ServiceReport::gestionaleValidationErrors()` controlla che cliente,
prodotto/macchina, descrizione del problema (`sl_sintomo`, obbligatorio) e
(se serve) pagatore abbiano tutti un `gestionale_code` — altrimenti blocca
l'invio con un messaggio chiaro invece di scoprire l'errore dalla risposta
HTTP.

**Perimetro API concordato col fornitore**: sola lettura su anagrafiche e
articoli; scrittura consentita solo sull'endpoint schede lavoro. Non
possiamo quindi creare/modificare articoli (es. un ipotetico "INTERVENTO
GENERICO") via API — va fatto dal fornitore o dall'amministrazione
direttamente nel gestionale.

**Ambiente di test**: esiste, separato dalla produzione (richiesto
esplicitamente al fornitore) — verificato funzionante il 2026-08-06 con un
invio reale di prova (rapportino di test → documento Eureka `id_eureka
17280`, stato "Evaso"). Va comunque usato con cautela: non è possibile
cancellare un documento una volta creato (vedi sopra), nemmeno in test.
