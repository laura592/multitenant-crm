<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\Select;
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
            // Come si fattura a questo cliente. Per quasi tutti e' scontato,
            // ma non per tutti: al NATO Stability Policing Centre le fatture
            // vanno non imponibili ex art. 72 c.1 DPR 633/1972, e prima quel
            // dato viveva solo sul PDF che avevano mandato (29/09/2026).
            Select::make('regime_iva')
                ->label('Regime IVA')
                ->options([
                    'soggetto_iva' => 'Soggetto IVA',
                    'esente' => 'Esente / non imponibile',
                    'privato' => 'Privato',
                ])
                ->default('soggetto_iva')
                ->native(false)
                ->live(),
            TextInput::make('esenzione_articolo')
                ->label('Articolo di esenzione')
                ->placeholder('Art. 72 c.1 DPR 633/1972')
                ->maxLength(255)
                ->helperText('Va in fattura: senza, chi la emette non sa perche\' non e\' imponibile.')
                ->visible(fn (Get $get) => $get('regime_iva') === 'esente')
                ->required(fn (Get $get) => $get('regime_iva') === 'esente'),
        ];
    }
}
