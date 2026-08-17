<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Una tantum: SplitLavaggioSchedulesByBeverageType e LinkMaintenanceScheduleMachineUnit
 * insieme potevano produrre due MaintenanceSchedule per lo stesso
 * (customer_id, machine_unit_id, type, beverage_type) — trovato l'8 piani
 * duplicati reali il 2026-08-12, segnalati dall'utente. Root cause:
 * createSplitSchedule() non controllava se un piano per quel beverage_type
 * esisteva gia' prima di crearne uno nuovo (solo idempotente sul piano
 * sorgente, non sulla destinazione); LinkMaintenanceScheduleMachineUnit non
 * controllava se un'altra scheda era gia' agganciata alla stessa macchina.
 * Entrambe corrette per non riprodurre il problema — questo comando ripulisce
 * i doppioni gia' esistenti.
 *
 * Tiene il piano piu' vecchio (created_at) come canonico: nei doppioni reali
 * aveva sempre dati uguali o piu' completi (frequency_days valorizzato,
 * lavaggi collegati pari o superiori). I lavaggi del doppione vengono
 * riassegnati al piano canonico, tranne quelli che duplicherebbero un
 * lavaggio gia' presente sullo stesso service_report_id (violerebbe
 * l'unique su lavaggi(service_report_id, maintenance_schedule_id)) — quelli
 * vengono cancellati come duplicati veri.
 */
class MergeDuplicateMaintenanceSchedules extends Command
{
    protected $signature = 'maintenance-schedules:merge-duplicates
        {--dry-run : Mostra cosa verrebbe unito senza scrivere nulla}';

    protected $description = "Unisce i piani di manutenzione duplicati (stesso cliente+macchina+tipo+beverage_type)";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $groups = MaintenanceSchedule::query()
            ->whereNotNull('machine_unit_id')
            ->get()
            ->groupBy(fn (MaintenanceSchedule $s) => implode('|', [
                $s->tenant_id, $s->customer_id, $s->machine_unit_id, $s->type, $s->beverage_type,
            ]))
            ->filter(fn ($group) => $group->count() > 1);

        $merged = 0;
        $lavaggiReassigned = 0;
        $lavaggiDeleted = 0;

        foreach ($groups as $group) {
            $sorted = $group->sortBy('created_at')->values();
            $canonical = $sorted->first();
            $duplicates = $sorted->slice(1);

            $this->line("Piano canonico: {$canonical->id} (creato {$canonical->created_at->toDateString()})");

            foreach ($duplicates as $duplicate) {
                $this->line("  Doppione: {$duplicate->id} (creato {$duplicate->created_at->toDateString()})");

                if ($dryRun) {
                    $count = Lavaggio::where('maintenance_schedule_id', $duplicate->id)->count();
                    $this->line("    [DRY RUN] {$count} lavaggi da riassegnare/deduplicare, poi il piano verrebbe eliminato.");

                    continue;
                }

                DB::transaction(function () use ($canonical, $duplicate, &$lavaggiReassigned, &$lavaggiDeleted) {
                    // Preferisce i valori del canonico, ma recupera quelli del
                    // doppione dove il canonico li ha vuoti (es. frequency_days).
                    if ($canonical->frequency_days === null && $duplicate->frequency_days !== null) {
                        $canonical->frequency_days = $duplicate->frequency_days;
                    }
                    if ($canonical->frequency === null && $duplicate->frequency !== null) {
                        $canonical->frequency = $duplicate->frequency;
                    }
                    if ($canonical->notes === null && $duplicate->notes !== null) {
                        $canonical->notes = $duplicate->notes;
                    }
                    $canonical->save();

                    Lavaggio::where('maintenance_schedule_id', $duplicate->id)->get()->each(function (Lavaggio $lavaggio) use ($canonical, &$lavaggiReassigned, &$lavaggiDeleted) {
                        $alreadyOnCanonical = $lavaggio->service_report_id
                            && Lavaggio::where('maintenance_schedule_id', $canonical->id)
                                ->where('service_report_id', $lavaggio->service_report_id)
                                ->exists();

                        if ($alreadyOnCanonical) {
                            $lavaggio->delete();
                            $lavaggiDeleted++;

                            return;
                        }

                        $lavaggio->maintenance_schedule_id = $canonical->id;
                        $lavaggio->save();
                        $lavaggiReassigned++;
                    });

                    $duplicate->delete();
                });

                $canonical->recalculateLavaggioNextDue();
            }

            $merged++;
        }

        $this->info(sprintf(
            '%sGruppi uniti: %d. Lavaggi riassegnati: %d. Lavaggi duplicati eliminati: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $merged,
            $lavaggiReassigned,
            $lavaggiDeleted,
        ));

        return self::SUCCESS;
    }
}
