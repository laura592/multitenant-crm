<?php

namespace App\Jobs;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * "Sincronizza ora" dalla revisione del sync (22/09/2026): lo stesso
 * gestionale:sync delle 03:00, per non aspettare la notte dopo una modifica
 * su Eureka. In coda perche' dura qualche minuto; avvisa chi l'ha lanciato.
 */
class SincronizzaGestionaleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(private readonly User $notifyUser)
    {
        $this->onQueue('eureka-bulk');
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('gestionale:sync');
        $output = trim(Artisan::output());

        Notification::make()
            ->title($exitCode === 0 ? 'Sincronizzazione con Eureka completata' : 'Sincronizzazione con Eureka fallita')
            ->body($exitCode === 0
                ? 'Le proposte nuove sono nella revisione del sync.'
                : str($output)->limit(500)->toString())
            ->{$exitCode === 0 ? 'success' : 'danger'}()
            ->sendToDatabase($this->notifyUser);
    }
}
