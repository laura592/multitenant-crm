# Migrazione da hosting condiviso a VPS (posta esclusa)

Runbook per portare **CRM e sito** da Serverplan (cPanel, `86.107.36.173`) a
una VPS, **lasciando la posta dov'è**. Sostituisce operativamente
`docs/deploy-cutover.md`, che descrive il primo deploy su cPanel e resta come
storia di com'è nata la produzione attuale. La parte periodica (cron, coda,
sync Eureka) è in `docs/vps-cron-e-sync.md` e non viene ripetuta qui.

Situazione di partenza, verificata il 2026-09-03:

| Cosa | Dove | Destino |
|---|---|---|
| CRM `app.alexcaffe.com` | cPanel, Apache | → VPS |
| Sito WordPress `www.alexcaffe.com` | cPanel, dietro WAF BitNinja | → VPS, **dopo** il CRM |
| Caselle `@alexcaffe.com` | cPanel (`mail.alexcaffe.com`) | **resta dov'è** |
| DNS autoritativo | `ns1/ns2.cmshigh.com` (Serverplan) | resta lì, si editano i record |

Macchina scelta (ordine del 03/09/2026): OVH **VPS-1** — 2 vCore, 4 GB RAM,
40 GB NVMe, impegno 12 mesi, immagine **Ubuntu 26.04 LTS**, piu' le opzioni
Snapshot e Automated Backup Premium. 62,52 €/anno.

La VPS-2 (8 GB) sarebbe stata piu' comoda ma non era ordinabile in un
datacenter UE, e la sede vince sulla RAM: il datacenter non si cambia mai piu',
mentre l'upgrade a un modello superiore mantiene IP, disco e dati e costa un
riavvio. Se lo swap lavora tutti i giorni (`free -h`, `vmstat 1 5`), si sale.

Il disco non e' un vincolo: il DB del CRM e' ~42 MB e `storage/app` ~43 MB.
E `npm run build` si puo' lasciare sul server — la build di questo progetto e'
58 moduli per 80 KB di output, qualche centinaio di MB di Node, non il picco
da 2 GB che si teme di solito.

**Datacenter: Gravelines** (Francia). Si sceglie all'ordine e non si cambia
dopo: spostare la VPS significa rifarla e rifare il cutover. Fra le sedi UE la latenza
dall'Italia varia di una decina di millisecondi, irrilevante qui: si sceglie
sulla capacità del sito, e Gravelines è il piu' grande di OVH. Strasburgo si
evita — a marzo 2021 un incendio ha distrutto l'edificio SBG2 e danneggiato
SBG1; il sito e' stato ricostruito, ma a parita' di prezzo non c'e' motivo di
prendere proprio quello. Il Regno Unito (Erith) è fuori dall'UE,
e qui dentro finiscono anagrafiche clienti, rapportini con i nominativi dei
tecnici e lo scadenzario — trasferirli fuori UE si regge sulla decisione di
adeguatezza concessa al Regno Unito, rinnovata a termine e periodicamente in
revisione. Restare in UE toglie la domanda di mezzo, allo stesso prezzo.

---

## Fase 0 — Cosa non si tocca, mai

Questi record restano **identici** dal primo all'ultimo passo. Se in un
qualsiasi momento ti trovi a modificarne uno, fermati: stai per spegnere la
posta aziendale.

```
MX      alexcaffe.com          10 mail.alexcaffe.com
A       mail.alexcaffe.com     86.107.36.173
A       webmail.alexcaffe.com  86.107.36.173
TXT     alexcaffe.com          v=spf1 ip4:86.107.36.173 +a +mx ~all
TXT     default._domainkey     v=DKIM1; k=rsa; p=MIIBIjANBgkq...
TXT     _dmarc                 v=DMARC1; p=none;
```

**L'account Serverplan resta attivo e pagato.** Serve per le caselle. Chiedi
al supporto se esiste un profilo solo-posta più economico una volta tolti
sito e CRM; se non c'è, tieni il piano attuale — è comunque meno rischioso
che migrare la posta insieme a tutto il resto.

### La trappola cPanel del "Local Mail Exchanger"

Quando l'`A` del dominio punterà altrove, cPanel può decidere da solo che la
posta di `alexcaffe.com` è ormai remota e **smettere di consegnarla in
locale**. Le email arrivano al server e vengono rimbalzate.

