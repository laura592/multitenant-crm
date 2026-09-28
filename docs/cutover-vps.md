# Il cutover: spostare app.alexcaffe.com sulla VPS

Procedura della sera del trasloco, da fare in ordine. Prepararla e' gia'
fatto (`docs/deploy-vps.md`): qui c'e' solo quello che si fa **il giorno in
cui il CRM cambia macchina**.

Dura circa un'ora, di cui mezza di attesa. Serve una finestra in cui non
lavora nessuno.

Gli indirizzi:

| Dove | Come ci si entra |
|---|---|
| cPanel (produzione di oggi) | `ssh -p 10223 nbalexca@alexcaffe.com`, PHP in `/opt/cpanel/ea-php84/root/usr/bin/php` |
| VPS, utente dell'app | `ssh -i ~/.ssh/crm-ovh deploy@51.255.35.205` — niente sudo, ed e' voluto |
| VPS, utente di sistema | `ssh crm` (entra come `ubuntu`, ha sudo) |
| DNS | cPanel -> Zone Editor -> `alexcaffe.com` (i nameserver sono `ns1/ns2.cmshigh.com`) |

Da una sessione `ubuntu` si esegue un comando come `deploy` senza
riconnettersi: `sudo -u deploy bash -lc '...'`.

## Cosa non si tocca, mai

La posta resta su Serverplan. Nel Zone Editor si modifica **un solo record**,
`app`. Restano fermi:

- il record `A` di **`alexcaffe.com`** (nudo) -> `86.107.36.173`
- il record `A` di **`mail`** -> `86.107.36.173`
- il record **`MX`** -> `10 mail.alexcaffe.com`
- il **TXT SPF** `v=spf1 ip4:86.107.36.173 +a +mx ~all`
- il **DKIM** (`default._domainkey`)

L'SPF contiene `+a`, cioe' "autorizzo l'IP del record A del dominio". Finche'
`alexcaffe.com` nudo resta su `86.107.36.173` va tutto bene. **Se un giorno
si sposta anche quello, l'SPF va riscritto prima**, o la posta comincia a
finire in spam senza nessun errore visibile.

`app.alexcaffe.com` non e' coperto da `+a` e non compare da nessuna parte
nella configurazione della posta: spostarlo non tocca le email.

## Passo 0 — Abbassare il TTL (da fare con un'ora di anticipo)

Oggi `app.alexcaffe.com` ha TTL 3600: dopo il cambio, un'ora di provider che
continuano a mandare la gente su cPanel — e un'ora per tornare indietro se
qualcosa non va.

cPanel -> Zone Editor -> `alexcaffe.com` -> Manage -> record `A` di `app`:
**lascia l'IP com'e'**, cambia solo il TTL da `3600` a `300`. Salva.

Poi aspetta un'ora. Nel frattempo non e' cambiato niente per nessuno.

## Passo 1 — Spegnere il CRM su cPanel

### Prima: c'e' qualcuno che sta lavorando?

Le sessioni stanno nel database, quindi questa non e' una stima: e' la lista
di chi e' collegato davvero.

```bash
ssh -p 10223 nbalexca@alexcaffe.com
cd ~/multitenant-crm
PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)
mysql -u nbalexca_crm -p"$PW" nbalexca_multitenant_crm -e "
select u.name, u.email, from_unixtime(s.last_activity) ultimo_click
from sessions s join users u on u.id = s.user_id
where s.last_activity > unix_timestamp(now() - interval 30 minute)
order by s.last_activity desc;"
```

Vuota: si procede. Se invece c'e' un tecnico, aspetta: l'unica cosa che il
cutover puo' portare via e' **il rapportino che qualcuno sta compilando
nell'istante in cui spegni**, cioe' un form non ancora salvato. Tutto quello
che e' gia' salvato finisce nel dump del Passo 2, che si fa apposta *dopo*
aver spento.

Segnati il punto di partenza, serve al Passo 8:

```bash
mysql -u nbalexca_crm -p"$PW" nbalexca_multitenant_crm -e "
select count(*) rapportini, max(created_at) ultimo from service_reports;
select count(*) lavaggi from lavaggi;
select count(*) clienti from customers;"
```

### Poi: il cron

**Prima il cron, e non e' un dettaglio.** Se i due scheduler girano insieme,
tutti e due mandano documenti a Eureka: escono doppi, e su Eureka non si
cancellano.

```bash
ssh -p 10223 nbalexca@alexcaffe.com
crontab -l > ~/crontab-prima-del-cutover.txt
crontab -l | sed 's|^\(\* \* \* \* \* cd /home/nbalexca/multitenant-crm.*\)|#\1|' | crontab -
crontab -l    # la riga del CRM deve iniziare con #
```

