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
 * Avvolge `eureka:import-service-reports` (comando lanciato finora sempre a
 * mano da terminale) per poterlo far partire anche da un bottone Filament
 * ("Importa rapportini da Eureka" su GestionaleSyncReview) senza bloccare la
 * richiesta HTTP: con --with-detail puo' girare per minuti su un intervallo
 * ampio (una chiamata pooled extra per rapportino). Stesso pattern di
 * SendServiceReportToGestionaleJob (notifica database a fine corsa).
 */
class ImportEurekaServiceReportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $from,
        private readonly string $to,
        private readonly bool $withDetail,
        private readonly User $notifyUser,
    ) {
        // Coda separata da 'default' (invio rapportino via email, push a
        // gestionale, geocodifica cliente): puo' girare per minuti su un
        // periodo ampio, non deve far aspettare azioni utente time-sensitive
        // dietro di se' nell'unico worker seriale disponibile (hosting
        // condiviso, vedi routes/console.php). Lo scheduler processa sempre
        // prima 'default' per intero, poi questa. Non una property $queue
        // ridichiarata: va in conflitto con quella (non tipizzata) gia'
        // definita dal trait Queueable, fatal error di composizione trait
        // (successo davvero, vedi log 2026-08-21) — onQueue() e' il modo
        // sicuro per impostarla.
        $this->onQueue('eureka-bulk');
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('eureka:import-service-reports', array_filter([
            '--tenant' => $this->tenant->slug,
            '--from' => $this->from,
            '--to' => $this->to,
            '--with-detail' => $this->withDetail,
        ]));

        $output = Artisan::output();

        if ($exitCode === 0) {
            Notification::make()
                ->title('Import rapportini Eureka completato')
                ->body("Periodo {$this->from} → {$this->to}.")
                ->success()
                ->sendToDatabase($this->notifyUser);
        } else {
            Notification::make()
                ->title('Import rapportini Eureka fallito')
                ->body(str($output)->limit(500)->toString())
                ->danger()
                ->sendToDatabase($this->notifyUser);
        }
    }
}
