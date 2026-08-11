<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\ServiceReport;
use Illuminate\Console\Command;

/**
 * Collega i lavaggi storici (importati da ImportLavaggiFromSpreadsheet, prima
 * che esistesse service_report_id) al rapportino Eureka che li documenta
 * davvero — countsAsLavaggio() gia' sa riconoscerli (parola chiave o codice
 * materiale LAV2/SANIFICAZIONE), manca solo l'abbinamento per data+cliente
 * perche' quei rapportini sono stati importati senza machine_unit_id e quindi
 * mai passati da ServiceReport::syncMaintenanceSchedule().
 *
 * Collega solo quando c'e' esattamente UN rapportino candidato entro la
 * finestra di tolleranza: quando ce ne sono due (stessa data, numerazione SL-
 * consecutiva — tipicamente la stessa visita spezzata in due documenti sul
 * gestionale) la scelta sarebbe arbitraria, quindi restano segnalati per
 * revisione manuale invece di indovinare.
 */
class LinkLavaggiToServiceReports extends Command
{
    protected $signature = 'lavaggi:link-service-reports
        {--dry-run : Mostra cosa verrebbe collegato senza scrivere nulla}
        {--days=10 : Tolleranza in giorni tra data lavaggio e data intervento rapportino}';

    protected $description = "Collega i lavaggi storici senza service_report_id al rapportino Eureka corrispondente (stesso cliente, data vicina, countsAsLavaggio)";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = (int) $this->option('days');

        $lavaggi = Lavaggio::whereNull('service_report_id')
            ->with('customer')
            ->orderBy('customer_id')
            ->orderBy('data')
            ->get();

        $linked = 0;
        $ambiguous = [];
        $noCandidate = [];

        foreach ($lavaggi as $lavaggio) {
            $label = $this->describe($lavaggio);

            $candidates = ServiceReport::where('customer_id', $lavaggio->customer_id)
                ->whereIn('status', ServiceReport::CLOSED_STATUSES)
                ->whereBetween('intervention_date', [
                    $lavaggio->data->copy()->subDays($days),
                    $lavaggio->data->copy()->addDays($days),
                ])
                ->get()
                ->filter(fn (ServiceReport $report) => $report->countsAsLavaggio());

            if ($candidates->isEmpty()) {
                $noCandidate[] = $label;

                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous[] = "{$label} → ".$candidates->map(
                    fn (ServiceReport $r) => "{$r->number} ({$r->intervention_date->format('Y-m-d')})"
                )->implode(', ');

                continue;
            }

            $report = $candidates->first();
            $this->line("  <info>LINK</info> {$label} → {$report->number} ({$report->intervention_date->format('Y-m-d')})");
            $linked++;

            if (! $dryRun) {
                $lavaggio->update(['service_report_id' => $report->id]);
            }
        }

        $this->info(sprintf(
            "%sCollegati: %d. Ambigui (piu' di un rapportino candidato): %d. Senza candidati: %d.",
            $dryRun ? '[DRY RUN] ' : '',
            $linked,
            count($ambiguous),
            count($noCandidate),
        ));

        if ($ambiguous !== []) {
            $this->warn('Da rivedere a mano (piu\' rapportini candidati):');
            foreach ($ambiguous as $row) {
                $this->line("  - {$row}");
            }
        }

        if ($noCandidate !== []) {
            $this->warn("Da rivedere a mano (nessun rapportino entro {$days} giorni):");
            foreach ($noCandidate as $row) {
                $this->line("  - {$row}");
            }
        }

        return self::SUCCESS;
    }

    private function describe(Lavaggio $lavaggio): string
    {
        $customerLabel = $lavaggio->customer->company_name ?? $lavaggio->customer->full_name ?? $lavaggio->customer_id;

        return "{$customerLabel} — {$lavaggio->data->format('Y-m-d')} ({$lavaggio->descrizione})";
    }
}
