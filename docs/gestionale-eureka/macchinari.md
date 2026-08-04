# Macchinari e matricole

## Matricole di un articolo — `GET /crm_api/m14/search?id_articolo_m10=<id>`

Dato un articolo di catalogo, elenca le matricole registrate di quel
modello. Parametri: `id_articolo_m10` (obbligatorio), `q` (filtro parziale
sul codice matricola, opzionale), `per_page` (default 25, max 100).

```json
{
  "items": [
    {"id": 8831, "matricola": "SN-2024-00871", "id_articolo_m10": 501, "note": null, "data_creazione": "2024-03-12T00:00:00"}
  ],
  "total": 1
}
```

Testato con `id_articolo_m10=123` (l'articolo collegato alla tariffa
"MAN"/manodopera, vedi [articoli-e-tariffe.md](articoli-e-tariffe.md)):
risultato vuoto, atteso — la manodopera non ha matricole.

**Aggiornamento — testato con veri articoli-macchina** (vedi
[articoli-e-tariffe.md](articoli-e-tariffe.md) per come si trovano questi
id): funziona molto bene, dati reali abbondanti. Esempi:

- `id_articolo_m10=19426` (ICON3GR) → 1 matricola: `L23000143`, con nota
  "Creata da Scheda lavoro" — cioè generata automaticamente la prima volta
  che quella matricola è stata usata in una scheda lavoro, non inserita a
  mano prima.
- `id_articolo_m10=19339` (A600FM) → 10 matricole reali, molte con nota tipo
  `"Creato da BC HOTEL CAMBRIDGE SRL - 30016 - JESOLO"` — sembra registrare
  automaticamente il cliente/luogo di prima installazione nella nota.

Questo endpoint è ora integrato nel sync automatico
(`EurekaClient::cercaMatricole()` + `GestionaleSyncRunner::proposeMachineUnitLinks()`,
vedi `app/Support/Gestionale/`): propone il collegamento tra un `MachineUnit`
(matricola nel CRM) e la matricola Eureka corrispondente, solo se il
`Product` collegato ha già un `gestionale_code` e la ricerca per quella
matricola dà **esattamente un risultato**.

## Macchinari installati presso un cliente — `GET /show/q/art_installati?q=<id_codice_f15>`

Dato l'`id` di un cliente (lo stesso restituito da `/anagrafica/cerca`),
elenca cosa gli risulta installato/consegnato. **Esempio reale** (cliente con
`id_codice_f15 = 238`, unico risultato non vuoto su un campione casuale di 32
clienti testati):

```json
[
  {
    "id_codice_f15": 238, "id": 256, "matricola": "B36414",
    "articolo": "BAV5", "desc_articolo_1": "ADDOLCITORE BAV 5 AUTOMATICO",
    "numero_doc_t23": 236, "data_documento": "2025-11-21T00:00:00.000+01:00"
  },
  {
    "id_codice_f15": 238, "id": 2662, "matricola": "CMD1012043",
    "articolo": "FABBRGHIACCIO", "desc_articolo_1": "FABBRICATORE DI GHIACCIO",
    "numero_doc_t23": 231, "data_documento": "2023-01-01T00:00:00.000+01:00"
  },
  {
    "id_codice_f15": 238, "id": 2662, "matricola": "CMG1008229",
    "articolo": "FABBRGHIACCIO", "desc_articolo_1": "FABBRICATORE DI GHIACCIO",
    "numero_doc_t23": 283, "data_documento": "2026-06-05T00:00:00.000+02:00"
  }
]
```

Campi: `id` = id dell'articolo di catalogo, `matricola` = numero di serie
della singola unità, `articolo`/`desc_articolo_1..3` = codice e descrizione,
`numero_doc_t23`/`data_documento` = riferimento al DDT di consegna.

### Cose da sapere

- **`id` non è una chiave univoca di riga.** Le ultime due righe sopra hanno
  lo stesso `id: 2662` (stesso modello "FABBRGHIACCIO") ma matricole diverse
  — è l'id dell'articolo di catalogo, condiviso da tutte le unità di quel
  modello. Non usarlo come chiave se si importano queste righe da qualche
  parte: usare `matricola`.
- **Copertura bassa su un campione piccolo, ma non su tutta l'anagrafica**:
  su un campione di 32 clienti solo 1 aveva macchinari qui censiti (aveva
  fatto pensare a un endpoint scarno con solo accessori). Sul giro completo
  su tutti i 2042 clienti collegati (primo sync reale, 2026-08-04) sono
  emerse invece **444 macchine nuove**, comprese vere macchine da caffè
  (es. "MACCHINA PER CAFFE' FRANKE A800FM", "FAEMA E98 UP 2 GRUPPI",
  "CASADIO UNDICI A/2"), non solo accessori (addolcitori, macinadosatori,
  frigo latte). L'affermazione precedente "solo accessori, mai macchine da
  caffè" era sbagliata — basata su un campione troppo piccolo. Il tipo del
  prodotto va quindi sempre scelto a vista in fase di conferma
  (`GestionaleMacchineNuoveWidget`), mai indovinato di default.

Integrato nel sync automatico (`EurekaClient::articoliInstallati()` +
`GestionaleSyncRunner::proposeInstalledMachines()`, vedi
`app/Support/Gestionale/`): per ogni cliente collegato, ogni `matricola` non
ancora presente in `MachineUnit` (di nessun tenant — la matricola è univoca)
genera una riga in `MachineUnitProposal`, mai una `MachineUnit` scritta da
sola. Da lì si conferma o si scarta a mano nella pagina "Sync Eureka"
(`GestionaleMacchineNuoveWidget`) — confermare crea davvero la `MachineUnit`
(e il `Product`, se non ce n'era già uno con lo stesso `gestionale_code`);
scartare marca `dismissed_at` invece di cancellare la riga, altrimenti la
proposta ricomparirebbe al giro successivo.
