# Le migration, otto invece di centocinquantotto

Fino al 24/09/2026 `database/migrations/` conteneva **158 file**, scritti uno
alla volta in quattordici mesi: crea la tabella, aggiungi una colonna, cambia
un enum, aggiungine un'altra. Per sapere com'era fatta `service_reports`
bisognava leggerne una dozzina in ordine cronologico.

Ora sono **otto, una per argomento**, più quella del listino caffè:

| File | Cosa contiene |
|---|---|
| `000100_fondamenta` | utenti, sessioni, code, tenant, ruoli e permessi, registro attività |
| `000200_anagrafiche` | clienti, fornitori, metodi di pagamento, mezzi |
| `000300_catalogo` | prodotti, famiglie, listini, materiali e ordini materiali |
| `000400_preventivi` | preventivi, gruppi, risposte del cliente, richieste informazioni |
| `000500_interventi` | rapportini, macchine, posizionamenti, lavaggi, manutenzioni, scadenze |
| `000600_gestionale` | Eureka: esecuzioni, fatture, partite aperte, saldi, contabilità |
| `000700_offerte_caffe` | listino caffè e documenti d'offerta |
| `000800_personale` | ore lavorate e richieste di ferie |

Una tabella si cerca per argomento, non per data.

## Come sono state scritte

Non a mano: uno script ha riletto lo schema dal database vero (colonne, tipi,
indici, chiavi esterne) e ha scritto le otto migration. Poi si è costruito un
database da zero con quelle e si è confrontato con l'originale:

| | riferimento | ricostruito |
|---|---|---|
| tabelle | 68 | 68 |
| colonne | 783 | 783 |
| chiavi esterne | 121 | 121 |
| indici | 246 | 246 |
| colonne con tipo diverso | — | 0 |

Identico. Lo script è servito una volta sola e non è rimasto nel progetto.

## Cosa cambia in produzione

**Niente.** Ogni tabella si crea solo se non esiste già (`Schema::hasTable`), e
ogni chiave esterna solo se non c'è. Al primo `./update.sh` le otto migration
risultano nuove, girano, e **non fanno niente**: le tabelle ci sono tutte. Il
loro nome finisce nella tabella `migrations` e da lì in poi non si guardano
più.

Le 158 vecchie restano nella storia di git:

```bash
git log --diff-filter=D --name-only -- database/migrations | head -40
git show <commit>^:database/migrations/2026_07_09_120000_create_customers_and_quotes_tables.php
```

## I dati, che lo schema non porta

Delle 158 solo **tre** scrivevano dati, e due erano backfill (riempire un campo
nuovo partendo da uno vecchio): su un database vuoto non hanno niente da fare.

La terza contava: **il listino del caffè**, che entra da una migration e non da
un seeder perché `update.sh` i seeder non li lancia. È
`2026_09_24_090000_listino_caffe_iniziale`, e scrive **solo se la tabella è
vuota** — in produzione il listino c'è già e può essere stato ritoccato dal
pannello. Dentro ci sono anche i formati che due migration successive
correggevano (Lyrae da 1 kg, cioccolato da 500 g): tre migration diventate una.

**La regola da ricordare**: prima di compattare, cercare le migration che
scrivono dati e non solo schema. Quelle vanno riscritte idempotenti, o il
database nuovo nasce vuoto dove dovrebbe nascere pieno. Qui se ne sono accorti
nove test.

## Da qui in avanti

Le migration nuove si scrivono come sempre, una per modifica. Quando saranno di
nuovo tante, si rifà questo giro: si rilegge lo schema, si riscrivono i gruppi,
si verifica col confronto qui sopra.