Prima del cutover, in cPanel → **Email Routing** (o *Instradamento email*),
seleziona `alexcaffe.com` e imposta esplicitamente **Local Mail Exchanger**.
Non lasciarlo su "Automatic Detection". Riverificalo dopo il cambio DNS.

### La sfumatura del `+a` nell'SPF

L'SPF contiene `+a`, cioè "l'IP dell'record A di alexcaffe.com può spedire".
Quando quell'`A` diventerà la VPS, la VPS risulterà autorizzata a spedire
senza che nessuno l'abbia deciso. Non è dannoso, ma significa che un
`mail()` accidentale dalla VPS passerebbe l'SPF pur avendo reputazione IP
zero, finendo in spam a nome tuo. Per questo **sulla VPS non si installa
nessun MTA locale** (niente postfix/sendmail): tutto esce via SMTP
autenticato verso `mail.alexcaffe.com`.

---

## Fase 1 — La VPS, prima che sappia di esistere

> **Aggiornato il 24/09/2026, dopo averci guardato dentro.** Due cose che
> questo runbook dava per scontate non reggono piu':
>
> 1. **PHP 8.4 da pacchetto non esiste su Ubuntu 26.04.** Il PPA ondrej non
>    copre questa release (l'ultima supportata e' `noble`, la 24.04), e
>    l'archivio di 26.04 porta **PHP 8.5**. Le due strade erano reinstallare
>    il sistema con la 24.04, oppure portare il progetto a 8.5: scelta la
>    seconda. L'unico pacchetto che teneva fermo il progetto era
>    `pxlrbt/filament-excel` (vedi commit `d7e666b`), ora tolto.
> 2. **`deploy` non ha sudo utilizzabile, ed e' voluto.** L'utente esiste,
>    possiede i file dell'app ed e' nel gruppo sudo, ma essendo creato senza
>    password non puo' usarlo. Le operazioni di sistema si fanno con
>    `ubuntu`, che sull'immagine OVH ha gia' sudo senza password. Un utente
>    che fa girare un'applicazione esposta su internet non ha motivo di
>    poter diventare root.

Stato al 24/09/2026: utente `deploy` creato con le chiavi SSH copiate e
login verificato. **Tutto il resto qui sotto e' ancora da fare.**

```bash
# gia' fatto, lasciato come riferimento se si riparte da zero
sudo adduser --disabled-password --gecos "" deploy
sudo usermod -aG sudo deploy
sudo rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy/
sudo chmod 700 /home/deploy/.ssh
```

Blindatura SSH — **da fare**, e da fare restando collegata in un secondo
terminale finche' non hai verificato di riuscire a rientrare:

```bash
sudo tee /etc/ssh/sshd_config.d/99-blindatura.conf >/dev/null <<EOF
PasswordAuthentication no
PermitRootLogin no
KbdInteractiveAuthentication no
EOF
sudo sshd -t && sudo systemctl reload ssh
```

Aggiornamento, firewall e stack:

```bash
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update && sudo apt-get -y full-upgrade
sudo apt-get install -y ufw fail2ban
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443
sudo ufw --force enable

sudo apt-get install -y nginx mysql-server supervisor certbot \
     python3-certbot-nginx git unzip curl

# PHP 8.5: dall'archivio di 26.04, niente PPA
sudo apt-get install -y php8.5-fpm php8.5-mysql php8.5-mbstring php8.5-xml \
     php8.5-curl php8.5-zip php8.5-gd php8.5-bcmath php8.5-intl

curl -sS https://getcomposer.org/installer | php8.5 -- \
     --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get install -y nodejs
```

`gd` e `zip` non sono opzionali: servono a dompdf/TCPDF per i PDF e a
`maatwebsite/excel` per gli export.

**Il collaudo vero e' qui.** Laravel 12 e Filament 3 su PHP 8.5 non sono mai
girati: sbloccato non vuol dire verificato, e la suite di test non c'e' piu'
per dirlo. Appena l'app e' installata, prima di ogni altra cosa:

```bash
php8.5 artisan about          # deve stampare senza errori
php8.5 artisan route:list | wc -l
```

e poi il giro a mano della Fase 2, con attenzione a export Excel e PDF, che
sono i due punti dove le dipendenze sono cambiate di piu'.

