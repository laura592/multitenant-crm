# VPS: cron e sincronizzazione con Eureka

Cosa mettere in piedi su una VPS (dove non c'è il vincolo di PHP 8.2 di
cPanel) perché il CRM resti allineato al gestionale da solo, e cosa lanciare
a mano quando è rimasto indietro.

Il runbook del primo cutover resta `docs/deploy-cutover.md`; qui c'è solo la
parte periodica.

## 1. Il cron: una riga sola

Laravel non vuole un cron per comando. Ne vuole **uno**, che ogni minuto
chiede allo scheduler se c'è qualcosa da fare. La lista dei lavori e i loro
orari vivono in `routes/console.php`, sotto controllo di versione: si
cambiano lì, non nel crontab.

```cron
* * * * * cd /var/www/multitenant-crm && php artisan schedule:run >> /dev/null 2>&1
```

Il path assoluto va aggiustato, e `php` deve essere il binario giusto: su una
VPS con più versioni installate conviene scriverlo esplicito
(`/usr/bin/php8.4`), perché il PATH del cron non è quello della tua shell —
è esattamente l'inciampo che ha tenuto ferma la produzione cPanel.

Verifica che sia vivo:

```bash
php artisan schedule:list        # cosa girerà e quando
tail -f storage/logs/gestionale-$(date +%F).log
```

## 2. La coda

`routes/console.php` fa girare un worker effimero ogni minuto
(`queue:work --stop-when-empty --max-time=50`). Su una VPS conviene invece un
worker persistente sotto **supervisor**: parte al boot, si riavvia da solo se
muore, e non paga l'avvio di Laravel a ogni minuto.

`/etc/supervisor/conf.d/multitenant-crm-worker.conf`:

```ini
[program:multitenant-crm-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/multitenant-crm/artisan queue:work --queue=default,eureka-bulk --sleep=3 --tries=3 --max-time=3600
directory=/var/www/multitenant-crm
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/multitenant-crm/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl status
```

Se passi a supervisor, **togli** la riga `queue:work` da
`routes/console.php`: due worker sullo stesso database di code non si
rompono, ma si rubano i job e raddoppiano le chiamate a Eureka.

## 3. Cosa gira e quando

Gli orari sono sfalsati apposta. Non accorparli: il 06/08/2026 un carico
concentrato ha causato un disservizio dell'API del fornitore.

| Ora | Comando | Cosa fa |
|---|---|---|
| 03:00 | `gestionale:sync` | anagrafiche, collegamenti, macchine installate, note, proposte di doppione |
| 03:15 | `eureka:apply-machine-billing-payer` | chi paga davvero, per macchina |
| 04:00 | `eureka:import-service-reports` | i rapportini degli ultimi 7 giorni, con dettaglio |
| 05:00 | `eureka:refresh-material-prices` | i listini |
| 05:30 | `eureka:import-partite-aperte` | lo scadenzario (prima delle fatture: è la pagina su cui si agisce la mattina) |
| 05:45 | `eureka:import-fatture` | le fatture registrate |
| 06:15 | `eureka:import-kpi-contabili` | fatturato mensile e cash flow |
| lun 06:00 | `eureka:sweep-materials-catalog` | i materiali nuovi a catalogo |

## 4. Quando è rimasto indietro

Dopo un fermo (cron spento, VPS nuova, blocco PHP), non serve ricordarsi
otto comandi e il loro ordine:

```bash
php artisan eureka:sincronizza-tutto --tenant=alex
```

Li lancia tutti nell'ordine delle **dipendenze**, non degli orari: il
catalogo prima dei rapportini (le righe articolo devono trovare il materiale
a cui agganciarsi), i prezzi subito dopo, e `gestionale:sync` per ultimo —
le proposte di doppione confrontano i nostri rapportini con quelli appena
importati, e girando prima confronterebbero con quelli di ieri.

Opzioni utili:

```bash
--da=2026-01-01        # quanto indietro andare coi rapportini (default: 120 giorni)
--dry-run              # prova a vuoto dove i singoli comandi lo supportano
--salta=fatture        # salta un passo (ripetibile)
```

Un passo che fallisce non ferma gli altri: Eureka risponde 500 a raffica su
query identiche, e sette ottavi di allineamento valgono più di niente. Alla
fine c'è la tabella degli esiti, e il comando esce diverso da zero se
qualcosa è andato storto — così il cron se ne accorge.

**Attenzione**: `gestionale:sync` manda il digest all'ufficio. In produzione
è giusto; da una macchina di prova neutralizza la posta per quella singola
esecuzione, senza toccare `.env`:

```bash
MAIL_MAILER=log php artisan eureka:sincronizza-tutto --tenant=alex
```

## 5. Il log dei movimenti

Tutto quello che il CRM scambia con Eureka finisce in
`storage/logs/gestionale-AAAA-MM-GG.log`, separato da `laravel.log` perché lì
annegherebbe fra le eccezioni. Conservato 90 giorni (`LOG_GESTIONALE_DAYS`):
le domande sui dati contabili arrivano settimane dopo.

Si registra quello che **cambia**, non quello che viene letto — una scheda
già identica o un prezzo invariato non lasciano traccia, altrimenti il file
diventa illeggibile proprio nei giorni in cui serve.

```
sync-anagrafiche: avviato {"tenant":"alex"}
sync-anagrafiche: macchina creata {"matricola":"AHD245035","modello":"MACINADOSATORE CEADO","cliente":"Majer S. Rocco"}
prezzi: listino aggiornato {"articolo":"CHIORD","da":44.5,"a":46.2}
import-rapportini: rapportino creato {"scheda_eureka":17713,"numero_gestionale":"SL-699/2026"}
doppioni: rapportini uniti {"nostro":"RT-2026-0586","importato":"RT-2026-0718","pagante":"GOPPION CAFFE' SPA","deciso_da":"..."}
sincronizza-tutto: concluso {"secondi":412.7,"falliti":[]}
```

Domande a cui risponde, che prima restavano senza risposta:

```bash
# quando è cambiato questo prezzo, e da quanto veniva?
grep '"articolo":"CHIORD"' storage/logs/gestionale-*.log

# chi ha unito questo rapportino?
grep 'RT-2026-0586' storage/logs/gestionale-*.log

# cosa è fallito stanotte?
grep -h 'WARNING\|falliti":\[".' storage/logs/gestionale-$(date +%F).log
```
