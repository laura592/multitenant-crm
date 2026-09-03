# Deploy e cutover in produzione (hosting condiviso cPanel)

> **Storico.** Questo runbook descrive il primo deploy su cPanel, cioe' come
> e' nata la produzione attuale. Per portare CRM e sito su VPS (lasciando la
> posta su Serverplan) vedi `docs/deploy-vps.md`, che lo sostituisce
> operativamente.

Runbook per il primo deploy reale di `multitenant-crm`, in sostituzione della
vecchia app "app_preventivi_vg". Vedi `docs/architecture.md` §8 per il piano
di migrazione dati e `docs/checklist-rilascio.md` per il gate generico di
qualsiasi rilascio (da eseguire comunque, in aggiunta a questo). Server:
`ssh -l nbalexca -p 10223 alexcaffe.com` — hosting condiviso cPanel, niente
root/Docker/supervisor.

**Prima di iniziare**: `git pull` dell'ultima versione di questo repo — questo
runbook presuppone che il fix di idempotenza di `import:legacy` (legacy_id +
updateOrCreate, vedi `app/Console/Commands/ImportLegacyData.php`) sia già
applicato. Senza quel fix rilanciare l'import una seconda volta duplica tutto.

## Fase 1 — Ricognizione (sola lettura, nessuna modifica)

Copia/incolla via SSH, riportami l'output:

```bash
ssh -l nbalexca -p 10223 alexcaffe.com
echo "--- home ---"; ls -la ~
echo "--- domini (cerca preventivi-vg.it e alexcaffe.com) ---"; ls ~/domains 2>/dev/null; ls ~/public_html 2>/dev/null
echo "--- PHP disponibile ---"; php -v; which php
for v in php8.4 php84 ea-php84 php8.3 ea-php83; do command -v $v && $v -v; done
echo "--- composer ---"; which composer; composer --version 2>/dev/null
echo "--- git ---"; which git; git --version
echo "--- node/npm (per la build assets, opzionale) ---"; which node npm; node -v 2>/dev/null
echo "--- cron esistenti (per capire come gira oggi la vecchia app) ---"; crontab -l
echo "--- spazio disco ---"; df -h ~
```

Cose da confermare guardando l'output prima di andare avanti:
- `preventivi-vg.it` è un dominio/sottodominio sullo **stesso account** di
  `alexcaffe.com` (non un hosting separato) — altrimenti la Fase 2 va rifatta
  sull'account giusto.
- Una versione PHP **8.4** (o quella richiesta da `composer.json` del repo)
  è disponibile, anche se non è quella attiva di default (su cPanel si
  seleziona da "MultiPHP Manager", non da riga di comando).
- `composer` e `git` esistono in shell (molto comune su cPanel, ma non
  garantito da ogni provider).

## Fase 2 — Setup database e codice (ambiente separato, non ancora pubblico)

1. **Database**: da cPanel → "MySQL Databases", crea un nuovo DB (es.
   `nbalexca_multitenant_crm`) e un utente dedicato con tutti i privilegi su
   quel DB soltanto. Non riusare il database della vecchia app.
2. **Codice**: in una cartella *fuori* dalla document root pubblica per ora
   (es. `~/multitenant-crm`, non `~/public_html`):
   ```bash
   git clone https://github.com/laura592/multitenant-crm.git ~/multitenant-crm
   cd ~/multitenant-crm
   composer install --no-dev --optimize-autoloader
   # Se node/npm non sono disponibili sull'host: build in locale
   # (npm run build) e carica solo la cartella public/build via git/SFTP,
   # saltando questo comando.
   npm ci && npm run build
   cp .env.production.example .env
   # compila .env con i valori reali (vedi commenti nel file): DB_*, MAIL_*,
   # TENANT_DEFAULT_*, poi:
   php artisan key:generate
   php artisan storage:link
   ```
