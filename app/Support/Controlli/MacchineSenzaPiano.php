<?php

namespace App\Support\Controlli;

use App\Models\MachineUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le macchine che si manutengono davvero ma che nessun piano tiene: se
 * saltano un giro non se ne accorge nessuno. E' il caso dell'impianto acqua
 * di Patatrac, lavorato il 9 ottobre e il 24 novembre senza che nulla lo
 * registrasse.
 *
 * IL PUNTO DELICATO: "installata e senza piano" da solo contava 705
 * macchine (24/11/2026). Moltissime non devono avere un piano — un
 * macinino, una macchina a comodato che si tocca solo se si rompe — e un
 * numero cosi' grande nasconde il caso vero invece di mostrarlo. Il segnale
 * e' la contraddizione: ci siamo andati sopra con la manutenzione
 * programmata o la sanificazione, e non l'abbiamo pianificato.
 */
class MacchineSenzaPiano
{
    /**
     * La condizione, su una query qualsiasi.
     *
     * Una sola, condivisa fra il conteggio della scheda e il filtro della
     * tabella macchine: due query scritte due volte sono due numeri che un
     * giorno smettono di combaciare.
     *
     * MachineUnit non ha una relazione verso i piani (il legame vive
     * dall'altra parte, MaintenanceSchedule->machine_unit_id), quindi la
     * domanda si fa con due sottoquery.
     *
     * @param  Builder<MachineUnit>  $query
     * @return Builder<MachineUnit>
     */
    public static function applica(Builder $query): Builder
    {
        return $query
            ->where('status', 'installata')
            ->whereNotExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('maintenance_schedules')
                ->whereColumn('maintenance_schedules.machine_unit_id', 'machine_units.id'))
            ->whereExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('service_reports')
                ->whereColumn('service_reports.machine_unit_id', 'machine_units.id')
                ->whereNull('service_reports.deleted_at')
                ->whereIn('service_reports.intervention_type', ['manutenzione_ordinaria', 'sanificazione']));
    }

    /** @return Builder<MachineUnit> */
    public static function query(): Builder
    {
        return self::applica(MachineUnit::query());
    }
}
