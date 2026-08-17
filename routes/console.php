<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('deadlines:send-reminders')->weeklyOn(1, '15:00');

Schedule::command('gestionale:sync')->dailyAt('03:00');

// QUEUE_CONNECTION=database in produzione: senza questo, i job accodati
// (invio a gestionale, geocodifica cliente) restano nella tabella `jobs`
// e non partono mai, perche' l'hosting condiviso non permette un worker
// persistente. Richiede comunque il cron `php artisan schedule:run` ogni
// minuto lato server (cPanel non lo attiva da solo).
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
