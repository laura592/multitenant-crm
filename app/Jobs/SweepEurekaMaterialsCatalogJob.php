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
 * Stesso pattern di RefreshMaterialPricesFromEurekaJob: avvolge
 * eureka:sweep-materials-catalog per poterlo lanciare anche da un bottone
 * Filament (GestionaleSyncReview) oltre che dal cron settimanale.
 */
class SweepEurekaMaterialsCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly User $notifyUser,
    ) {
        // Vedi il commento su RefreshMaterialPricesFromEurekaJob::__construct()
        // sul perche' onQueue() e non una property $queue ridichiarata.
        $this->onQueue('eureka-bulk');
    }

    public function handle(): void
    {
        $exitCode = Artisan::call('eureka:sweep-materials-catalog', [
            '--tenant' => $this->tenant->slug,
        ]);

        $output = Artisan::output();

        if ($exitCode === 0) {
            Notification::make()
                ->title('Scansione catalogo Eureka completata')
                ->body(str($output)->trim()->afterLast("\n")->limit(300)->toString())
                ->success()
                ->sendToDatabase($this->notifyUser);
        } else {
            Notification::make()
                ->title('Scansione catalogo Eureka fallita')
                ->body(str($output)->limit(500)->toString())
                ->danger()
                ->sendToDatabase($this->notifyUser);
        }
    }
}
