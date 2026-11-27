<?php

namespace App\Support\Controlli;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * I clienti che hanno gia' un lavoro in ballo e non hanno ne' P.IVA ne'
 * codice fiscale: non si possono mandare in Eureka, quindi non si puo'
 * fatturare quello che e' gia' stato fatto.
 *
 * IL PUNTO DELICATO: "senza dati fiscali" da solo contava 954 anagrafiche
 * (24/11/2026). Sono i lead arrivati dal sito e i 101 soci ABA: un dato
 * fiscale non ce l'hanno perche' non l'hanno mai avuto, e per ora va bene
 * cosi'. Un numero cosi' grande non e' un controllo, e' uno sfondo. Il
 * segnale e' la contraddizione: gli abbiamo gia' fatto un preventivo o un
 * rapportino e non sappiamo a chi intestarlo.
 */
class ClientiSenzaDatiFiscali
{
    /**
     * La condizione, su una query qualsiasi.
     *
     * Esiste separata da query() perche' la stessa condizione serve al
     * conteggio della scheda e al filtro della tabella clienti: se fossero
     * due query scritte due volte, il giorno in cui una cambia il numero
     * sulla scheda e le righe nell'elenco smettono di combaciare, ed e'
     * peggio di non avere il filtro.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public static function applica(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('vat_number')->orWhereRaw("TRIM(vat_number) = ''"))
            ->where(fn (Builder $q) => $q->whereNull('tax_code')->orWhereRaw("TRIM(tax_code) = ''"))
            ->where(fn (Builder $q) => $q->whereHas('quotes')->orWhereHas('serviceReports'));
    }

    /** @return Builder<Customer> */
    public static function query(): Builder
    {
        return self::applica(Customer::query());
    }
}
