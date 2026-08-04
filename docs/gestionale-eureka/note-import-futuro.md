# Note per un futuro import di massa da Eureka

Approfondimento mirato (sola lettura, solo `GET`) fatto per capire se un
futuro "importa tutte le anagrafiche/il catalogo prodotti da Eureka nel CRM"
è fattibile in blocco o va per forza fatto cliente-per-cliente/articolo-per-
articolo. Tutte le chiamate sono state fatte contro l'ambiente di produzione,
nessuna scrittura.

## 1. Due endpoint per cercare clienti per nome — non sono equivalenti

`GET /anagrafica/clienti/(nome)` (mai testato prima) e `GET
/anagrafica/cerca?nome=...&like=true` sembrano fare la stessa cosa ma si
comportano in modo molto diverso:

| | `/anagrafica/clienti/(nome)` | `/anagrafica/cerca?nome=...&like=true` |
|---|---|---|
| Tipo di match | **Prefisso** ("inizia con") | **Contains** ("contiene") |
| Campi restituiti | Solo `id_eureka`, `rag_sociale`, `citta` | Tutti (`id`, `partita_iva`, `codice_fiscale`, `citta`, `sigla_prov`, `email`, `nr_telefono`) |
| Limite risultati | Nessun cap osservato (vedi §5) | **Cap silenzioso a 50** (vedi §2) |

Esempio con `ITALIA`, stesso termine sui due endpoint:

```
GET /anagrafica/clienti/ITALIA
→ [{"id_eureka":992,"rag_sociale":"ITALIAN COFFEE SRL",...},
    {"id_eureka":993,"rag_sociale":"ITALIANA CAFFE' NUOVA TRADIZIONE",...}]
   (solo 2 risultati: e' un match sul PREFISSO del nome)

GET /anagrafica/cerca?nome=ITALIA&like=true
→ 50 risultati, tra cui "A.M.G. PIAZZA ITALIA", "ACCOR HOSPITALITY ITALIA SRL",
   "ACI - AUTOMOBILE CLUB D'ITALIA"... (contains, "Italia" ovunque nel nome)
```

Utilità pratica: `/anagrafica/clienti/(nome)` **non sostituisce** `/anagrafica/cerca`
per la ricerca puntuale (mancano piva/email/telefono, servono per
disambiguare), ma è molto più utile per un'**enumerazione in blocco per
prefisso** — vedi §5.

## 2. `/anagrafica/cerca` con termini larghissimi: cap silenzioso a 50

Provato `nome=a&like=true` (una sola lettera, che in teoria dovrebbe matchare
quasi tutti i ~2000+ clienti): tornano **esattamente 50 risultati**, sempre,
qualunque lettera si usi:

```
nome=a → 50   nome=e → 50   nome=i → 50   nome=o → 50   nome=m → 50
```

Provati anche `page=2`, `per_page=200`, `limit=200` come parametri extra:
**tutti ignorati silenziosamente**, la risposta è byte-per-byte identica alla
richiesta base (stesso primo/ultimo elemento, stessa dimensione in byte).
Non c'è alcun campo `total` o `next_page` nella risposta (è un array JSON
nudo) che segnali che i risultati sono stati troncati — **il rischio concreto
è che un futuro import basato su `/anagrafica/cerca` con termini generici
perda silenziosamente risultati oltre il 50°, senza nessun errore o avviso**.

## 3. Rate limiting: nessuno osservato

20 chiamate consecutive e rapide a `GET /crm_api/t61/tariffe` (endpoint
leggero, nessun parametro): **20/20 HTTP 200**, nessun 429, nessun
rallentamento progressivo. Tempi di risposta tra 0.34s e 1.45s, senza un
trend di peggioramento (i picchi a 0.8-1.4s sembrano jitter di rete, non
throttling — capitano random tra chiamate veloci). Non prova che non esista
un rate limit più alto (es. su migliaia di chiamate/minuto), ma per un
volume "a raffica" di decine di chiamate il server non sembra reagire.
Nessun header tipo `X-RateLimit-*` in risposta.

## 4. `/show/q/art_installati` senza `q` o con `q` invalido

```
GET /show/q/art_installati                  → HTTP 500
{"classname":"EIBNativeException","message":"...Dynamic SQL Error...
 Unexpected end of command - line 1, column 51"}

GET /show/q/art_installati?q=               → HTTP 500 (stesso errore)

GET /show/q/art_installati?q=abc            → HTTP 500
{"classname":"EIBNativeException","message":"...Column unknown\r\nABC..."}

GET /show/q/art_installati?q=999999999      → HTTP 200, []
```

Da notare per un futuro codice di import: l'endpoint **non fa validazione
dell'input** lato applicativo — costruisce la query SQL con il parametro
grezzo e lascia trapelare l'errore nativo del database Firebird (`FireDAC`)
quando `q` manca o non è un intero. Va quindi **sempre** passato un id
numerico valido (mai omesso, mai una stringa), e il codice chiamante deve
gestire un possibile `HTTP 500` con corpo JSON di errore come caso normale,
non eccezionale — non è detto che resti sempre `HTTP 200` per input strani
non ancora provati. Un id numerico ma inesistente, invece, si comporta bene:
`200` con array vuoto.

## 5. Ottenere tutto in blocco: clienti sì (con un trucco), articoli solo in parte

### Clienti — enumerazione per lettera iniziale, fattibile

