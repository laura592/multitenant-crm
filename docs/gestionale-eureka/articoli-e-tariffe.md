# Catalogo articoli e tariffe manodopera

## Articoli — `GET /articoli/lista/(codice)` e `/articoli/articolo/(codice)`

Cerca un articolo per codice: `lista` fa un match parziale, `articolo` un
match esatto. Risposta tipo:

```json
{
  "id_eureka": 364,
  "codice": "10PCKWSRV",
  "descr1": "10-pack of Windows Server 2025/2022 User",
  "um": "NR",
  "prezzo01": 199.00,
  "ultimo_costo": 0.0
}
```

`id_eureka` è il valore da salvare in `gestionale_code` per collegare un
record del CRM all'articolo Eureka (serve per `sl_articolo` nelle schede
lavoro). Il campo esiste sia su `Product` (macchine a listino) sia su
`Material` (anagrafica articoli) — vedi la sezione "Dove vivono gli articoli"
qui sotto per capire quale dei due usare.

**Bug reale trovato e corretto il 2026-08-06**: quando
`ImportEurekaServiceReports::resolveOrCreateProduct()` (oggi
`resolveExistingProduct()`) trovava un prodotto
già esistente a catalogo (il caso comune, es. matchato per SKU dal dump di
produzione), riusava il record **senza mai scrivergli `gestionale_code`**
— solo i prodotti creati ex-novo lo ricevevano. Risultato: su 407 prodotti a
catalogo, **zero** avevano `gestionale_code`, quindi nessun rapportino con
una macchina collegata poteva mai passare
`ServiceReport::gestionaleValidationErrors()` e venire inviato a gestionale.
Corretto con un backfill (`backfillProductEurekaCode()`) che scrive il
codice anche sui prodotti già esistenti trovati durante l'import; rilanciare
`eureka:import-service-reports` per applicarlo ai record già importati.

**Aggiornamento — verificato con codici reali del catalogo Alex**: cercare
per **SKU esatto del nostro catalogo non funziona quasi mai** (es.
`DC-XT-2G-BARISTA`, `A600-FM-EC-MU-1G-H1` → zero risultati: i formati dei
due sistemi non coincidono). Cercare invece per **parola chiave del modello**
funziona bene:

| Cerca (`/articoli/lista/...`) | Trovato su Eureka |
|---|---|
| `ICON` | `ICON3GR` — "DALLA CORTE ICON 3 GRUPPI" (id 19426), tra 15 risultati (anche ricambi non correlati — la ricerca non è un semplice "contains" pulito) |
| `XT` | `XTBARISTA` (id 19378), `XTCLASSIC` (id 19551) |
| `A600` | `A600FM` — "MACCHINA PER CAFFE' FRANKE A600FM" (id 19339) |

Per questo `EurekaClient::cercaArticoli()`/l'azione "Cerca su Eureka" cercano
per parola chiave e lasciano sempre scegliere a vista il risultato giusto —
mai un collegamento automatico basato sul codice SKU.

**`GET /articoli/articolo/(codice)` (match esatto) non dà 404 se non trova
nulla** — torna sempre `HTTP 200` con un oggetto singolo `id_eureka: 0` e
tutti gli altri campi vuoti/`""`. Va controllato `id_eureka !== 0` per capire
se l'articolo esiste davvero, non lo status HTTP. Verificato: `ICON3GR` torna
l'oggetto reale con `"stato": "A"`; un codice inventato torna l'oggetto vuoto
con `id_eureka: 0` e `"stato": ""`.

## Tariffe manodopera — `GET /crm_api/t61/tariffe`

Nessun parametro, ritorna tutte le tariffe attive. **Dato reale completo**
(3 tariffe, nessuna paginazione necessaria):

```json
{
  "items": [
    {"id": 6, "tariffa": "HTS",   "descr": "INTERVENTO HTS",   "manodopera_oraria": null, "id_articolo_m10": 125,  "diritto_fisso": 30.0},
    {"id": 7, "tariffa": "HTS-F", "descr": "HTS FESTIVO",      "manodopera_oraria": null, "id_articolo_m10": 1397, "diritto_fisso": null},
    {"id": 2, "tariffa": "MAN",   "descr": "MANODOPERA STD",   "manodopera_oraria": null, "id_articolo_m10": 123,  "diritto_fisso": null}
  ],
  "total": 3
}
```

### Tariffa "FISSA"/MAN — chiarito col fornitore (2026-08-06)

La documentazione fornita da Eureka dice testualmente: *"Ad oggi ALEX srl non
utilizza le tariffe: nella scheda lavoro indicare **sempre** la tariffa con
`id` = 2 (**"FISSA"**)"*.

