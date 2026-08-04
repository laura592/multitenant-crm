<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('deadlines:send-reminders')->weeklyOn(1, '08:00');

// Verificare con chi gestisce il server che un cron reale esegua
// `php artisan schedule:run` ogni minuto in produzione — non c'e' traccia
// nel repo di crontab/supervisor che lo attivi (vedi anche la nota identica
// gia' presente su deadlines:send-reminders sopra).
Schedule::command('gestionale:sync')->dailyAt('03:00');
