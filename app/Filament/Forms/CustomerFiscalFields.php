<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

/**
 * Codice fiscale / P.IVA: obbligatorio almeno uno dei due in creazione,
 * altrimenti qualsiasi utente puo' creare un cliente senza alcun dato
 * fiscale. Non e' invece required in modifica: molti clienti storici non
 * ne hanno nessuno dei due, e altrimenti non si potrebbe piu' salvare
 * nessun'altra modifica su quei record senza recuperare prima il dato
 * fiscale mancante.
 *
 * Usato in CustomerResource e ovunque si crei un cliente al volo
 * (createOptionForm in QuoteResource, InformationRequestResource,
 * ServiceReportResource) cosi' i vari punti di inserimento restano coerenti.
 */
class CustomerFiscalFields
{
    public static function schema(): array
    {
        return [
            TextInput::make('tax_code')->label('Codice fiscale')->maxLength(255)
                ->live(onBlur: true)
                ->required(fn (Get $get, string $operation) => $operation === 'create' && blank($get('vat_number')))
                ->helperText('Obbligatorio il codice fiscale oppure la P.IVA.'),
            TextInput::make('vat_number')->label('P.IVA')->maxLength(255)
                ->live(onBlur: true)
                ->required(fn (Get $get, string $operation) => $operation === 'create' && blank($get('tax_code'))),
        ];
    }
}
