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
                // Stesso valore anche qui: senza, un futuro
                // eureka:import-service-reports non riconosce questo
                // rapportino come gia' esistente (la sua idempotenza e'
                // chiavata su eureka_service_report_id, non su
                // gestionale_scheda_lavoro_id) e ne crea un duplicato
                // locale — successo davvero, vedi RT-2026-0002/SL-592.
                'eureka_service_report_id' => $result['id_eureka'] ?? null,
                // Numero assegnato da Eureka, con lo stesso schema
                // "SL-{numero}/{anno}" usato da ImportEurekaServiceReports —
                // tenuto separato da "number" (che resta il numero RT-...
                // gia' consegnato al cliente in PDF/email) proprio per non
                // fargli perdere corrispondenza col documento inviato.
                'gestionale_number' => $this->resolveGestionaleNumber($report, $result),
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

    /**
     * Stesso schema "SL-{numero}/{anno}" usato da
     * ImportEurekaServiceReports::resolveUniqueNumber() (compreso il
     * fallback su id_eureka e la disambiguazione con suffisso) — cosi' un
     * rapportino nato qui e uno stesso documento ripescato da un giro
     * storico finiscono sempre con lo stesso numero gestionale, non due
     * diversi. L'anno serve perche' il "numero" di Eureka da solo non e'
     * univoco nel tempo (si ripete su anni diversi, vedi il commento
     * gemello sull'altro metodo). Il controllo di unicita' e' sulla
     * colonna gestionale_number (non piu' su "number", che ora resta il
     * numero CRM stabile e non viene mai riscritto qui).
     *
     * @param  array<string, mixed>  $result
     */
    private function resolveGestionaleNumber(ServiceReport $report, array $result): string
    {
        $numero = (int) ($result['numero'] ?? 0);
        $year = $report->intervention_date->year;
        $base = $numero > 0 ? "SL-{$numero}/{$year}" : 'SL-EK-'.(int) ($result['id_eureka'] ?? 0);

        $candidate = $base;
        $i = 2;
        while (
            ServiceReport::withTrashed()
                ->where('tenant_id', $report->tenant_id)
                ->where('gestionale_number', $candidate)
                ->where('id', '!=', $report->id)
                ->exists()
        ) {
            $candidate = "{$base}-".$i++;
        }

        return $candidate;
    }
}
