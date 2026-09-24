# Le migration compattate in un file solo

Dal 24/09/2026 `database/migrations/` è **vuota**: lo schema del database sta in
`database/schema/mysql-schema.sql` (e in `sqlite-schema.sql` per i test).

Erano **158 file**, scritti in quattordici mesi. Nessuno li leggeva più, e
ricostruire il database da zero voleva dire eseguirli tutti in fila — con tre
di loro che nel frattempo si erano rotti (vedi `docs/prova-postgres.md`).

## Cosa cambia, in pratica

**In produzione, niente.** Le 159 migration risultano già eseguite: `migrate`
non trova nulla da fare, esattamente come prima. Il file di schema viene letto
solo quando la tabella `migrations` è **vuota**, cioè su un database nuovo.

**Su un database nuovo** (una macchina nuova, i test, un collega che parte da
zero) il database si costruisce in **un secondo** invece che in quaranta, e le
159 righe della tabella `migrations` vengono scritte come se fossero girate.

**Da qui in avanti** si scrivono migration nuove come sempre, in
`database/migrations/`. Quando saranno tante e vecchie, si rifà
`php artisan schema:dump --prune`.

## I dati, che lo schema non porta

Lo schema porta le tabelle, non il loro contenuto. Delle 158 migration solo
**tre** scrivevano dati, e due erano backfill (riempire un campo nuovo partendo
da uno vecchio): su un database vuoto non avrebbero nulla da spostare.

La terza invece contava: **il listino del caffè**, che entra da una migration e
non da un seeder perché `update.sh` i seeder non li lancia. Compattando è
sparito, e nove test lo hanno detto subito. È tornato come
`2026_09_24_090000_listino_caffe_iniziale`, che scrive **solo se la tabella è
vuota** — in produzione il listino c'è già, e può essere stato ritoccato dal
pannello. Ci sono anche i formati che due migration successive correggevano
(Lyrae da 1 kg, cioccolato da 500 g): tre migration diventate una riga sola.

Quello che serve a un'installazione nuova — tenant, ruoli, permessi — resta nei
**seeder**, dove deve stare.

**La regola da ricordare**: prima di compattare, cercare le migration che
scrivono dati, non solo schema. Quelle vanno riscritte come migration nuove e
idempotenti, o il database nuovo nasce vuoto dove dovrebbe nascere pieno.

## Rigenerare lo schema

```bash
# MySQL: da un database appena migrato, non dalla copia di produzione
docker compose exec -e DB_DATABASE=testing laravel.test php artisan schema:dump

# SQLite (i test)
docker compose exec laravel.test bash -lc \
  'DB_CONNECTION=sqlite DB_DATABASE=/tmp/s.sqlite php artisan migrate:fresh --force \
   && DB_CONNECTION=sqlite DB_DATABASE=/tmp/s.sqlite php artisan schema:dump'
```

## Se serve ripescare una vecchia migration

Sono nella storia di git, non sono sparite:

```bash
git log --diff-filter=D --name-only -- database/migrations | head -40
git show <commit>^:database/migrations/2026_07_09_120000_create_customers_and_quotes_tables.php
```
