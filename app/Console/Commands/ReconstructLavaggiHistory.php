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
 *
 * Include anche i rapportini in stato 'bozza' (non solo CLOSED_STATUSES):
 * countsAsLavaggio() e' un fatto materiale (il tecnico ha usato un ricambio
 * "LAVAGGIO X VIE"/sanificazione), vero a prescindere da firma/invio del
 * documento — un rapportino ancora bozza non e' meno prova di un lavaggio
 * fatto solo perche' la pratica amministrativa non e' chiusa.
 *
 * Quando lo stesso cliente ha PIU' rapportini distinti (numeri diversi) che
 * countano come lavaggio nello stesso giorno — tipicamente la stessa visita
 * spezzata in piu' documenti sul gestionale, uno per impianto — il gruppo
 * viene segnalato per revisione manuale invece di processarlo: elaborando i
 * rapportini uno alla volta, il primo processato "vince" il piano (lo trova
 * libero, lo occupa), i successivi trovano il piano gia' occupato da
 * quel primo report e vengono scartati in silenzio — la loro prova di
 * lavaggio andrebbe persa senza che nessuno se ne accorga. Non si prova a
 * indovinare quale rapportino vada su quale piano.
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

        $reports = ServiceReport::whereIn('status', [...ServiceReport::CLOSED_STATUSES, 'bozza'])
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
        $multipleSameDay = [];

        $reportsByCustomerDate = $reports->groupBy(
            fn (ServiceReport $r) => $r->customer_id.'|'.$r->intervention_date->format('Y-m-d')
        );

        foreach ($reportsByCustomerDate as $sameDayReports) {
            if ($sameDayReports->count() > 1) {
                $customerLabel = $sameDayReports->first()->customer->company_name
                    ?? $sameDayReports->first()->customer->full_name
                    ?? $sameDayReports->first()->customer_id;
                $multipleSameDay[] = sprintf(
                    '%s — %s (%s)',
                    $customerLabel,
                    $sameDayReports->first()->intervention_date->format('Y-m-d'),
                    $sameDayReports->pluck('number')->implode(', '),
                );

                continue;
            }

            $report = $sameDayReports->first();
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
            "%sCreati: %d. Collegati a righe gia' esistenti: %d. Gia' presenti (nessuna azione): %d. Rapportini senza alcun piano lavaggio: %d. Piu' rapportini stesso giorno (da rivedere a mano): %d.",
            $dryRun ? '[DRY RUN] ' : '',
            $created,
            $linked,
            $alreadyPresent - $linked,
            count($noSchedule),
            count($multipleSameDay),
        ));

        if ($multipleSameDay !== []) {
            $this->warn('Da rivedere a mano (piu\' rapportini distinti lo stesso giorno per lo stesso cliente):');
            foreach ($multipleSameDay as $row) {
                $this->line("  - {$row}");
            }
        }

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
