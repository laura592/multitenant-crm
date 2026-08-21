<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Stesso pattern di ImportEurekaServiceReportsJob: avvolge
 * eureka:refresh-material-prices per poterlo lanciare anche da un bottone
 * Filament (GestionaleSyncReview) oltre che dal cron notturno.
 */
class RefreshMaterialPricesFromEurekaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly User $notifyUser,
    ) {
        // Coda separata da 'default' (invio rapportino via email, push a
        // gestionale, geocodifica cliente): puo' girare minuti su 877
        // materiali, non deve far aspettare azioni utente time-sensitive
        // dietro di se' nell'unico worker seriale disponibile (hosting
        // condiviso, vedi routes/console.php). Non una property $queue
        // ridichiarata: va in conflitto con quella (non tipizzata) gia'
        // definita dal trait Queueable, fatal error di composizione trait
        // (successo davvero, vedi log 2026-08-21) — onQueue() e' il modo
        // sicuro per impostarla.
        $this->onQueue('eureka-bulk');
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('eureka:refresh-material-prices', [
            '--tenant' => $this->tenant->slug,
        ]);

        $output = Artisan::output();

        if ($exitCode === 0) {
            Notification::make()
                ->title('Prezzi materiali aggiornati da Eureka')
                ->body(str($output)->trim()->afterLast("\n")->limit(300)->toString())
                ->success()
                ->sendToDatabase($this->notifyUser);
        } else {
            Notification::make()
                ->title('Aggiornamento prezzi materiali fallito')
                ->body(str($output)->limit(500)->toString())
                ->danger()
                ->sendToDatabase($this->notifyUser);
        }
    }
}
