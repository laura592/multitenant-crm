<?php

namespace App\Jobs;

use App\Models\ServiceReport;
use App\Models\User;
use App\Support\Gestionale\EurekaClient;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Invio a Eureka spostato fuori dal ciclo di richiesta Filament (azione
 * "invia_gestionale" di ServiceReportResource): era una chiamata HTTP
 * sincrona (fino a 15s di timeout, contro un'API vecchia su Firebird senza
 * ambiente di test) che bloccava l'interfaccia per l'intera durata del
 * round-trip. L'esito arriva all'utente come notifica database (il pannello
 * Filament le mostra gia' via polling automatico), stesso pattern usato da
 * LeaveRequestResource.
 */
class SendServiceReportToGestionaleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly ServiceReport $report,
        private readonly User $notifyUser,
    ) {}

    public function handle(): void
    {
        $report = $this->report->fresh([
            'customer.billingCustomer', 'machineProduct', 'machineUnit.product',
            'machineUnit.billingCustomer', 'materialsUsed.material', 'tenant',
        ]);

        if (! $report) {
            return;
        }

        // Ri-validato qui (non solo nell'azione che dispatcha il job): tra
        // il click e l'esecuzione sulla coda i dati del rapportino possono
        // essere cambiati, o il job puo' girare molto dopo se la coda e'
        // occupata.
        $errors = $report->gestionaleValidationErrors();

        if ($errors !== []) {
            $report->update(['gestionale_sync_status' => 'failed', 'gestionale_sync_error' => implode(' ', $errors)]);

            Notification::make()
                ->title("Impossibile inviare {$report->number} a gestionale")
                ->body(implode("\n", $errors))
                ->danger()
                ->sendToDatabase($this->notifyUser);

            return;
        }

        try {
            $client = new EurekaClient($report->tenant);
            $result = $client->inviaSchedaLavoro($report->toGestionalePayload(), "CRM-{$report->id}");

            $report->update([
                // La risposta reale identifica il documento con "id_eureka",
                // non "id" — vedi la stessa nota in EurekaClient.php.
                'gestionale_scheda_lavoro_id' => $result['id_eureka'] ?? null,
                'gestionale_sync_status' => 'sent',
                'gestionale_sync_error' => null,
                'gestionale_synced_at' => now(),
            ]);

            Notification::make()
                ->title("Rapportino {$report->number} inviato a gestionale")
                ->success()
                ->sendToDatabase($this->notifyUser);
        } catch (\Throwable $e) {
            $report->update([
                'gestionale_sync_status' => 'failed',
                'gestionale_sync_error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title("Invio a gestionale fallito per {$report->number}")
                ->body($e->getMessage())
                ->danger()
                ->sendToDatabase($this->notifyUser);
        }
    }
}
