<?php

namespace App\Support\Controlli;

use App\Models\MachineUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le macchine che Eureka vede lavorare da un cliente diverso da quello a
 * cui sono intestate, e su cui nessuno ha ancora deciso. Finche' la
 * decisione non arriva i rapportini finiscono sull'anagrafica sbagliata —
 * e' la Casadio ancora intestata a Isola Blu.
 *
 * Il suggerimento lo scrive l'import (spostamento_suggerito_customer_id);
 * spostamento_scartato e' la risposta "no, sta dov'e'": una volta data, il
 * controllo non deve piu' richiederla.
 */
class MacchineForseSpostate
{
    /**
     * La condizione, su una query qualsiasi: la stessa per il conteggio
     * della scheda e per il filtro della tabella macchine. Due righe di
     * where sono anche due righe facili da copiare male, ed e' proprio
     * copiandole che il numero sulla scheda e le righe nell'elenco
     * prendono strade diverse.
     *
     * @param  Builder<MachineUnit>  $query
     * @return Builder<MachineUnit>
     */
    public static function applica(Builder $query): Builder
    {
        return $query
            ->whereNotNull('spostamento_suggerito_customer_id')
            ->whereNull('spostamento_scartato');
    }

    /** @return Builder<MachineUnit> */
    public static function query(): Builder
    {
        return self::applica(MachineUnit::query());
    }
}
