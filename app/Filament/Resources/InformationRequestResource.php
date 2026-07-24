<?php

namespace App\Filament\Resources;

use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\InformationRequestResource\Pages;
use App\Models\InformationRequest;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InformationRequestResource extends Resource
{
    protected static ?string $model = InformationRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Richieste informazioni';

    protected static ?string $modelLabel = 'Richiesta informazioni';

    protected static ?string $pluralModelLabel = 'Richieste informazioni';

    /**
     * Stesso conteggio di PrioritaWidget ("Richieste da gestire"), ma visibile
     * direttamente in sidebar senza dover aprire la Dashboard.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = InformationRequest::whereIn('status', ['nuova', 'in_lavorazione'])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Richiesta')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('number')
                        ->label('Numero')
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit')
                        ->default(fn () => InformationRequest::nextNumberForTenant(Filament::getTenant()?->id)),
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
                            ...ItalianAddressFields::schema(),
                        ]),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(static::statusLabels())
                        ->default('nuova')
                        ->required(),
                    Forms\Components\Select::make('products')
                        ->label('Prodotti di interesse')
                        ->relationship('products', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    Forms\Components\Textarea::make('request_details')
                        ->label('Dettagli richiesta')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Gestione')
                ->schema([
                    Forms\Components\Select::make('handled_by_user_id')
                        ->label('Gestita da')
                        ->relationship('handledByUser', 'name')
                        ->searchable()
                        ->preload(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('handledByUser.name')->label('Gestita da')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Ricevuta il')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
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
            'index' => Pages\ListInformationRequests::route('/'),
            'create' => Pages\CreateInformationRequest::route('/create'),
            'edit' => Pages\EditInformationRequest::route('/{record}/edit'),
        ];
    }

    /**
     * Centralizzato come QuoteResource::statusLabels(): la colonna tabella
     * mostrava il valore grezzo del DB (es. "in_lavorazione") invece di
     * un'etichetta leggibile, a differenza delle altre risorse a stato.
     */
    public static function statusLabels(): array
    {
        return [
            'nuova' => 'Nuova',
            'in_lavorazione' => 'In lavorazione',
            'gestita' => 'Gestita',
            'chiusa' => 'Chiusa',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'nuova' => 'gray',
            'in_lavorazione' => 'warning',
            'gestita' => 'success',
            'chiusa' => 'success',
        ];
    }
}
