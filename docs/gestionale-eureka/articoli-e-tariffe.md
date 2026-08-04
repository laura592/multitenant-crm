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

`id_eureka` è il valore da salvare in `Product.gestionale_code` per collegare
un prodotto del nostro catalogo all'articolo Eureka (serve per `sl_articolo`
nelle schede lavoro).

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

### ⚠️ Discrepanza da chiarire col fornitore

La documentazione fornita da Eureka dice testualmente: *"Ad oggi ALEX srl non
utilizza le tariffe: nella scheda lavoro indicare **sempre** la tariffa con
`id` = 2 (**"FISSA"**)"*.

Ma i dati reali sopra mostrano che l'id 2 è **"MAN" / "MANODOPERA STD"** — non
esiste nessuna tariffa chiamata "FISSA" tra quelle attive. Il codice
(`ServiceReport::toGestionalePayload()`) usa comunque l'id 2 come da
istruzioni del fornitore, con un commento che segnala la cosa.

**Aggiornamento — corroborato da dati reali**: leggendo lo storico vero delle
schede lavoro (`GET /schedelavoro/?data_da=...&data_a=...`, centinaia di
documenti reali fino al 2026-07-23) **ogni singolo documento aveva
`"id_tariffa_t61": 2`**, nessuna eccezione. Quindi l'id 2 è davvero quello
sempre usato in pratica — la questione "FISSA" vs "MAN" resta solo
un'incongruenza di nome nella documentazione del fornitore, non un rischio
concreto per l'id da usare. Resta comunque buona norma chiederlo a Eureka se
mai si aggiungono altre tariffe in futuro.
