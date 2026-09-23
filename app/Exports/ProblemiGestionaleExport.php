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
            $fogli[] = new FoglioProblemiGestionale($titolo.' ('.$gruppo->count().')', $colonne, $gruppo);
        }

        return $fogli;
    }
}
