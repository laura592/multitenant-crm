<?php

namespace App\Filament\Forms;

use App\Models\MunicipalityPostalCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;

/**
 * Schema condiviso via/CAP/città/provincia con autocomplete sui comuni
 * italiani (MunicipalityPostalCode, dataset ISTAT): selezionando un comune si
 * compilano CAP e provincia coerenti, evitando accoppiate errate (es. CAP di
 * Milano con provincia RM). I 3 campi restano comunque testo libero
 * modificabile a mano, per indirizzi esteri o casi non a catalogo.
 *
 * Usato in CustomerResource, TenantResource e ovunque si crei un
 * cliente/azienda al volo (createOptionForm in QuoteResource,
 * InformationRequestResource) cosi' i vari punti di inserimento restano
 * coerenti tra loro.
 */
class ItalianAddressFields
{
    public static function schema(): array
    {
        return [
            TextInput::make('street')->label('Via')->maxLength(255)->columnSpanFull(),
            Select::make('municipality_lookup')
                ->label('Cerca comune o CAP')
                ->live()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => MunicipalityPostalCode::query()
                    ->where('municipality_name', 'like', "%{$search}%")
                    ->orWhere('postal_code', 'like', "{$search}%")
                    ->orderBy('municipality_name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (MunicipalityPostalCode $row) => [$row->id => $row->label]))
                ->afterStateUpdated(function (?string $state, Set $set) {
                    if ($row = MunicipalityPostalCode::find($state)) {
                        $set('city', $row->municipality_name);
                        $set('province', $row->province_code);
                        $set('postal_code', $row->postal_code);
                    }
                })
                ->dehydrated(false)
                ->columnSpanFull()
                ->helperText('Compila automaticamente città, CAP e provincia. I campi restano modificabili per indirizzi esteri o casi particolari.'),
            TextInput::make('postal_code')->label('CAP')->maxLength(10),
            TextInput::make('city')->label('Città')->maxLength(255),
            TextInput::make('province')->label('Provincia')->maxLength(255),
        ];
    }
}