Poi metti l'app vecchia in manutenzione, cosi' chi ha ancora il vecchio IP in
cache vede una pagina di servizio invece di scrivere su un database che stai
per abbandonare:

```bash
cd ~/multitenant-crm
/opt/cpanel/ea-php84/root/usr/bin/php artisan down
```

Da questo momento il CRM e' fermo. Il cronometro parte qui.

## Passo 2 — Portare via dati e documenti

Il dump si fa **adesso**, non prima: deve contenere anche l'ultimo rapportino
scritto oggi.

Sempre su cPanel:

```bash
cd ~/multitenant-crm
PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)
mysqldump --single-transaction --routines --no-tablespaces --default-character-set=utf8mb4 \
  -u nbalexca_crm -p"$PW" nbalexca_multitenant_crm | gzip > ~/cutover.sql.gz
gunzip -c ~/cutover.sql.gz | tail -1    # deve dire "-- Dump completed on ..."
exit
```

**`--no-tablespaces` non e' opzionale**: su hosting condiviso l'utente del
database non ha il privilegio `PROCESS`, e senza quel flag mysqldump si ferma
con `Access denied ... when trying to dump tablespaces`.

E il controllo si fa sull'ultima riga, non sulla dimensione: il dump
compresso sta sui 2,6 MB (una ventina di MB di SQL), quindi un file troncato
a meta' sembrerebbe comunque plausibile. `-- Dump completed on ...` c'e' solo
se mysqldump e' arrivato in fondo.

Dal Mac, il database e i documenti gia' generati (PDF, firme, allegati):

```bash
scp -P 10223 nbalexca@alexcaffe.com:~/cutover.sql.gz ~/Downloads/
scp -i ~/.ssh/crm-ovh ~/Downloads/cutover.sql.gz deploy@51.255.35.205:/tmp/

rsync -avz -e "ssh -p 10223" \
  nbalexca@alexcaffe.com:~/multitenant-crm/storage/app/ ~/Downloads/storage-app/
rsync -avz -e "ssh -i ~/.ssh/crm-ovh" \
  ~/Downloads/storage-app/ deploy@51.255.35.205:/var/www/multitenant-crm/storage/app/
```

Su `storage/app` **niente `--delete`**: aggiunge e aggiorna, non porta via
nulla.

## Passo 3 — Caricare sulla VPS

```bash
ssh -i ~/.ssh/crm-ovh deploy@51.255.35.205
cd /var/www/multitenant-crm
PW=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)

# rete di sicurezza: il database che c'e' adesso sulla VPS
mysqldump --single-transaction -u crm -p"$PW" crm | gzip > ~/crm-vps-prima-del-cutover.sql.gz

mysql -u crm -p"$PW" -e "DROP DATABASE crm; CREATE DATABASE crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c /tmp/cutover.sql.gz | mysql -u crm -p"$PW" --default-character-set=utf8mb4 crm
php8.5 artisan migrate --force
php8.5 artisan optimize:clear
```

Controlla che i numeri siano **identici** a quelli segnati al Passo 1:

```bash
mysql -u crm -p"$PW" crm -e "
select count(*) rapportini, max(created_at) ultimo from service_reports;
select count(*) lavaggi from lavaggi;
select count(*) clienti from customers;"
```

Se coincidono non e' rimasto indietro niente. Se non coincidono **fermati
qui**: cPanel e' ancora intatto e il DNS non l'hai toccato, quindi non e'
successo nulla di irreparabile — rifai il dump.

### Il fuso orario, prima di proseguire

Le colonne `created_at` sono di tipo `timestamp`: MySQL le tiene in UTC e le
converte secondo il fuso del **sistema**, e Laravel non imposta niente sulla
connessione. cPanel e' su ora italiana, una VPS appena installata e' su UTC —
e cosi' tutto lo storico si legge due ore indietro.

```bash
ssh crm
sudo timedatectl set-timezone Europe/Rome
sudo systemctl restart mysql          # il fuso SYSTEM lo legge all'avvio
sudo systemctl restart php8.5-fpm
```

La prova: l'ultimo rapportino deve avere lo stesso orario che aveva su cPanel.

```bash
mysql -u crm -p"$PW" crm -e "select number, created_at from service_reports order by created_at desc limit 3;"
```

## Passo 4 — Il puntamento

Ora, e non prima: il certificato del passo dopo funziona solo se il dominio
gia' arriva sulla VPS.

cPanel -> Zone Editor -> `alexcaffe.com` -> Manage -> record `A` di `app`:

