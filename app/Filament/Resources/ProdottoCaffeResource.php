<?php

namespace App\Filament\Resources;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\ProdottoCaffeResource\Pages;
use App\Models\ProdottoCaffe;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Il listino da cui si scelgono i caffe' delle offerte caffe' (vedi
 * OffertaCaffeResource). Nel pannello e non in un file di configurazione perche'
 * i prezzi del caffe' cambiano, e cambiarli non deve voler dire un deploy.
 */
class ProdottoCaffeResource extends Resource
{
    protected static ?string $model = ProdottoCaffe::class;

    // Come il catalogo macchine: un listino di Alex, non dei singoli tenant.
    protected static bool $isScopedToTenant = false;

    // Senza, Filament pluralizza all'inglese: "prodotto-caffes".
    protected static ?string $slug = 'listino-caffe';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    // Nel Catalogo e non in Vendite accanto a Offerte caffe': e' un'anagrafica
    // di prezzi, si tocca quando cambia il listino (21/09/2026).
    protected static ?string $navigationGroup = 'Catalogo';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Listino caffè';

    protected static ?string $modelLabel = 'prodotto';

    protected static ?string $pluralModelLabel = 'Listino caffè';

    protected static bool $hasTitleCaseModelLabel = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('gruppo')
                        ->label('Gruppo')
                        ->options(ProdottoCaffe::GRUPPI)
                        ->default('caffe')
                        ->required(),
                    Forms\Components\TextInput::make('nome')
                        ->label('Prodotto')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('formato')
                        ->label('Formato')
                        ->placeholder('es. 1 kg, 250 g, 50 pz')
                        ->maxLength(50),
                    MoneyInput::make('prezzo')
                        ->label('Prezzo')
                        ->required(),
                    Forms\Components\TextInput::make('ordinamento')
                        ->label('Posizione')
                        ->helperText('Nell\'offerta i prodotti escono in quest\'ordine, dal più basso.')
                        ->numeric()
                        ->default(fn () => (int) ProdottoCaffe::max('ordinamento') + 10),
                    Forms\Components\Toggle::make('attivo')
                        ->label('In listino')
                        ->helperText('Spento: resta qui ma non compare più nelle nuove offerte.')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('ordinamento')
            ->defaultGroup(
                Tables\Grouping\Group::make('gruppo')
                    ->label('Gruppo')
                    ->getTitleFromRecordUsing(fn (ProdottoCaffe $record) => $record->etichettaGruppo())
            )
            ->columns([
                Tables\Columns\TextColumn::make('nome')->wrap()->label('Prodotto')->searchable(),
                Tables\Columns\TextColumn::make('formato')->visibleFrom('md')
                    ->label('Formato')
                    ->placeholder('da completare')
                    ->color(fn (ProdottoCaffe $record) => blank($record->formato) ? 'warning' : null),
                Tables\Columns\TextColumn::make('prezzo')->label('Prezzo')->money('EUR')->sortable(),
                Tables\Columns\IconColumn::make('attivo')->label('In listino')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('attivo')->label('In listino'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProdottiCaffe::route('/'),
            'create' => Pages\CreateProdottoCaffe::route('/create'),
            'edit' => Pages\EditProdottoCaffe::route('/{record}/edit'),
        ];
    }
}
