<?php

namespace App\Support\Controlli;

use App\Models\ServiceReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Il lavoro e' fatto, il rapportino e' chiuso, ma in Eureka non e' mai
 * arrivato: finche' non ci arriva, non si fattura.
 *
 * IL PUNTO DELICATO: i rapportini nati DA Eureka (source = eureka) sono
 * lo specchio di una scheda che li' esiste gia' — chiedere di inviarli
 * vorrebbe dire duplicarli. E gestionale_sync_status non nullo vuol dire
 * che un invio c'e' stato: se e' andato male lo dice l'elenco dei
 * rapportini, non questo controllo, che e' per quelli di cui nessuno si e'
 * mai accorto.
 */
class RapportiniMaiInviati
{
    /**
     * La condizione, su una query qualsiasi: una sola, usata dal conteggio
     * della scheda e dal filtro della tabella rapportini. Qui le
     * condizioni sono quattro e intrecciate: riscriverle nel filtro
     * sarebbe il modo piu' rapido di ritrovarsi con due numeri diversi.
     *
     * @param  Builder<ServiceReport>  $query
     * @return Builder<ServiceReport>
     */
    public static function applica(Builder $query): Builder
    {
        return $query
            ->where('source', '!=', 'eureka')
            ->whereIn('status', ServiceReport::CLOSED_STATUSES)
            ->where('status', '!=', 'in_gestionale')
            ->whereNull('gestionale_sync_status');
    }

    /** @return Builder<ServiceReport> */
    public static function query(): Builder
    {
        return self::applica(ServiceReport::query());
    }
}