```
app.alexcaffe.com    A    51.255.35.205    TTL 300
```

**Solo questo record.** Salva e controlla dal Mac:

```bash
dig +short app.alexcaffe.com @ns1.cmshigh.com   # deve dire 51.255.35.205 subito
dig +short app.alexcaffe.com                    # il tuo provider: entro 5 minuti
```

Il primo interroga il server autoritativo e risponde giusto all'istante; il
secondo passa dalla cache del tuo operatore e puo' metterci qualche minuto.
Se il primo e' giusto, il DNS e' fatto: il resto e' solo attesa.

## Passo 5 — Il certificato

nginx oggi risponde a qualsiasi nome (`server_name _;`). Per il certificato
gli serve il nome vero:

```bash
ssh crm
sudo sed -i 's/server_name _;/server_name app.alexcaffe.com;/' /etc/nginx/sites-available/crm
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d app.alexcaffe.com
```

Alla domanda sul redirect scegli **"Redirect"**: da qui in poi `http://` porta
da solo su `https://`.

## Passo 6 — Il `.env` di produzione

**Dopo** il certificato, non prima: il file ha `SESSION_SECURE_COOKIE=true`,
e senza https il cookie di sessione non viene accettato — la pagina di login
rimanda alla pagina di login, all'infinito, senza dire perche'.

Dal Mac:

```bash
scp -i ~/.ssh/crm-ovh ~/Downloads/env-produzione.txt \
  deploy@51.255.35.205:/var/www/multitenant-crm/.env
```

Poi sulla VPS, come `deploy`:

```bash
cd /var/www/multitenant-crm
php8.5 artisan optimize:clear
php8.5 artisan config:cache
php8.5 artisan about | head -20
```

Da questo momento **Eureka non e' piu' in sola lettura e la posta va ai
destinatari veri**: e' il punto in cui la VPS smette di essere una prova.

## Passo 7 — Cron e coda sulla VPS

```bash
crontab -e
```

Una riga, con il percorso assoluto del binario:

```cron
* * * * * cd /var/www/multitenant-crm && /usr/bin/php8.5 artisan schedule:run >> /dev/null 2>&1
```

La coda per ora resta dov'e': la riga `queue:work` dentro
`routes/console.php` la fa girare lo scheduler, come su cPanel. Funziona.
Il worker persistente sotto supervisor (`docs/vps-cron-e-sync.md` §2) e' un
miglioramento da fare **dopo**, a trasloco assestato, e richiede di togliere
quella riga dal codice: due worker sulla stessa coda si rubano i job e
raddoppiano le chiamate a Eureka.

## Passo 8 — Le verifiche

```bash
php8.5 artisan schedule:list      # gli otto lavori Eureka, agli orari giusti
php8.5 artisan queue:failed       # vuoto
tail -n 50 storage/logs/laravel-$(date +%F).log
```

E dal browser, su `https://app.alexcaffe.com`:

- [ ] il lucchetto e' verde e il certificato dice `app.alexcaffe.com`
- [ ] il login funziona e la dashboard si carica
- [ ] apri un rapportino recente: numero, cliente e righe sono quelli di oggi
- [ ] scarica il PDF di un rapportino gia' firmato — verifica che le firme
      siano arrivate con `storage/app`
- [ ] Partite aperte e Fatture mostrano dati, non una tabella vuota

La mattina dopo, il controllo che conta: **il cron ha girato?**

```bash
tail -n 100 storage/logs/laravel-$(date +%F).log | grep -i eureka
```

Se alle 03:00 la sincronizzazione anagrafiche e' partita, il trasloco e'
finito davvero.

## Se qualcosa va storto

Fino al Passo 6 il ritorno indietro costa cinque minuti, perche' il TTL e'
300 e cPanel e' ancora intatto:

1. Zone Editor: record `A` di `app` -> `86.107.36.173`
2. su cPanel: `cd ~/multitenant-crm && /opt/cpanel/ea-php84/root/usr/bin/php artisan up`
3. rimetti il cron: `crontab ~/crontab-prima-del-cutover.txt`

L'unica cosa che **non** torna indietro sono i documenti mandati a Eureka
dopo il Passo 6. E' il motivo per cui `EUREKA_SOLA_LETTURA` si disattiva per
ultimo, a verifiche fatte.

## Dopo, con calma

- il worker sotto supervisor al posto della riga nello scheduler
- spegnere per davvero il CRM su cPanel (per ora resta li', fermo e in
  manutenzione: e' la rete di sicurezza)
- il sito WordPress, che e' un trasloco a se' (`docs/deploy-vps.md` Fase 4)