**MySQL solo in locale.** In `/etc/mysql/mysql.conf.d/mysqld.cnf` verifica
`bind-address = 127.0.0.1` e non aprire mai la 3306 sul firewall. `DB_HOST`
in `.env` resta `127.0.0.1` esattamente com'è oggi.

```bash
sudo mysql_secure_installation
sudo mysql
```
```sql
CREATE DATABASE crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'crm'@'localhost' IDENTIFIED BY '<password-lunga-e-casuale>';
GRANT ALL PRIVILEGES ON crm.* TO 'crm'@'localhost';
FLUSH PRIVILEGES;
```

Un DB e un utente **per applicazione** — le stesse tre righe con `wordpress`
al posto di `crm` quando arriva il sito: l'utente di WordPress non deve poter
leggere il database del CRM. È metà del motivo per cui li tieni separati.

### Guardarci dentro senza phpMyAdmin

Non installare phpMyAdmin sulla VPS: è una pagina di login su internet davanti
al database, ed è fra i bersagli più scansionati che esistano. Dal Mac si apre
un tunnel SSH e si usa un client locale (TablePlus, Sequel Ace):

```bash
ssh -L 3307:127.0.0.1:3306 deploy@<IP-VPS>
```

Finché quel terminale resta aperto, il client si collega a `127.0.0.1:3307`
con l'utente `crm`. Nessuna porta esposta, nessuna interfaccia web da tenere
aggiornata.

### Due pool php-fpm, non uno

È l'isolamento che ti interessa davvero avendo due app sulla stessa macchina:
se un plugin WordPress impazzisce e satura i suoi worker, il CRM continua a
rispondere. Copia `/etc/php/8.4/fpm/pool.d/www.conf` in `crm.conf` e
`wordpress.conf`, e in ciascuno cambia nome del pool, socket
(`/run/php/php8.5-fpm-crm.sock`) e `pm.max_children`. Poi elimina il pool
`www` di default.

---

## Fase 2 — Il CRM sulla VPS, non ancora pubblico

Non si prova in produzione. Crea un record temporaneo — **solo questo**, i
record di Fase 0 restano fermi:

```
A    vps.alexcaffe.com    <IP-della-VPS>    TTL 300
```

Poi:

```bash
git clone https://github.com/laura592/multitenant-crm.git /var/www/multitenant-crm
cd /var/www/multitenant-crm
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.production.example .env
php8.5 artisan key:generate
php8.5 artisan storage:link
chown -R www-data:www-data storage bootstrap/cache
```

In `.env`, oltre a `DB_*` con le credenziali appena create:

```dotenv
APP_URL=https://app.alexcaffe.com     # l'URL finale, non quello di prova
MAIL_MAILER=smtp
MAIL_HOST=mail.alexcaffe.com          # resta il server Serverplan
MAIL_PORT=465
MAIL_SCHEME=                          # vuoto: lo deriva dalla porta
MAIL_USERNAME=<una casella reale @alexcaffe.com>
MAIL_PASSWORD=
```

È il punto che tiene in piedi la deliverability: le email escono
dall'IP `86.107.36.173`, quindi SPF e DKIM restano quelli di sempre.
**Verifica con Serverplan il limite orario di invio SMTP** — un giro di
rapportini può fare parecchie email in pochi minuti, e i piani condivisi
hanno soglie oltre le quali smettono di accettare.

nginx: un server block per `vps.alexcaffe.com` con root
`/var/www/multitenant-crm/public` e `fastcgi_pass unix:/run/php/php8.5-fpm-crm.sock`,
poi `certbot --nginx -d vps.alexcaffe.com`.

### Aggiornare il codice dal Mac

Finche' il repo non e' raggiungibile dalla VPS il codice si porta con `rsync`.
**Le esclusioni non sono facoltative:**

```bash
rsync -az --delete \
  --exclude '.git' --exclude 'node_modules' --exclude 'vendor' \
  --exclude 'bootstrap/cache' --exclude 'storage' --exclude '.env' \
  -e "ssh -i ~/.ssh/crm-ovh" \
  ~/Documents/multitenant-crm/ deploy@<IP-VPS>:/var/www/multitenant-crm/
```

Poi, **sempre come `deploy`**:

```bash
cd /var/www/multitenant-crm
composer install --no-dev --optimize-autoloader
php8.5 artisan migrate --force
php8.5 artisan optimize:clear
```

Tre inciampi, tutti visti il 28/09/2026:

