# Provare Postgres sul CRM — cosa costa davvero

> Ramo `prova-postgres`. Non si fonde su `main` finché non c'è una ragione per
> cambiare motore: qui si misura, non si decide.

Domanda di partenza (24/09/2026): *conviene passare da MySQL a Postgres?*
La risposta a voce era "no, non adesso". Questo ramo serve a dare dei numeri a
quel no, invece di lasciarlo alla sensazione.

## Come si prova

Un servizio Postgres accanto a MySQL in `docker-compose.yml` — i due non si
toccano, il database di lavoro (copia della produzione) resta dov'è:

```bash
WWWUSER=$(id -u) WWWGROUP=$(id -g) docker compose up -d pgsql
docker compose exec -e DB_CONNECTION=pgsql -e DB_HOST=pgsql -e DB_PORT=5432 \
  laravel.test php artisan migrate:fresh --force
```

## Cosa si è rotto, in ordine

Su MySQL la stessa catena di 158 migration passa intera. Su Postgres si è
fermata tre volte nelle prime 46.

**1. Chiave esterna su sé stessa dentro la `CREATE TABLE`**
`quote_products.parent_quote_product_id` referenzia `quote_products.id` nella
stessa create: MySQL la accetta, Postgres pretende che la primary key esista
già. Risolta spostando la foreign key in una `Schema::table()` dopo la create —
correzione portabile, va bene per tutti e due i motori.

**2. Una migration che chiama un comando artisan che non esiste più**
`products:migrate-compatibilities-to-slots` è stato tolto dal progetto a
conversione avvenuta. Non è un problema di Postgres: è un problema di chiunque
ricrei il database da zero, e nessuno se n'era accorto perché in locale si
riparte sempre da un dump di produzione. Ora la chiamata c'è solo se il comando
c'è.

**3. Un backfill che usa il MODELLO invece della tabella**
`Tenant::where('slug', ...)` porta con sé il codice di oggi — compreso il soft
delete aggiunto mesi dopo, che aggiunge `where deleted_at is null` su una
colonna che a quel punto della storia non esiste. Riscritto con `DB::table()`.

**4. `ALTER TABLE ... enum(...)` in SQL grezzo** — dove si è fermata.

## Quanto lavoro resta

| | |
|---|---|
| Migration totali | 158 |
| Passate su Postgres prima di fermarsi | 46 |
| Migration con `->enum(...)` | 16 |
| Migration con `DB::statement` o `MODIFY COLUMN` | 4 |

Gli enum sono il grosso: su MySQL sono un tipo di colonna, su Postgres si fanno
con un `CHECK` o con un tipo dedicato, e ogni `ALTER` scritto a mano va
riscritto.

E questo è solo lo **schema**. Restano fuori dal conto:

- **50 query grezze in 29 file** (`whereRaw`, `selectRaw`, `orderByRaw`), da
  rileggere una per una;
- **i confronti fra stringhe**: MySQL ignora maiuscole e minuscole, Postgres no.
  È il rischio peggiore perché non dà errore: una ricerca per ragione sociale
  semplicemente smette di trovare, e te ne accorgi settimane dopo;
- le colonne JSON, che si interrogano in modo diverso;
- il travaso dei dati veri e un giro completo di collaudo.

## Cosa se ne ricava comunque

Due correzioni di questo ramo valgono anche su MySQL e andrebbero portate su
`main` a prescindere: la migration che chiama un comando inesistente e il
backfill che usa il modello. Oggi `php artisan migrate:fresh` su un database
vuoto non arriva in fondo, e questo prima o poi morde — il giorno che si
allestisce una macchina nuova, per esempio.

## Conclusione, per ora

Il motore non è il collo di bottiglia: il database del CRM è ~20 MB e il tempo
se ne va nelle chiamate a Eureka. Postgres si giustificherebbe se le analisi
contabili diventassero pesanti o servisse una ricerca testuale seria. Fino ad
allora questo ramo resta qui, come misura di quanto costerebbe.
