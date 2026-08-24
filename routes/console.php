<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('deadlines:send-reminders')->weeklyOn(1, '16:00');

Schedule::command('gestionale:sync')->dailyAt('03:00');

// Finora lanciato sempre a mano da terminale (vedi App\Console\Commands\
// ImportEurekaServiceReports): finestra corta (7 giorni, non l'intero
// storico) apposta, sia per restare leggero sull'API del fornitore (un
// import --with-detail su migliaia di righe ha gia' contribuito a un
// disservizio Eureka il 2026-08-06) sia perche' qui serve solo catturare
// i rapportini/materiali nuovi giorno per giorno, non un backfill. Orario
// sfalsato di un'ora da gestionale:sync per non sommare carico sulla
// stessa finestra. --with-detail e' necessario: senza, i ricambi
// (dettaglio[]) non vengono nemmeno letti, quindi i materiali mancanti
// come NR621216 non si creerebbero da soli (vedi thread 2026-08-20).
Schedule::command('eureka:import-service-reports', [
    '--tenant' => 'alex',
    '--from' => now()->subDays(7)->toDateString(),
    '--with-detail' => true,
])->dailyAt('04:00');

// 877 chiamate (una per materiale gia' a catalogo, pooled a concorrenza 15)
// ogni notte: l'utente ha scelto esplicitamente "ogni notte" pur avvisati
// del rischio di sovraccaricare l'API del fornitore (gia' successo un
// disservizio il 2026-08-06 con un carico ben piu' pesante) — orario
// sfalsato di un'ora dagli altri due sync Eureka per non sommarsi.
Schedule::command('eureka:refresh-material-prices', ['--tenant' => 'alex'])->dailyAt('05:00');

// Settimanale, non ogni notte: la prima scansione (2026-08-21, a mano) ha
// trovato 1368 materiali nuovi, ma i giri successivi trovano sempre meno
// (il catalogo locale cresce) — ~100 chiamate quasi ogni notte per
// diminishing returns non ha senso, un giro a settimana intercetta comunque
// gli articoli nuovi che Eureka aggiunge nel tempo senza esagerare col
// carico sulla loro API.
Schedule::command('eureka:sweep-materials-catalog', ['--tenant' => 'alex'])->weeklyOn(1, '06:00');

// QUEUE_CONNECTION=database in produzione: senza questo, i job accodati
// (invio a gestionale, geocodifica cliente) restano nella tabella `jobs`
// e non partono mai, perche' l'hosting condiviso non permette un worker
// persistente. Richiede comunque il cron `php artisan schedule:run` ogni
// minuto lato server (cPanel non lo attiva da solo).
//
// --queue=default,eureka-bulk (non solo 'default' implicito): un solo
// worker seriale, nessun secondo processo persistente disponibile su
// questo hosting. ImportEurekaServiceReportsJob/RefreshMaterialPricesFromEurekaJob
// (possono girare minuti) vivono sulla coda 'eureka-bulk' proprio per
// restare DIETRO in priorita' — Laravel svuota sempre 'default' per
// intero prima di toccare la coda successiva nell'elenco, quindi invio
// rapportino/push gestionale/geocodifica non aspettano mai un job bulk
// gia' in corso di lunga durata (thread 2026-08-21).
Schedule::command('queue:work --queue=default,eureka-bulk --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
