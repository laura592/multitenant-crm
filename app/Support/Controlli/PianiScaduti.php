<?php

namespace App\Support\Controlli;

use App\Models\MaintenanceSchedule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lavaggi e manutenzioni la cui data e' passata senza che arrivasse un
 * rapportino. E' il piano dell'impianto acqua di Patatrac, fermo dal 5
 * settembre e scoperto per caso mentre si guardava un'altra cosa.
 *
 * IL PUNTO DELICATO: inCorso(). Chi chiude per la stagione mette il piano
 * in pausa, e un piano in pausa per definizione non scade: senza quel
 * filtro il controllo avrebbe messo in fila tutte le chiusure invernali e
 * nessuno lo avrebbe piu' guardato. Lo stesso vale per i piani chiusi, che
 * inCorso() lascia fuori.
 */
class PianiScaduti
{
    /**
     * La condizione, su una query qualsiasi.
     *
     * La stessa condizione conta sulla scheda dei controlli e filtra
     * l'elenco dei piani: una sola, perche' due copie prima o poi si
     * disallineano e il numero sulla scheda smette di corrispondere alle
     * righe che si vedono.
     *
     * @param  Builder<MaintenanceSchedule>  $query
     * @return Builder<MaintenanceSchedule>
     */
    public static function applica(Builder $query): Builder
    {
        return $query
            ->inCorso()
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<', now());
    }

    /** @return Builder<MaintenanceSchedule> */
    public static function query(): Builder
    {
        return self::applica(MaintenanceSchedule::query());
    }
}
