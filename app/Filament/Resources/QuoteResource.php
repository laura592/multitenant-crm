<?php

namespace App\Filament\Resources;

use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\QuoteResource\Pages;
use App\Filament\Resources\QuoteResource\RelationManagers\QuoteProductsRelationManager;
use App\Models\PaymentMethod;
use App\Models\Quote;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Dati preventivo')
                ->columns(4)
                ->schema([
                    TextEntry::make('number')->label('Numero'),
                    TextEntry::make('customer.full_name')->label('Cliente'),
                    TextEntry::make('date')->label('Data')->date(),
                    TextEntry::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => ucfirst($state)),
                    TextEntry::make('paymentMethodRelation.name')->label('Metodo di pagamento')->placeholder('—'),
                    TextEntry::make('discount')->label('Sconto generale')->suffix('%'),
                    TextEntry::make('notes')->label('Note')->placeholder('—')->columnSpanFull(),
                ]),
            InfolistSection::make('Totali')
                ->columns(3)
                ->schema([
                    TextEntry::make('subtotal')->label('Imponibile')->money('EUR'),
                    TextEntry::make('tax_total')->label('IVA')->money('EUR'),
                    TextEntry::make('total')->label('Totale')->money('EUR'),
                ]),
            InfolistSection::make('Provvigione partner')
                ->columns(3)
                ->visible(fn (Quote $record) => ! (bool) $record->tenant?->is_master)
                ->schema([
                    TextEntry::make('commission_scenario')->label('Scenario')->placeholder('—'),
                    TextEntry::make('commission_status')->label('Stato fatturazione')->placeholder('—'),
                    TextEntry::make('commission_amount')->label('Importo')->money('EUR')->placeholder('—'),
                ]),
        ]);
    }

    public static function form(Form $form): Form
    {
        $isCreating = $form->getOperation() === 'create';
        $canEditCommission = fn () => auth()->user()?->is_super_admin ?? false;

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
                            ...ItalianAddressFields::schema(),
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
                    // Le righe si gestiscono nel tab "Righe preventivo" (RelationManager),
                    // qui resta solo il totale calcolato come riferimento rapido.
                    Forms\Components\Placeholder::make('total_display')
                        ->label('Totale (calcolato)')
                        ->content(fn (?Quote $record) => $record?->total !== null
                            ? number_format((float) $record->total, 2, ',', '.').' €'
                            : '—')
                        ->visible(! $isCreating),
                ]),
            Forms\Components\Section::make('Provvigione partner')
                ->columns(3)
                // Solo in modifica, mai in creazione: e' un dato di back-office
                // calcolato dopo, non qualcosa da compilare mentre si crea il
                // preventivo (docs/architecture.md §4.3).
                ->visible(fn () => ! $isCreating && ! (bool) Filament::getTenant()?->is_master)
                ->schema([
                    Forms\Components\Select::make('commission_scenario')
                        ->label('Scenario')
                        ->options([
                            'A' => 'A - Segnalazione cliente',
                            'B' => 'B - Partner procura cliente, installazione Alex',
                            'C' => 'C - Partner installa in autonomia',
                        ])
                        ->default(fn () => Filament::getTenant()?->default_commission_scenario)
                        ->disabled(fn () => ! $canEditCommission()),
                    Forms\Components\Select::make('commission_status')
                        ->label('Stato fatturazione')
                        ->options([
                            'da_fatturare' => 'Da fatturare',
                            'fatturata' => 'Fatturata',
                            'pagata' => 'Pagata',
                        ])
                        // Solo lo staff Alex (is_super_admin) puo' segnare una
                        // provvigione come fatturata/pagata: il partner la vede
                        // ma non può auto-certificarsi il pagamento.
                        ->disabled(fn () => ! $canEditCommission()),
                    Forms\Components\TextInput::make('commission_invoice_number')
                        ->label('N. fattura')
                        ->disabled(fn () => ! $canEditCommission()),
                    Forms\Components\Placeholder::make('commission_amount_display')
                        ->label('Importo provvigione (calcolato)')
                        ->content(fn (?Quote $record) => $record?->commission_amount !== null
                            ? number_format((float) $record->commission_amount, 2, ',', '.').' €'
                            : '—'),
                    Forms\Components\DatePicker::make('commission_invoiced_at')
                        ->label('Data fattura')
                        ->disabled(fn () => ! $canEditCommission()),
                    Forms\Components\DatePicker::make('commission_paid_at')
                        ->label('Data pagamento')
                        ->disabled(fn () => ! $canEditCommission()),
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('recalculate')
                        ->label('Ricalcola totali')
                        ->icon('heroicon-o-arrow-path')
                        ->action(fn (Quote $record) => $record->updateTotal())
                        ->successNotificationTitle('Totali ricalcolati'),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            QuoteProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'view' => Pages\ViewQuote::route('/{record}'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}
