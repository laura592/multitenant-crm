<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sync Google -> CRM per i calendari di lavoro collegati (docs/architecture.md §15.2).
Schedule::command('google-calendar:pull')->everyFifteenMinutes();
