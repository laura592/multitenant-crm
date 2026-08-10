<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use Illuminate\Console\Command;

/**
 * Collega machine_unit_id ai piani di lavaggio che hanno gia' un beverage_type
 * (vedi SplitLavaggioSchedulesByBeverageType) ma nessuna macchina agganciata —
 * la stragrande maggioranza dei piani storici, perche' il campo non esisteva
 * ancora quando sono stati creati/importati. Stessa convenzione di keyword su
 * MachineUnit.model_name gia' usata da SplitLavaggioSchedulesByBeverageType:
 * collega solo quando c'e' esattamente UNA macchina del cliente il cui nome
 * contiene la parola chiave del beverage_type — mai un'ambiguita' risolta a
 * caso, mai una macchina creata dal nulla. selz/bibite non hanno una
 * convenzione di nome-macchina consolidata quanto birra/acqua/vino: restano
 * quasi sempre senza candidato, segnalati per revisione manuale.
 */
class LinkMaintenanceScheduleMachineUnit extends Command
{
    protected $signature = 'maintenance-schedules:link-machine-unit
        {--dry-run : Mostra cosa verrebbe collegato senza scrivere nulla}';

    protected $description = "Collega ai piani di lavaggio la macchina del cliente corrispondente al beverage_type gia' classificato";

    private const KEYWORDS = [
        MaintenanceSchedule::BEVERAGE_BIRRA => 'birra',
        MaintenanceSchedule::BEVERAGE_ACQUA => 'acqua',
        MaintenanceSchedule::BEVERAGE_VINO => 'vino',
        MaintenanceSchedule::BEVERAGE_SELZ => 'selz',
        MaintenanceSchedule::BEVERAGE_BIBITE => 'bibite',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $schedules = MaintenanceSchedule::where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->whereNull('machine_unit_id')
            ->whereNotNull('beverage_type')
            ->with('customer')
            ->get();

        $linked = 0;
        $ambiguous = [];
        $noCandidate = [];

        foreach ($schedules as $schedule) {
            $keyword = self::KEYWORDS[$schedule->beverage_type] ?? null;
            $label = $this->describe($schedule);

            if ($keyword === null) {
                $noCandidate[] = "{$label} (nessuna parola chiave nota per beverage_type={$schedule->beverage_type})";

                continue;
            }

            $candidates = MachineUnit::where('current_customer_id', $schedule->customer_id)
                ->get()
                ->filter(fn (MachineUnit $unit) => str_contains(mb_strtolower($unit->model_name ?? ''), $keyword));

            if ($candidates->isEmpty()) {
                $noCandidate[] = $label;

                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous[] = "{$label} → ".$candidates->pluck('model_name')->implode(', ');

                continue;
            }

            $unit = $candidates->first();
            $this->line("  <info>LINK</info> {$label} → {$unit->model_name} ({$unit->serial_number})");
            $linked++;

            if (! $dryRun) {
                $schedule->update(['machine_unit_id' => $unit->id]);
            }
        }

        $this->info(sprintf(
            "%sCollegati: %d. Ambigui (piu' di una macchina candidata): %d. Senza candidati: %d.",
            $dryRun ? '[DRY RUN] ' : '',
            $linked,
            count($ambiguous),
            count($noCandidate),
        ));

        if ($ambiguous !== []) {
            $this->warn('Da rivedere a mano (piu\' macchine candidate):');
            foreach ($ambiguous as $row) {
                $this->line("  - {$row}");
            }
        }

        if ($noCandidate !== []) {
            $this->warn('Da rivedere a mano (nessuna macchina trovata):');
            foreach ($noCandidate as $row) {
                $this->line("  - {$row}");
            }
        }

        return self::SUCCESS;
    }

    private function describe(MaintenanceSchedule $schedule): string
    {
        $customerLabel = $schedule->customer->company_name ?? $schedule->customer->full_name ?? $schedule->customer_id;

        return "{$customerLabel} — {$schedule->beverage_type}";
    }
}
