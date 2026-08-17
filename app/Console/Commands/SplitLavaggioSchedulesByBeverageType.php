<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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
        // Un cliente puo' avere due piani lavaggio "non ancora classificati"
        // (whereNull('beverage_type') in handle() e' idempotente solo sul
        // piano sorgente, non sulla destinazione): se il tipo rilevato per
        // questo piano e' gia' quello di un altro piano dello stesso cliente
        // gia' classificato, questo diventa un doppione da unire invece di
        // classificare a se' — trovato 8 casi reali il 2026-08-12 (vedi
        // MergeDuplicateMaintenanceSchedules).
        $existing = MaintenanceSchedule::where('tenant_id', $schedule->tenant_id)
            ->where('customer_id', $schedule->customer_id)
            ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->where('beverage_type', $type)
            ->where('id', '!=', $schedule->id)
            ->first();

        if ($existing) {
            $this->moveLavaggi(Lavaggio::where('maintenance_schedule_id', $schedule->id), $existing);
            $schedule->delete();
            $existing->recalculateLavaggioNextDue();

            return;
        }

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
        // Stessa ragione del controllo in applyType(): riusa un piano dello
        // stesso tipo gia' esistente per questo cliente invece di crearne
        // sempre uno nuovo.
        $newSchedule = MaintenanceSchedule::firstOrCreate(
            [
                'tenant_id' => $original->tenant_id,
                'customer_id' => $original->customer_id,
                'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
                'beverage_type' => $type,
            ],
            [
                'status' => $original->status,
                'frequency_days' => MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$type] ?? null,
                'notes' => $original->notes,
            ],
        );

        // Solo le macchine con un tipo unico e non ambiguo (es. non "vino+birra").
        $machineIdsForType = $machineTypes
            ->filter(fn (array $types) => $types === [$type])
            ->keys();

        $this->moveLavaggi(
            Lavaggio::where('maintenance_schedule_id', $original->id)->whereIn('machine_unit_id', $machineIdsForType),
            $newSchedule,
        );

        $newSchedule->recalculateLavaggioNextDue();
    }

    /**
     * Sposta i lavaggi della query su $target, saltando quelli che
     * duplicherebbero un lavaggio gia' presente sullo stesso
     * service_report_id (violerebbe l'unique su
     * lavaggi(service_report_id, maintenance_schedule_id)) — cancellati come
     * duplicati veri invece di spostati.
     */
    private function moveLavaggi(Builder $query, MaintenanceSchedule $target): void
    {
        $query->get()->each(function (Lavaggio $lavaggio) use ($target) {
            $duplicate = $lavaggio->service_report_id
                && Lavaggio::where('maintenance_schedule_id', $target->id)
                    ->where('service_report_id', $lavaggio->service_report_id)
                    ->exists();

            if ($duplicate) {
                $lavaggio->delete();

                return;
            }

            $lavaggio->maintenance_schedule_id = $target->id;
            $lavaggio->save();
        });
    }
}