- **`bootstrap/cache` va escluso.** Il `packages.php` del Mac elenca anche i
  pacchetti di sviluppo; sulla VPS, dove `composer install --no-dev` non li ha
  installati, il primo che incontra fa cadere tutto con
  `Class "Laravel\Pail\PailServiceProvider" not found`: HTTP 500 su ogni
  pagina, senza che una riga di codice sia sbagliata.
- **`--delete` porta via `storage/framework/views`** se `storage` non e'
  escluso, e Blade non la ricrea da solo: va rifatta a mano con
  `mkdir -p storage/framework/{views,cache/data,sessions} storage/logs`.
- **L'app e' di `deploy`, il `sudo` e' di `ubuntu`.** `ssh crm` entra come
  `ubuntu`: da li' ogni `composer` o `artisan` finisce in "Permission denied",
  e `git` ci aggiunge un "dubious ownership" che sembra un altro problema ma
  e' lo stesso. Non serve riconnettersi: da una sessione `ubuntu` si passa
  all'altro utente con `sudo -u deploy bash -lc '...'`.

### Portare i dati

Dal tuo Mac, con il CRM ancora vivo su cPanel:

```bash
ssh -p 10223 nbalexca@alexcaffe.com \
  "mysqldump --single-transaction --routines --default-character-set=utf8mb4 \
   -u nbalexca_crm -p nbalexca_multitenant_crm | gzip" > crm.sql.gz

scp crm.sql.gz deploy@<IP-VPS>:/tmp/
ssh deploy@<IP-VPS> "gunzip -c /tmp/crm.sql.gz | mysql -u crm -p crm"

# i PDF e gli allegati già generati
rsync -avz -e "ssh -p 10223" \
  nbalexca@alexcaffe.com:~/multitenant-crm/storage/app/ \
  ./storage-app/
rsync -avz ./storage-app/ deploy@<IP-VPS>:/var/www/multitenant-crm/storage/app/
```

Sono ~42 MB di database e ~43 MB di file: minuti, non una finestra di
manutenzione. Poi `php8.5 artisan migrate --force` e
`php8.5 artisan optimize:clear`.

### Cron e coda

Una riga sola, con il **percorso assoluto** del binario — è l'inciampo che ha
già tenuto ferma la produzione su cPanel:

```cron
* * * * * cd /var/www/multitenant-crm && /usr/bin/php8.5 artisan schedule:run >> /dev/null 2>&1
```

Il worker passa sotto supervisor (file di configurazione pronto in
`docs/vps-cron-e-sync.md` §2). Quando lo attivi, **togli da
`routes/console.php` la riga**:

```php
Schedule::command('queue:work --queue=default,eureka-bulk --stop-when-empty --max-time=50')
```

Due worker sullo stesso database di code si rubano i job e raddoppiano le
chiamate a Eureka. Nessun test asserisce quella riga, si rimuove pulita.

### Verifica prima di andare avanti

Su `https://vps.alexcaffe.com`, con i dati reali importati:

- [ ] login con un'utenza vera
- [ ] un preventivo reale: righe e totali identici a quelli su `app.alexcaffe.com`
- [ ] generazione PDF di un preventivo e di un rapportino
- [ ] **email di prova a te stessa**, e controlla negli header che SPF e DKIM
      risultino `pass`
- [ ] `php8.5 artisan schedule:list` mostra tutti gli otto lavori Eureka
- [ ] un `MAIL_MAILER=log php8.5 artisan eureka:sincronizza-tutto --tenant=alex`
      arriva in fondo senza fallimenti (`MAIL_MAILER=log` perché
      `gestionale:sync` manda il digest all'ufficio, e da una macchina di
      prova non deve partire)
- [ ] Eureka risponde: se il fornitore filtra per IP, l'IP nuovo va in
      whitelist **prima** del cutover, non dopo

---

## Fase 3 — Cutover del CRM

**24 ore prima**: abbassa il TTL di `app.alexcaffe.com` da 3600 a **300**.
Senza questo passo, un rollback impiega un'ora a propagarsi.

Poi, in un momento di traffico basso:

1. Metti il CRM su cPanel in manutenzione (`php artisan down`) — evita che
   qualcuno scriva su un database che stai per abbandonare.
