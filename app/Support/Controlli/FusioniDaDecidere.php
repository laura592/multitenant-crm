<?php

namespace App\Support\Controlli;

use App\Models\MachineUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Due matricole che potrebbero essere la stessa macchina, ancora da
 * decidere a mano.
 *
 * Dove Eureka elenca entrambe le schede puo' trattarsi davvero di due
 * apparecchi, quindi la fusione non si fa da sola: fusione_suggerita_id e'
 * il sospetto, fusa_in_id e' la decisione presa. Quando la seconda c'e', la
 * macchina e' fuori dal controllo.
 */
class FusioniDaDecidere
{
    /**
     * La condizione, su una query qualsiasi: la stessa per il conteggio
     * della scheda e per il filtro della tabella macchine, cosi' il numero
     * annunciato e le righe che si aprono restano per costruzione lo
     * stesso insieme.
     *
     * @param  Builder<MachineUnit>  $query
     * @return Builder<MachineUnit>
     */
    public static function applica(Builder $query): Builder
    {
        return $query
            ->whereNotNull('fusione_suggerita_id')
            ->whereNull('fusa_in_id');
    }

    /** @return Builder<MachineUnit> */
    public static function query(): Builder
    {
        return self::applica(MachineUnit::query());
    }
}
