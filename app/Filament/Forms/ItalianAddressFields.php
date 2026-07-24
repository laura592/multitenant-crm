<?php

namespace App\Filament\Forms;

use App\Models\MunicipalityPostalCode;
use App\Support\Geocoder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
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
    /**
     * @param bool $withGeocoding se true, alla selezione del comune stima anche
     *   latitude/longitude via OpenStreetMap (Geocoder) — solo se non già
     *   impostate. Attivo solo dove esistono i campi latitude/longitude nel
     *   form (oggi CustomerResource, campi nascosti).
     * @param string $streetField nome della colonna via/indirizzo: "street" quasi
     *   ovunque, ma "address" su Supplier (colonna storica).
     */
    public static function schema(bool $withGeocoding = false, string $streetField = 'street'): array
    {
        return [
            TextInput::make($streetField)->label('Via')->maxLength(255)->columnSpanFull(),
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
                ->afterStateUpdated(function (?string $state, Set $set, Get $get) use ($withGeocoding, $streetField) {
                    if (! $row = MunicipalityPostalCode::find($state)) {
                        return;
                    }

                    $set('city', $row->municipality_name);
                    $set('province', $row->province_code);
                    $set('postal_code', $row->postal_code);

                    if (! $withGeocoding || filled($get('latitude')) || filled($get('longitude'))) {
                        return;
                    }

                    $coords = Geocoder::geocodeBestEffort([
                        collect([
                            $get($streetField),
                            "{$row->postal_code} {$row->municipality_name}",
                            $row->province_code,
                            'Italia',
                        ])->filter()->implode(', '),
                        collect([
                            "{$row->postal_code} {$row->municipality_name}",
                            $row->province_code,
                            'Italia',
                        ])->filter()->implode(', '),
                        collect([
                            $row->municipality_name,
                            $row->province_code,
                            'Italia',
                        ])->filter()->implode(', '),
                    ]);

                    if ($coords) {
                        $set('latitude', $coords['lat']);
                        $set('longitude', $coords['lng']);
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
