<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * I piani di lavaggio erano un piano unico per cliente, anche quando il
 * cliente ha impianti birra/acqua/vino mischiati insieme: impossibile
 * rappresentare scadenze diverse per tipo (birra 30gg, vino 90gg, acqua a
 * filtro) con un solo record. MachineUnit.model_name contiene gia' il tipo
 * ("Impianto Birra/Acqua/Vino"): lo usiamo per capire quanti tipi diversi
 * convivono in un piano e dividerlo in un piano per tipo.
 *
 * I lavaggi storici gia' legati a una macchina di un solo tipo vengono
 * spostati sul piano nuovo corrispondente (triggera il ricalcolo automatico
 * di MaintenanceSchedule::recalculateLavaggioNextDue() via i model event di
 * Lavaggio). I lavaggi "tutti gli impianti" (machine_unit_id nullo) e le
 * macchine con nome ambiguo (es. "vino+birra") restano sul piano originale:
 * non c'e' modo di sapere retroattivamente quale tipo abbiano davvero
 * servito.
 *
 * Idempotente: ignora i piani che hanno gia' un beverage_type assegnato.
 */
class SplitLavaggioSchedulesByBeverageType extends Command
{
    protected $signature = 'maintenance-schedules:split-by-beverage-type';

    protected $description = 'Divide i piani di lavaggio misti in un piano per tipo di impianto (birra/acqua/vino)';

    private const KEYWORDS = [
        MaintenanceSchedule::BEVERAGE_BIRRA => 'birra',
        MaintenanceSchedule::BEVERAGE_ACQUA => 'acqua',
        MaintenanceSchedule::BEVERAGE_VINO => 'vino',
    ];

    public function handle(): int
    {
        $schedules = MaintenanceSchedule::where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->whereNull('beverage_type')
            ->with('customer')
            ->get();

        $classified = 0;
        $split = 0;
        $skipped = [];

        foreach ($schedules as $schedule) {
            $machineTypes = $this->machineTypesFor($schedule->customer_id);
            $presentTypes = $machineTypes->flatten()->unique()->sort()->values();

            if ($presentTypes->isEmpty()) {
                $skipped[] = $schedule->customer->company_name ?? $schedule->id;

                continue;
            }

            $classified++;

            $primaryType = $presentTypes->shift();
            $this->applyType($schedule, $primaryType);

            foreach ($presentTypes as $type) {
                $split++;
                $this->createSplitSchedule($schedule, $type, $machineTypes);
            }
        }

        $this->info("Piani classificati: {$classified}. Piani nuovi creati dallo split: {$split}.");

        if ($skipped !== []) {
            $this->warn('Piani senza impianti riconoscibili dal nome macchina (da assegnare a mano): '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<string, array<int, string>> machine_unit_id => tipi rilevati dal nome
     */
    private function machineTypesFor(string $customerId): Collection
    {
        return MachineUnit::where('current_customer_id', $customerId)->get()
            ->mapWithKeys(function (MachineUnit $machine) {
                $name = mb_strtolower($machine->model_name ?? '');
                $types = [];

                foreach (self::KEYWORDS as $type => $keyword) {
                    if (str_contains($name, $keyword)) {
                        $types[] = $type;
                    }
                }

                return [$machine->id => $types];
            })
            ->filter(fn (array $types) => $types !== []);
    }

    private function applyType(MaintenanceSchedule $schedule, string $type): void
    {
        $schedule->update([
            'beverage_type' => $type,
            'frequency_days' => MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$type] ?? $schedule->frequency_days,
        ]);

        $schedule->recalculateLavaggioNextDue();
    }

    /**
     * @param  Collection<string, array<int, string>>  $machineTypes
     */
    private function createSplitSchedule(MaintenanceSchedule $original, string $type, Collection $machineTypes): void
    {
        $newSchedule = MaintenanceSchedule::create([
            'tenant_id' => $original->tenant_id,
            'customer_id' => $original->customer_id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'status' => $original->status,
            'beverage_type' => $type,
            'frequency_days' => MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$type] ?? null,
            'notes' => $original->notes,
        ]);

        // Solo le macchine con un tipo unico e non ambiguo (es. non "vino+birra").
        $machineIdsForType = $machineTypes
            ->filter(fn (array $types) => $types === [$type])
            ->keys();

        Lavaggio::where('maintenance_schedule_id', $original->id)
            ->whereIn('machine_unit_id', $machineIdsForType)
            ->get()
            ->each(function (Lavaggio $lavaggio) use ($newSchedule) {
                $lavaggio->maintenance_schedule_id = $newSchedule->id;
                $lavaggio->save();
            });

        $newSchedule->recalculateLavaggioNextDue();
    }
}
