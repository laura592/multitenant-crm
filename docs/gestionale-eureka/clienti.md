# Clienti — `GET /anagrafica/cerca`

Cerca un'anagrafica cliente. Parametri (si può passare uno o più):

- `nome` — ragione sociale (anche parziale con `&like=true`)
- `piva` — partita IVA
- `email`

Priorità di ricerca lato Eureka: prima `piva`, poi `email`, poi `nome`.

## Esempio reale (testato)

`GET /anagrafica/cerca?piva=00554810242` → cliente "Gdp Italia SRL":

```json
[{
  "id": 1,
  "rag_sociale_1": "GDP ITALIA SRL",
  "rag_sociale_2": null,
  "partita_iva": "00554810242",
  "codice_fiscale": "00554810242",
  "citta": "SAN GIUSEPPE DI CASSOLA                           ",
  "sigla_prov": "VI",
  "email": "info@gdpitalia.com",
  "nr_telefono": "0424-514008"
}]
```

Questo `id: 1` è esattamente il valore già salvato in `Customer.gestionale_code`
per questo cliente nel CRM — confermato che la corrispondenza è 1:1.

## Cose da sapere

- **Il campo `citta` arriva con spazi di riempimento in fondo** (formato a
  colonne fisse del vecchio gestionale sorgente). Se mai si importano questi
  dati, va sempre fatto un `trim()`.
- Con `nome=Alex&like=true` tornano anche varianti simili (es. "ALEX di
  SIGNORATO ALESSANDRO" oltre a "ALEX SRL") — la ricerca per nome è
  volutamente permissiva, va sempre disambiguata a vista o incrociata con
  `piva`.
- Esiste anche una ricerca solo-nome più semplice: `GET /anagrafica/clienti/(nome)`
  (non testata a fondo, stesso concetto).

## ⚠️ P.IVA/Codice Fiscale a volte sono un placeholder

Scoperto lanciando il sync automatico su dati reali (2026-07-30): su **32
clienti** il campo `partita_iva` restituito da Eureka era identico al loro
stesso `id` (es. cliente `id: 2807` → `"partita_iva": "2807"`), e su altri
il valore era chiaramente troppo corto per essere vero (`"2"`, `"22"`,
`"34"`). Sembra che Eureka usi l'id interno come riempitivo quando manca il
vero dato, invece di lasciare il campo vuoto.

**Da tenere sempre a mente in qualunque nuovo codice che legge questi
campi**: prima di fidarsi di `partita_iva`/`codice_fiscale`, controllare che
non sia identico a `id` e che sia lungo almeno 5 caratteri — vedi
`GestionaleSyncRunner::looksLikePlaceholder()` in
`app/Support/Gestionale/GestionaleSyncRunner.php`, unico punto dove oggi
facciamo questo controllo.
