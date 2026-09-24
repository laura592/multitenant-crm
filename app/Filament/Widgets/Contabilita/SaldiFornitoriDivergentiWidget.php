<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaPartitaAperta;

/**
 * Come SaldiDivergentiWidget, ma per i fornitori (24/09/2026): le partite
 * si importano per tutti e due, e anche di la' capita che il saldo
 * dichiarato da Eureka e la somma delle partite non coincidano.
 *
 * Qui non si telefona a nessuno: serve a non fidarsi del numero quando si
 * guarda l'esposizione verso i fornitori.
 */
class SaldiFornitoriDivergentiWidget extends SaldiDivergentiWidget
{
    protected static ?string $heading = 'Fornitori dove Eureka e le partite non tornano';

    protected static ?string $description = 'Il numero va verificato sul gestionale prima di usarlo.';

    protected static string $tipo = EurekaPartitaAperta::TIPO_FORNITORE;

    protected static string $etichettaAnagrafica = 'Fornitore';
}