2. Rifai il `mysqldump` (Fase 2), questa volta è quello definitivo.
3. Cambia il record: `A app.alexcaffe.com → <IP-VPS>`.
4. `certbot --nginx -d app.alexcaffe.com` — solo **dopo** che il DNS punta
   alla VPS, altrimenti la validazione HTTP fallisce.
5. Verifica il dominio vero, non quello di prova. Controlla anche l'invio di
   una email reale.

**Rollback**: ripunta `A app.alexcaffe.com → 86.107.36.173` e togli la
manutenzione sul vecchio. Con TTL 300 sei tornato indietro in cinque minuti,
e il vecchio ambiente è rimasto intatto — in Fase 3 lo hai solo letto.

Tieni cPanel e il suo database del CRM **intatti almeno un mese** prima di
cancellare qualcosa.

---

## Fase 4 — WordPress, a CRM stabile

Non nello stesso giorno. Se qualcosa va storto vuoi sapere quale delle due
migrazioni l'ha causato.

Il vantaggio è che **il dominio non cambia** (`https://www.alexcaffe.com`
prima e dopo): niente search-replace degli URL nel database, che è la parte
che di solito rompe le migrazioni WordPress.

1. `rsync` dell'intera document root + `mysqldump` del DB di WordPress.
2. Server block nginx per `alexcaffe.com` e `www.alexcaffe.com`, con il
   redirect apex → www che c'è oggi (il sito risponde 301 verso `www`).
3. **Plugin WP Mail SMTP**, puntato a `mail.alexcaffe.com` con le stesse
   credenziali logiche del CRM. Senza, i form di contatto userebbero
   `mail()` della VPS e finirebbero in spam.
4. Cambia `A alexcaffe.com` e `A www` → VPS (TTL 300 il giorno prima), poi
   certbot per entrambi.
5. **Ricontrolla Email Routing = Local Mail Exchanger** in cPanel, e manda
   una email di prova a una casella `@alexcaffe.com` dall'esterno.

Nota di sicurezza: sul condiviso WordPress sta dietro il WAF BitNinja del
provider. Sulla VPS quel paracadute non c'è. Servono aggiornamenti puntuali,
`fail2ban` sulle pagine di login e — se il sito è statico nei contenuti — una
cache aggressiva che riduca la superficie esposta.

---

## Fase 5 — Quello che prima faceva il provider

Sono le cose che su cPanel non erano un tuo problema e ora lo diventano.

**Backup.** Il "backup automatizzato 1 giorno" di OVH è uno snapshot di ieri:
se un import sporca i dati e te ne accorgi giovedì per lunedì, contiene già i
dati sporchi. Serve un `mysqldump` notturno con storico, spedito **fuori
dalla macchina** (Backblaze B2, o lo storage object del provider). Va messo
in piedi *prima* di dismettere il vecchio hosting, non dopo.

**Aggiornamenti.** `unattended-upgrades` per le patch di sicurezza; le minor
di PHP e MySQL a mano, dopo aver letto cosa cambia.

**Accorgersene.** Almeno un controllo esterno che avvisi se
`app.alexcaffe.com` smette di rispondere, e un occhio a
`storage/logs/gestionale-$(date +%F).log` la mattina dopo un fallimento
notturno — `eureka:sincronizza-tutto` esce con codice diverso da zero
apposta, perché il cron possa segnalarlo.

**Certificati.** Certbot rinnova da solo, ma verifica una volta che il timer
sia attivo: `systemctl list-timers | grep certbot`.

---

## Perché non Docker

La VPS ospiterà due applicazioni, ed è la soglia in cui containerizzare
inizia ad avere senso — ma non qui, per due ragioni concrete.

WordPress **scrive dentro sé stesso**: aggiornamenti di core, plugin, temi e
upload passano dal suo filesystem. Metterlo in un container significa
bind-montare quasi tutta la sua directory, e a quel punto l'immagine non
garantisce più niente: hai la complessità di Docker senza l'immutabilità che
la giustifica.

E l'isolamento che ti serve davvero — che le due app non si rubino risorse —
lo danno i **due pool php-fpm** di Fase 1, con una frazione delle parti in
movimento.

Il `docker-compose.yml` in radice è di Sail, cioè da sviluppo: monta il
codice come volume, espone MySQL sulla 3309, gira con Xdebug. **Non è un
setup di produzione** e non va portato su questa macchina.

Da rivalutare se un domani sulla stessa VPS finiscono altri progetti, o se il
server lo amministra qualcun altro.
