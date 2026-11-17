<?php

namespace App\Exports;

use App\Models\LetturaCasetta;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Il rendiconto delle casette dell'acqua, un foglio per casetta (30/10/2026).
 *
 * Un foglio per casetta e non un elenco unico perche' il rendiconto si manda
 * a un Comune alla volta, e perche' i contatori non sono confrontabili fra
 * loro: ogni casetta ha la sua unita' (machine_units.unita_contatore), e un
 * totale che somma metri cubi e litri non vorrebbe dire niente. Oggi contano
 * tutte in metri cubi, ma il campo resta per casetta perche' e' una
 * caratteristica del contatore, non dell'azienda.
 */
class LettureCasetteExport implements Export, WithMultipleSheets
{
    /** @param  Collection<int, LetturaCasetta>  $letture */
    public function __construct(private readonly Collection $letture) {}

    /** @return array<int, FoglioRendicontoCasetta> */
    public function sheets(): array
    {
        $fogli = $this->letture
            // Dalla piu' vecchia: il rendiconto si legge in avanti, e ogni
            // riga ha senso solo dopo quella che la precede.
            ->sortBy([['data', 'asc'], ['created_at', 'asc']])
            ->groupBy('machine_unit_id')
            ->map(function (Collection $gruppo): FoglioRendicontoCasetta {
                $prima = $gruppo->first();
                $casetta = $prima?->machineUnit;

                return new FoglioRendicontoCasetta(
                    $casetta ? (string) $casetta->serial_number : 'Casetta',
                    $prima?->customer?->company_name,
                    $gruppo->values(),
                );
            })
            ->values()
            ->all();

        // Excel vuole almeno un foglio, e "nessuna lettura nel periodo" e'
        // una risposta: il file si scarica lo stesso.
        return $fogli ?: [new FoglioRendicontoCasetta('Nessuna lettura', null, collect())];
    }
}
