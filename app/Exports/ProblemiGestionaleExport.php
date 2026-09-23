<?php

namespace App\Exports;

use App\Models\Tenant;
use App\Support\Gestionale\ControlloPaganteFattura;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * I rapportini nel gestionale con qualcosa che non torna, un foglio per tipo
 * di problema (ControlloPaganteFattura::CATEGORIE).
 *
 * Tutto in un foglio solo non si leggeva: le schede da correggere, che sono
 * una ventina, sparivano fra le centinaia di rapportini senza fattura. Il
 * nome del foglio dice anche quante righe ci sono.
 */
class ProblemiGestionaleExport implements WithMultipleSheets
{
    public function __construct(private readonly Tenant $tenant) {}

    /** @return array<int, FoglioProblemiGestionale> */
    public function sheets(): array
    {
        $righe = collect(iterator_to_array(ControlloPaganteFattura::righeEsportazione($this->tenant), false))
            ->groupBy('Categoria');

        $fogli = [];

        foreach (ControlloPaganteFattura::CATEGORIE as $categoria => [$titolo, $colonne]) {
            $gruppo = $righe->get($categoria, collect());

            // Un foglio vuoto per ogni problema che non c'e' e' solo rumore:
            // si scrivono quelli con qualcosa dentro (23/09/2026).
            if ($gruppo->isEmpty()) {
                continue;
            }

            $fogli[] = new FoglioProblemiGestionale($titolo.' ('.$gruppo->count().')', $colonne, $gruppo);
        }

        // Excel vuole almeno un foglio, e "non c'e' niente da controllare" e'
        // gia' una risposta: il file si scarica lo stesso.
        return $fogli ?: [new FoglioProblemiGestionale('Niente da controllare', ['Rapportino'], collect())];
    }
}