`/anagrafica/clienti/(nome)` fa match sul prefisso e **non sembra avere un
cap fisso**: interrogato con ogni lettera dell'alfabeto (26 chiamate) i
conteggi sono tutti diversi e nessuno è un numero "tondo" sospetto (nessun
50/100/200 secco):

```
a:176  b:195  c:229  d:76  e:47  f:65  g:112  h:162  i:60  j:7  k:17
l:122  m:125  n:43  o:48  p:127  q:0  r:107  s:138  t:82  u:12  v:58
w:11  x:0  y:2  z:14
```

Somma: **2035 clienti** recuperati con sole 26 chiamate (una per lettera).
Massimo singolo (lettera "c") = 229, ben sopra i 50 del cap di
`/anagrafica/cerca` — quindi qui il cap, se esiste, è più alto di 229 e non
è stato raggiunto.

**Attenzione però**: questo metodo per prefisso **salta i nomi che non
iniziano con una lettera A-Z**. Esempio reale trovato: il cliente
`" 80 Voglia di Pesce  ristorante Amicizia s.a.s."` (id 2980) ha uno
**spazio iniziale** prima dell'"8" — `GET /anagrafica/clienti/8` infatti
torna vuoto (`[]`), mentre `GET /anagrafica/clienti/1` trova regolarmente
`"1994 IMMOBILIARE SRL"` (id 12). Un'anagrafica con nome che inizia per
spazio, apostrofo o altro carattere non alfanumerico **sfugge
all'enumerazione A-Z e 0-9**. Per un import completo serve quindi:
1. le 26 lettere + le 10 cifre come prefisso (36 chiamate totali);
2. un controllo di completezza a parte, ad es. confrontando gli `id`
   massimi visti con un campionamento su `/anagrafica/cerca` per individuare
   eventuali id "orfani" mai apparsi nelle 36 chiamate (nomi con prefisso
   anomalo).

### Articoli — stessa idea, ma con un cap reale a 100 che va aggirato

`GET /articoli/lista/` con codice vuoto **non dà errore**: torna comunque
risultati (primo elemento `{"id_eureka":11693,"codice":".",...}`), ma
**esattamente 100**, identici byte-per-byte a `GET /articoli/lista/%25`
(wildcard `%`) — segno che con termine vuoto/jolly il server applica un cap
fisso a 100 risultati.

Provando poi l'enumerazione per lettera come per i clienti, **la maggior
parte delle lettere sta sotto il cap** (es. `a:92, b:35, e:85`), ma **due
lettere lo toccano esattamente**: `o:100` e `r:100` — segno chiaro di
troncamento silenzioso (nessun campo `total`, nessun errore). Confermato
scendendo di un livello: interrogando tutti i prefissi a 2 caratteri
`o?` (`oa`, `ob`, ... `oz`, `o0`...`o9`) si trovano **152** articoli, ben
oltre i 100 restituiti dalla sola `o` — prova diretta che il cap troncava
risultati veri.

**Conclusione pratica**: per un import di massa del catalogo articoli,
l'enumerazione a un solo livello (lettera singola) **non basta** per i
prefissi più popolati — serve un algoritmo ricorsivo che, ogni volta che una
chiamata torna esattamente 100 risultati, approfondisce con un secondo
carattere (e così via) finché non si scende sotto soglia. Per i clienti
invece un solo livello (lettera + cifra) sembra sufficiente dato che nessuna
lettera si è avvicinata a un cap.

## 6. Riepilogo per chi implementerà l'import

| Aspetto | Scoperta | Impatto sull'import |
|---|---|---|
| Rate limit | Nessuno osservato su 20 chiamate rapide | Un import di migliaia di record via tante chiamate sembra fattibile senza throttling lato server, ma non è stato provato su volumi realmente massivi (centinaia/migliaia di chiamate) |
| `/anagrafica/cerca` | Cap silenzioso **a 50**, `page`/`per_page`/`limit` ignorati | Mai usarlo con termini larghi per un import di massa: va bene solo per ricerche mirate (nome specifico, piva) |
| `/anagrafica/clienti/(nome)` | Match per **prefisso**, nessun cap fino a 229 risultati, ma **salta nomi con prefisso non alfanumerico** | Buono per un'enumerazione di massa (36 chiamate: A-Z + 0-9), ma serve un controllo di completezza extra; dà solo 3 campi (bisogna poi arricchire con `/anagrafica/cerca` per piva/email se servono) |
| `/articoli/lista/(codice)` | Cap **reale a 100** per query, toccato da almeno 2 lettere su 26 | Serve enumerazione ricorsiva (approfondire il prefisso se il conteggio torna esattamente 100) |
| `/show/q/art_installati` | Errori SQL grezzi (HTTP 500) se `q` manca/non è un intero; pulito (`200`, `[]`) se `q` è un intero inesistente | Va sempre passato un intero valido; il chiamante deve gestire HTTP 500 come possibile risposta legittima, non solo eccezionale |

In generale: un import di massa **è fattibile con relativamente poche
chiamate** (decine, non migliaia) sia per clienti che per articoli, a patto
di non affidarsi a `/anagrafica/cerca` per liste ampie e di implementare
l'approfondimento ricorsivo del prefisso per `/articoli/lista/`. Nessun
segnale di rate limiting che lo renda rischioso nel breve termine, ma vale
la pena introdurre comunque un piccolo ritardo tra le chiamate in produzione
per prudenza, dato che non è stato provato un volume di migliaia di
richieste consecutive.
