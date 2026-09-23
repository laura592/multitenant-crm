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
 * Saldi, partite aperte e fatture rilette da Eureka subito, senza aspettare
 * il giro notturno (23/09/2026).
 *
 * Stesso pattern di RefreshMaterialPricesFromEurekaJob. I tre comandi
 * girano nell'ordine in cui girano di notte, che non e' casuale: le
 * partite sono la fotografia da cui dipende lo scaduto, le fatture
 * servono ai controlli sui rapportini, i KPI si calcolano su entrambe
 * (vedi routes/console.php).
 */
class AggiornaSaldiEurekaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    /** I comandi da rifare, nell'ordine. */
    private const COMANDI = [
        'eureka:import-partite-aperte' => 'partite aperte e saldi',
        'eureka:import-fatture' => 'fatture',
        'eureka:import-kpi-contabili' => 'indicatori contabili',
    ];

    public function __construct(
        private readonly Tenant $tenant,
        private readonly User $notifyUser,
    ) {
        // Vedi RefreshMaterialPricesFromEurekaJob per il perche' della coda
        // separata e di onQueue() invece della property.
        $this->onQueue('eureka-bulk');
    }

    public function handle(): void
    {
        $falliti = [];

        foreach (self::COMANDI as $comando => $cosa) {
            $esito = Artisan::call($comando, ['--tenant' => $this->tenant->slug]);

            if ($esito !== 0) {
                $falliti[] = $cosa.': '.str(Artisan::output())->trim()->limit(200)->toString();
            }
        }

        if ($falliti === []) {
            Notification::make()
                ->title('Saldi aggiornati da Eureka')
                ->body('Partite aperte, fatture e indicatori sono quelli di adesso.')
                ->success()
                ->sendToDatabase($this->notifyUser);

            return;
        }

        Notification::make()
            ->title('Aggiornamento saldi incompleto')
            ->body(implode(' — ', $falliti))
            ->danger()
            ->sendToDatabase($this->notifyUser);
    }
}
