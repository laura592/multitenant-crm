<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteResource\Pages;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Quote;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Preventivi';

    protected static ?string $modelLabel = 'Preventivo';

    protected static ?string $pluralModelLabel = 'Preventivi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dati preventivo')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('number')
                        ->label('Numero')
                        ->required()
                        ->disabled(fn (?Quote $record) => $record !== null)
                        ->dehydrated()
                        ->default(fn () => Quote::nextNumberForTenant(Filament::getTenant()?->id)),
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
                            Forms\Components\TextInput::make('email')->label('Email')->email(),
                            Forms\Components\TextInput::make('mobile')->label('Cellulare'),
                        ]),
                    Forms\Components\DatePicker::make('date')
                        ->label('Data')
                        ->required()
                        ->default(now()),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options([
                            'bozza' => 'Bozza',
                            'inviato' => 'Inviato',
                            'accettato' => 'Accettato',
                            'rifiutato' => 'Rifiutato',
                        ])
                        ->default('bozza')
                        ->required(),
                    Forms\Components\Select::make('payment_method')
                        ->label('Metodo di pagamento')
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'slug')),
                    Forms\Components\TextInput::make('discount')
                        ->label('Sconto generale (%)')
                        ->numeric()
                        ->suffix('%')
                        ->default(0),
                    Forms\Components\Textarea::make('notes')
                        ->label('Note')
                        ->columnSpan(2),
                ]),
            Forms\Components\Section::make('Provvigione partner')
                ->columns(3)
                ->visible(fn () => ! (Filament::getTenant()?->is_master ?? true))
                ->schema([
                    Forms\Components\Select::make('commission_scenario')
                        ->label('Scenario')
                        ->options([
                            'A' => 'A - Segnalazione cliente',
                            'B' => 'B - Partner procura cliente, installazione Alex',
                            'C' => 'C - Partner installa in autonomia',
                        ])
                        ->default(fn () => Filament::getTenant()?->default_commission_scenario),
                    Forms\Components\Select::make('commission_status')
                        ->label('Stato fatturazione')
                        ->options([
                            'da_fatturare' => 'Da fatturare',
                            'fatturata' => 'Fatturata',
                            'pagata' => 'Pagata',
                        ]),
                    Forms\Components\TextInput::make('commission_invoice_number')->label('N. fattura'),
                    Forms\Components\Placeholder::make('commission_amount_display')
                        ->label('Importo provvigione (calcolato)')
                        ->content(fn (?Quote $record) => $record?->commission_amount !== null
                            ? number_format((float) $record->commission_amount, 2, ',', '.').' €'
                            : '—'),
                    Forms\Components\DatePicker::make('commission_invoiced_at')->label('Data fattura'),
                    Forms\Components\DatePicker::make('commission_paid_at')->label('Data pagamento'),
                ]),
            Forms\Components\Section::make('Righe preventivo')
                ->schema([
                    Forms\Components\Repeater::make('quoteProducts')
                        ->relationship('quoteProducts')
                        ->label('')
                        ->columns(5)
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Prodotto')
                                ->options(fn () => Product::query()->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('quantity')->label('Quantità')->numeric()->default(1)->required(),
                            Forms\Components\TextInput::make('price')->label('Prezzo (€)')->numeric()->prefix('€')->required(),
                            Forms\Components\TextInput::make('discount')->label('Sconto (%)')->numeric()->default(0),
                            Forms\Components\TextInput::make('tax')->label('IVA (%)')->numeric()->default(22),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel('Aggiungi riga')
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable(),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable(),
                Tables\Columns\TextColumn::make('date')->label('Data')->date(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR'),
                Tables\Columns\TextColumn::make('commission_amount')->label('Provvigione')->money('EUR')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('recalculate')
                    ->label('Ricalcola totali')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (Quote $record) => $record->updateTotal())
                    ->successNotificationTitle('Totali ricalcolati'),
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
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}