3. **Collegare la document root pubblica**: la document root del dominio (o
   di un sottodominio/percorso di test, es. `test.preventivi-vg.it`) deve
   puntare a `~/multitenant-crm/public`, non alla cartella del repo — si
   configura da cPanel → "Domains". In questa fase punta **solo** un dominio
   di test, non quello di produzione: la vecchia app resta attiva e pubblica
   fino alla Fase 4.
4. **Cron** (cPanel → "Cron Jobs"), due righe, ogni minuto:
   ```
   * * * * * cd /home/nbalexca/multitenant-crm && php artisan schedule:run >> /dev/null 2>&1
   * * * * * cd /home/nbalexca/multitenant-crm && php artisan queue:work --stop-when-empty >> /dev/null 2>&1
   ```
   (aggiusta il path assoluto se la cartella non è `~/multitenant-crm`).
5. Verifica che l'app risponda sul dominio/percorso di test prima di
   continuare (login, dashboard) — con il DB ancora vuoto va bene vedere
   l'app senza dati, serve solo confermare che gira.

## Fase 3 — Migrazione dati reali (finestra di manutenzione)

Fallo in un momento di basso traffico, avvisando prima chi usa la vecchia app.

```bash
# 1. Backup fresco del DB della vecchia app (da cPanel -> phpMyAdmin -> Esporta,
#    oppure mysqldump se hai accesso), SUBITO PRIMA di questo passo.

# 2. Vecchia app in manutenzione (se ha un flag/pagina di manutenzione propria,
#    attivalo; altrimenti valuta di rinominare temporaneamente il suo file
#    di entry point per bloccare nuove scritture).

# 3. Importa il dump fresco della vecchia app nel database "legacy"
#    (nome in LEGACY_DB_DATABASE del tuo .env), da phpMyAdmin o:
mysql -u nbalexca_crm -p nbalexca_app_preventivi_fresh < dump_fresco.sql

cd ~/multitenant-crm
php artisan migrate --force
```

> ⚠️ **`import:legacy` è stato rimosso dal repo il 2026-08-10** (comando
> considerato esaurito, dati già migrati in locale). Questo passo di Fase 3
> non è più eseguibile così com'è: prima del prossimo deploy reale va deciso
> come portare i dati della vecchia app in produzione — ripristinare il
> comando da `git log` (`app/Console/Commands/ImportLegacyData.php`,
> rimosso in `f01ea6b`), oppure usare l'approccio di confronto/import
> selettivo da dump già rodato in questa sessione (vedi memoria di
> conversazione "Local dev DB seeded from production dump").

**Verifica manuale prima di andare avanti** — checklist minima di cutover
(in aggiunta a `docs/checklist-rilascio.md` §3, che resta valida):

- [ ] Conteggi ragionevoli: clienti/preventivi importati vicini ai numeri
      reali della vecchia app (visibili nel backup appena preso).
- [ ] Login funziona con le utenze reali (password legacy, bcrypt, restano
      valide - vedi `ImportLegacyData::importUsers`).
- [ ] Un preventivo reale a caso: dati cliente, righe, totali corrispondono
      a quanto risultava nella vecchia app.
- [ ] Generazione PDF di un preventivo reale funziona.
- [ ] Invio email di test funziona davvero (MAIL_MAILER=smtp, non log) —
      manda un'email di prova a te stessa prima di fidarti.
- [ ] Scoping multi-tenant: vedi solo i dati del tenant "alex", nessun dato
      di altri tenant di test/sviluppo finito per errore nel dump.

## Fase 4 — Switch (il vero "andare live") e rollback

1. cPanel → "Domains": cambia la document root di `preventivi-vg.it` da
   quella della vecchia app a `~/multitenant-crm/public`.
2. Verifica immediatamente il dominio reale (non quello di test).
3. **Rollback se qualcosa non torna**: ripunta la document root alla cartella
   della vecchia app — cambio quasi istantaneo, nessun dato perso (la vecchia
   app e il suo DB restano intatti, non sono stati toccati in Fase 3 se non
   in lettura per il dump).
4. Tenere vecchia app + DB intatti (non serviti) per un periodo di sicurezza
   prima di rimuoverli definitivamente.
