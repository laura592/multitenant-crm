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
 *
 * Include anche i rapportini in stato 'bozza' (non solo CLOSED_STATUSES):
 * countsAsLavaggio() e' un fatto materiale (il tecnico ha usato un ricambio
 * "LAVAGGIO X VIE"/sanificazione), vero a prescindere da firma/invio del
 * documento — un rapportino ancora bozza non e' meno prova di un lavaggio
 * fatto solo perche' la pratica amministrativa non e' chiusa.
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
        $duplicates = [];

        foreach ($lavaggi as $lavaggio) {
            $label = $this->describe($lavaggio);

            $candidates = ServiceReport::where('customer_id', $lavaggio->customer_id)
                ->whereIn('status', [...ServiceReport::CLOSED_STATUSES, 'bozza'])
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

            if (! $dryRun) {
                try {
                    $lavaggio->update(['service_report_id' => $report->id]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // Un'altra riga Lavaggio sullo stesso piano e' gia' collegata a questo
                    // rapportino (data leggermente diversa ma stesso evento reale, es. due
                    // import della stessa visita con un paio di giorni di scarto): non e'
                    // un errore, e' un doppione di questa riga da segnalare, non da linkare
                    // di nuovo (violerebbe l'unicita' service_report_id+maintenance_schedule_id).
                    $duplicates[] = "{$label} → {$report->number} (gia' rappresentato da un'altra riga sullo stesso piano)";

                    continue;
                }
            }

            $this->line("  <info>LINK</info> {$label} → {$report->number} ({$report->intervention_date->format('Y-m-d')})");
            $linked++;
        }

        $this->info(sprintf(
            "%sCollegati: %d. Ambigui (piu' di un rapportino candidato): %d. Senza candidati: %d. Righe duplicate (stesso rapportino gia' su un'altra riga dello stesso piano): %d.",
            $dryRun ? '[DRY RUN] ' : '',
            $linked,
            count($ambiguous),
            count($noCandidate),
            count($duplicates),
        ));

        if ($duplicates !== []) {
            $this->warn('Possibili righe duplicate (da unire/eliminare a mano):');
            foreach ($duplicates as $row) {
                $this->line("  - {$row}");
            }
        }

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