Ma i dati reali sopra mostrano che l'id 2 è **"MAN" / "MANODOPERA STD"** — non
esiste nessuna tariffa chiamata "FISSA" tra quelle attive. Il codice
(`ServiceReport::toGestionalePayload()`) usa comunque l'id 2 come da
istruzioni del fornitore.

Corroborato prima dai dati reali (leggendo lo storico vero delle schede
lavoro, `GET /schedelavoro/?data_da=...&data_a=...`, centinaia di documenti
reali fino al 2026-07-23: **ogni singolo documento aveva
`"id_tariffa_t61": 2`**, nessuna eccezione) e poi **confermato per iscritto
dal fornitore**: "FISSA"/MAN resta solo un'incongruenza di nome nella loro
documentazione, non un rischio concreto — l'id da usare è sempre `2`. Da
richiedere di nuovo a Eureka solo se in futuro ALEX inizia a usare tariffe
multiple.

## Distinguere macchine da ricambi nel catalogo articoli

Confermato dal fornitore (2026-08-06): `/articoli/lista/` e
`/articoli/articolo/` restituiscono **l'intero catalogo indistintamente**
(macchine, ricambi, materiali) — non esiste un filtro per categoria/famiglia
su questi endpoint, e comunque la classificazione per famiglia/sottofamiglia
non è mantenuta bene lato Eureka (confermato anche da noi: i campi
`famiglia`/`sottofamiglia`/`sottosottofamiglia` tornano quasi sempre vuoti
con `id_eureka: 0`).

Per selezionare **solo le macchine** installate presso un cliente (il caso
giusto quando si compila `sl_articolo` in una scheda lavoro, vedi
[schede-lavoro.md](schede-lavoro.md)), usare invece
`GET /show/q/art_installati?q=<id_codice_f15>` — vedi
[macchinari.md](macchinari.md).

Esiste anche `GET /articoli/ricerca?rql=...` con filtri sui campi anagrafici
dell'articolo (inclusa la famiglia), ma è utilizzabile solo se si concorda
con Eureka una classificazione per famiglie che separi davvero ricambi e
materiali — non ancora fatto.

## Dove vivono gli articoli: Materiali, non Prodotti (2026-08-27)

Su Eureka gli articoli sono **una tabella sola** (`sl_articolo`): macchine,
ricambi, manodopera e lavaggi stanno tutti lì, senza una classificazione
usabile (vedi la sezione qui sopra). Nel CRM quella stessa anagrafica è
**Materiali**, non Prodotti:

| | Cos'è | Chi la riempie |
|---|---|---|
| **Materiali** (`materials`) | l'anagrafica articoli di Eureka: macchine, ricambi, manodopera, lavaggi | `eureka:sweep-materials-catalog` e gli import rapportini |
| **Prodotti** (`products`) | il catalogo commerciale a listino: famiglie, opzioni, slot di configurazione, prezzi | inserimento a mano dai PDF in Listini |
| **Macchinari** (`machine_units`) | la matricola fisica installata | import Eureka o inserimento a mano |

Una macchina che vendiamo noi è **entrambe le cose**: un `Product` per
configurarla a preventivo e un `Material` per assisterla e fatturarla. Il
ponte fra i due è `gestionale_code`, che vale lo stesso id articolo su
entrambi.

Fino al 2026-08-27 non era così: `ImportEurekaServiceReports` materializzava
`sl_articolo` come `Product` con `type = service`, e il catalogo preventivi si
era riempito di 189 macchine del parco installato (Faema, Cimbali, impianti
alla spina, addolcitori) mai state a listino — 66 delle quali già presenti in
Materiali con lo stesso codice. Oggi:

- l'import **non crea più prodotti**: `resolveExistingProduct()` cerca soltanto
  a catalogo (utile per le macchine che vendiamo davvero), mentre l'articolo
  passa da `resolveOrCreateMaterial()` come i ricambi del `dettaglio[]`;
- il rapportino referenzia l'articolo in `service_reports.machine_material_id`
  (`machine_product_id` resta per i rapportini agganciati a una macchina a
  listino); chi legge l'articolo usa `ServiceReport::gestionaleArticle()`, mai
  un campo da solo;
- il macchinario referenzia l'articolo in `machine_units.material_id`, valorizzato
  dall'import quando Eureka passa matricola e articolo insieme;
- `eureka:migrate-machine-articles` (idempotente, con `--dry-run`) fa la
  migrazione dei dati storici: sposta i prodotti-articolo in Materiali,
  riaggancia i rapportini, collega i macchinari e cancella i doppioni.

Per `toGestionalePayload()` non cambia nulla: `sl_articolo.id_eureka` e
`dettaglio[].id_articolo` sono sempre stati lo stesso identificatore, ora
vengono solo letti dalla stessa tabella.
