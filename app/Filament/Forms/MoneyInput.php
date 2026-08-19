<?php

namespace App\Filament\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * TextInput per importi in euro in formato italiano (punto per le
 * migliaia, virgola per i decimali) mentre si scrive. Un normale
 * ->numeric() forza type="number", che il browser mostra sempre in
 * stile anglosassone (1234.56, niente separatore delle migliaia): qui
 * si forza type="text" con una maschera Alpine, e si converte la
 * virgola in punto prima di validare/salvare cosi' il valore in DB
 * resta un decimale corretto.
 */
class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->type('text')
            ->mask(RawJs::make(<<<'JS'
                $money($input, ',', '.')
                JS))
            ->stripCharacters('.')
            ->mutateStateForValidationUsing(
                fn (mixed $state) => is_string($state) ? str_replace(',', '.', $state) : $state
            )
            ->mutateDehydratedStateUsing(
                fn (mixed $state) => (is_string($state) && $state !== '') ? (float) str_replace(',', '.', $state) : $state
            );
    }
}
