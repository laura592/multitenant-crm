<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use Illuminate\Console\Command;

/**
 * Oltre ai lavaggi gia' presenti in tabella (LinkLavaggiToServiceReports), lo
 * storico Eureka importato contiene molti piu' rapportini che documentano un
 * lavaggio (countsAsLavaggio()) di quanti ne siano mai stati trascritti a mano
 * nel foglio LAVAGGI.ods: qui si ricostruiscono anche quelli, creando le righe
 * Lavaggio mancanti invece di limitarsi a collegare quelle gia' esistenti.
 *
 * Questi rapportini storici non hanno quasi mai machine_unit_id (importati
 * prima che il campo fosse valorizzato sistematicamente), quindi non si puo'
 * sapere con certezza quale macchina sia stata lavata quando il cliente ha
 * piu' di un piano lavaggio attivo. Scelta esplicita dell'utente: in quel caso
 * si crea un Lavaggio su OGNI piano attivo del cliente per quella data,
 * assumendo che una visita di lavaggio copra tutti gli impianti del cliente
 * (pattern gia' visto nei dati: piani "birra" e "apertura stagione" con lo
 * stesso rapportino collegato lo stesso giorno). Se il cliente non ha nessun
 * piano lavaggio attivo, il rapportino resta fuori: crearne uno da zero
 * richiederebbe indovinare cadenza/beverage_type/macchina.
 */
class ReconstructLavaggiHistory extends Command
{
    protected $signature = 'lavaggi:reconstruct-history
        {--dry-run : Mostra cosa verrebbe creato senza scrivere nulla}
        {--days=10 : Tolleranza in giorni per considerare un lavaggio gia\' rappresentato su un piano}';

    protected $description = "Ricostruisce le righe Lavaggio mancanti dai rapportini storici che documentano un lavaggio, per ogni piano lavaggio attivo del cliente";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = (int) $this->option('days');

        $reports = ServiceReport::whereIn('status', ServiceReport::CLOSED_STATUSES)
            ->with(['customer', 'materialsUsed.material'])
            ->get()
            ->filter(fn (ServiceReport $r) => $r->countsAsLavaggio());

        $schedulesByCustomer = MaintenanceSchedule::where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
            ->get()
            ->groupBy('customer_id');

        $created = 0;
        $linked = 0;
        $alreadyPresent = 0;
        $noSchedule = [];

        foreach ($reports as $report) {
            $schedules = $schedulesByCustomer->get($report->customer_id, collect());

            if ($schedules->isEmpty()) {
                $customerLabel = $report->customer->company_name ?? $report->customer->full_name ?? $report->customer_id;
                $noSchedule[] = "{$customerLabel} — {$report->intervention_date->format('Y-m-d')} ({$report->number})";

                continue;
            }

            foreach ($schedules as $schedule) {
                $existing = Lavaggio::where('maintenance_schedule_id', $schedule->id)
                    ->whereBetween('data', [
                        $report->intervention_date->copy()->subDays($days),
                        $report->intervention_date->copy()->addDays($days),
                    ])
                    ->first();

                if ($existing) {
                    $alreadyPresent++;

                    if (! $existing->service_report_id) {
                        $this->line("  <comment>LINK</comment> {$this->describeSchedule($schedule, $report)} → riga esistente ({$report->number})");
                        $linked++;

                        if (! $dryRun) {
                            $existing->update(['service_report_id' => $report->id]);
                        }
                    }

                    continue;
                }

                $this->line("  <info>CREATE</info> {$this->describeSchedule($schedule, $report)} ({$report->number})");
                $created++;

                if (! $dryRun) {
                    Lavaggio::create([
                        'tenant_id' => $report->tenant_id,
                        'customer_id' => $report->customer_id,
                        'maintenance_schedule_id' => $schedule->id,
                        'service_report_id' => $report->id,
                        'data' => $report->intervention_date,
                        'descrizione' => "Generato da rapportino {$report->number}",
                    ]);
                }
            }
        }

        $this->info(sprintf(
            "%sCreati: %d. Collegati a righe gia' esistenti: %d. Gia' presenti (nessuna azione): %d. Rapportini senza alcun piano lavaggio: %d.",
            $dryRun ? '[DRY RUN] ' : '',
            $created,
            $linked,
            $alreadyPresent - $linked,
            count($noSchedule),
        ));

        if ($noSchedule !== []) {
            $this->warn('Rapportini di clienti senza alcun piano lavaggio (nessuna azione, per riferimento):');
            foreach (array_unique($noSchedule) as $row) {
                $this->line("  - {$row}");
            }
        }

        return self::SUCCESS;
    }

    private function describeSchedule(MaintenanceSchedule $schedule, ServiceReport $report): string
    {
        $customerLabel = $report->customer->company_name ?? $report->customer->full_name ?? $report->customer_id;
        $machineLabel = $schedule->machineUnit?->serial_number ?? $schedule->beverage_type ?? $schedule->id;

        return "{$customerLabel} [{$machineLabel}] — {$report->intervention_date->format('Y-m-d')}";
    }
}
