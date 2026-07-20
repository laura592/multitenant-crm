<?php

namespace App\Filament\Resources;

use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Clienti';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clienti';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('first_name')->label('Nome')->maxLength(255),
                    Forms\Components\TextInput::make('last_name')->label('Cognome')->maxLength(255),
                    Forms\Components\TextInput::make('company_name')->label('Ragione sociale')->maxLength(255)->columnSpanFull(),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                    Forms\Components\TextInput::make('mobile')->label('Cellulare')->tel()->maxLength(255),
                ]),
            Forms\Components\Section::make('Indirizzo')
                ->columns(2)
                ->schema(ItalianAddressFields::schema()),
            Forms\Components\Section::make('Posizione GPS')
                ->description('Usata per "Clienti vicini" e per riconoscere rapidamente dove ti trovi durante una visita.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('latitude')->label('Latitudine')->numeric()->step('0.0000001'),
                    Forms\Components\TextInput::make('longitude')->label('Longitudine')->numeric()->step('0.0000001'),
                    Forms\Components\Placeholder::make('locate_action')
                        ->label('')
                        ->content(new HtmlString(<<<'HTML'
                            <button type="button"
                                x-on:click="
                                    navigator.geolocation.getCurrentPosition(
                                        (pos) => {
                                            $wire.set('data.latitude', pos.coords.latitude.toFixed(7));
                                            $wire.set('data.longitude', pos.coords.longitude.toFixed(7));
                                        },
                                        (err) => alert('Impossibile ottenere la posizione: ' + err.message)
                                    )
                                "
                                class="fi-btn fi-btn-color-gray inline-flex items-center justify-center gap-1 rounded-lg border px-3 py-2 text-sm font-medium shadow-sm border-gray-300 bg-white text-gray-950 hover:bg-gray-50 dark:border-gray-600 dark:bg-white/5 dark:text-white dark:hover:bg-white/10">
                                Usa la mia posizione
                            </button>
                        HTML)),
                ]),
            Forms\Components\Section::make('Dati fiscali')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('tax_code')->label('Codice fiscale')->maxLength(255),
                    Forms\Components\TextInput::make('vat_number')->label('P.IVA')->maxLength(255),
                    Forms\Components\TextInput::make('sdi')->label('Codice SDI')->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')->label('Ragione sociale')->searchable(),
                Tables\Columns\TextColumn::make('full_name')->label('Referente')->searchable(['first_name', 'last_name']),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('mobile')->label('Cellulare'),
                Tables\Columns\TextColumn::make('city')->label('Città'),
                Tables\Columns\IconColumn::make('has_location')
                    ->label('GPS')
                    ->boolean()
                    ->state(fn (Customer $record) => $record->latitude !== null && $record->longitude !== null),
            ])
            ->filters([
                Tables\Filters\Filter::make('no_location')
                    ->label('Senza posizione GPS')
                    ->query(fn ($query) => $query->whereNull('latitude')->orWhereNull('longitude')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
