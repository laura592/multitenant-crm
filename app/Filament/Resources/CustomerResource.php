<?php

namespace App\Filament\Resources;

use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\CustomerFiscalFields;
use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\LavaggiRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\MacchinariRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\QuotesRelationManager;
use App\Filament\Resources\CustomerResource\RelationManagers\ServiceReportsRelationManager;
use App\Models\Customer;
use App\Support\DisplayName;
use App\Support\Gestionale\EurekaClient;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                ->extraAttributes(['data-tour' => 'customers-field-anagrafica'])
                ->schema([
                    // Nessuno dei due era obbligatorio: si poteva salvare un
                    // cliente senza alcun nome/ragione sociale, pur essendo la
                    // base di full_name usato ovunque nell'app. Richiesto
                    // almeno uno dei due (ragione sociale B2B, o nome
                    // referente se non c'e' un'azienda).
                    Forms\Components\TextInput::make('first_name')->label('Nome')->maxLength(255)
                        ->live(onBlur: true)
                        ->required(fn (Forms\Get $get) => blank($get('company_name'))),
                    Forms\Components\TextInput::make('last_name')->label('Cognome')->maxLength(255),
                    Forms\Components\TextInput::make('company_name')->label('Ragione sociale')->maxLength(255)->columnSpanFull()
                        ->live(onBlur: true)
                        ->required(fn (Forms\Get $get) => blank($get('first_name')))
                        ->helperText('Obbligatoria la ragione sociale oppure almeno il nome del referente.'),
                    ...CustomerContactFields::schema(),
                    Forms\Components\TextInput::make('website')->label('Sito web')->url()->maxLength(255),
                ]),
            Forms\Components\Section::make('Indirizzo')
                ->columns(3)
                ->extraAttributes(['data-tour' => 'customers-field-address'])
                ->schema(ItalianAddressFields::schema(withGeocoding: true)),
            Forms\Components\Hidden::make('latitude'),
            Forms\Components\Hidden::make('longitude'),
            Forms\Components\Section::make('Dati fiscali')
                ->columns(3)
                ->schema([
                    ...CustomerFiscalFields::schema(),
                    Forms\Components\TextInput::make('sdi')->label('Codice SDI')->maxLength(255),
                    Forms\Components\TextInput::make('pec')->label('PEC')->email()->maxLength(255),
                    Forms\Components\TextInput::make('gestionale_code')
                        ->label('Codice gestionale (Eureka)')
                        ->numeric()
                        ->unique(ignoreRecord: true)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Usa "Cerca su Eureka" nella tabella per collegarlo.'),
                    Forms\Components\Select::make('billing_customer_id')
                        ->label('Fatturare a')
                        ->relationship('billingCustomer', 'company_name', modifyQueryUsing: fn ($query, ?Customer $record) => $query
                            ->when($record, fn ($q) => $q->whereKeyNot($record->id))
                            ->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::titleCase($record->full_name))
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->columnSpanFull()
                        ->helperText('Lascia vuoto se il cliente paga per se stesso. Imposta un altro cliente se qualcun altro paga al posto suo (es. un gestore che ha messo una macchina in comodato presso questo cliente): preventivi e rapportini restano su questo cliente, ma verranno intestati/inviati al cliente scelto qui.')
                        ->extraAttributes(['data-tour' => 'customers-field-billing']),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Anagrafica')
                ->columns(2)
                ->schema([
                    TextEntry::make('company_name')->label('Ragione sociale')->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('full_name')->label('Referente')->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('emails')->label('Email')->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('phones')->label('Telefoni')->listWithLineBreaks()->placeholder('—'),
                    TextEntry::make('website')->label('Sito web')->placeholder('—')
                        ->url(fn (Customer $record) => $record->website, shouldOpenInNewTab: true),
                ]),
            InfolistSection::make('Indirizzo')
                ->columns(3)
                ->schema([
                    TextEntry::make('street')->label('Via')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('postal_code')->label('CAP')->placeholder('—'),
                    TextEntry::make('city')->label('Città')->placeholder('—'),
                    TextEntry::make('province')->label('Provincia')->placeholder('—'),
                ]),
            InfolistSection::make('Dati fiscali')
                ->columns(3)
                ->schema([
                    TextEntry::make('tax_code')->label('Codice fiscale')->placeholder('—'),
                    TextEntry::make('vat_number')->label('P.IVA')->placeholder('—'),
                    TextEntry::make('sdi')->label('Codice SDI')->placeholder('—'),
                    TextEntry::make('pec')->label('PEC')->placeholder('—'),
                    TextEntry::make('billingCustomer.full_name')->label('Fatturare a')->placeholder('Se stesso')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                ]),
            InfolistSection::make('Gestionale')
                ->columns(3)
                ->schema([
                    TextEntry::make('source')
                        ->label('Origine')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === Customer::SOURCE_GESTIONALE ? 'Gestionale' : 'App'),
                    TextEntry::make('gestionale_code')->label('Codice gestionale')->placeholder('—'),
                    TextEntry::make('approved_for_gestionale_at')->label('Pronto per invio dal')->date()->placeholder('—'),
                    TextEntry::make('sent_to_gestionale_at')->label('Inviato il')->date()->placeholder('—'),
                    TextEntry::make('gestionale_review_flagged_at')->label('Da aggiornare su Eureka dal')->date()->placeholder('—'),
                    TextEntry::make('gestionale_review_note')->label('Cosa è cambiato')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')->label('Ragione sociale')->searchable()->sortable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('emails')
                    ->label('Email')
                    ->listWithLineBreaks()
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereRaw('JSON_SEARCH(emails, "one", ?) IS NOT NULL', ["%{$search}%"])),
                Tables\Columns\TextColumn::make('phones')
                    ->label('Telefoni')
                    ->listWithLineBreaks()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('website')
                    ->label('Sito web')
                    ->placeholder('—')
                    ->url(fn (Customer $record) => $record->website, shouldOpenInNewTab: true)
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('city')->label('Città')->sortable(),
                Tables\Columns\IconColumn::make('source')
                    ->label('Eureka')
                    // Uniformato con la colonna "Da Eureka" di MachineUnitResource
                    // e "Eureka" di ServiceReportResource: icona invece del badge
                    // testuale "Gestionale"/"App", stesso sì/no a colpo d'occhio.
                    ->icon(fn (Customer $record) => match (true) {
                        $record->source === Customer::SOURCE_GESTIONALE, $record->sent_to_gestionale_at !== null => 'heroicon-o-check-circle',
                        default => 'heroicon-o-x-circle',
                    })
                    ->color(fn (Customer $record) => match (true) {
                        $record->source === Customer::SOURCE_GESTIONALE => 'gray',
                        $record->sent_to_gestionale_at !== null => 'success',
                        $record->approved_for_gestionale_at !== null => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (Customer $record) => match (true) {
                        $record->source === Customer::SOURCE_GESTIONALE => 'Origine gestionale',
                        $record->sent_to_gestionale_at !== null => 'Inviato al gestionale il '.$record->sent_to_gestionale_at->format('d/m/Y'),
                        $record->approved_for_gestionale_at !== null => 'Pronto per invio al gestionale (preventivo accettato)',
                        default => 'Non ancora inviato al gestionale',
                    }),
            ])
            ->defaultSort('company_name')
            ->filters([
                Tables\Filters\Filter::make('no_location')
                    ->label('Senza posizione GPS')
                    ->query(fn ($query) => $query->where(function ($inner) {
                        $inner->whereNull('latitude')->orWhereNull('longitude');
                    })),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Origine')
                    ->options([
                        Customer::SOURCE_GESTIONALE => 'Gestionale',
                        Customer::SOURCE_APP => 'App',
                    ]),
                Tables\Filters\Filter::make('ready_for_gestionale')
                    ->label('Pronti per invio al gestionale')
                    ->query(fn ($query) => $query
                        ->where('source', Customer::SOURCE_APP)
                        ->whereNotNull('approved_for_gestionale_at')
                        ->whereNull('sent_to_gestionale_at')),
                Tables\Filters\Filter::make('da_aggiornare_gestionale')
                    ->label('Da aggiornare su Eureka')
                    ->query(fn ($query) => $query->whereNotNull('gestionale_review_flagged_at')),
                Tables\Filters\Filter::make('gestionale_suggested_code')
                    ->label('Collegamento proposto')
                    ->query(fn ($query) => $query->whereNotNull('gestionale_suggested_code')),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('cerca_eureka')
                        ->label('Cerca su Eureka')
                        ->icon('heroicon-o-magnifying-glass')
                        ->visible(fn (): bool => Filament::getTenant()?->hasGestionaleEurekaCredentials() ?? false)
                        ->fillForm(fn (Customer $record): array => ['gestionale_code' => $record->gestionale_code])
                        ->form([
                            Forms\Components\Select::make('gestionale_code')
                                ->label('Cliente Eureka')
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search): array {
                                    $client = new EurekaClient(Filament::getTenant());

                                    return collect($client->cercaClienti($search))
                                        ->mapWithKeys(fn (array $item) => [
                                            $item['id'] => trim(collect([
                                                $item['rag_sociale_1'] ?? null,
                                                filled($item['partita_iva'] ?? null) ? "P.IVA {$item['partita_iva']}" : null,
                                                filled($item['citta'] ?? null) ? trim($item['citta']) : null,
                                            ])->filter()->implode(' — ')),
                                        ])
                                        ->all();
                                })
                                ->getOptionLabelUsing(fn ($value) => "Codice gestionale: {$value}")
                                ->required()
                                ->helperText('Digita la ragione sociale per cercare nell\'anagrafica Eureka.'),
                        ])
                        ->action(function (array $data, Customer $record) {
                            try {
                                $record->update(['gestionale_code' => $data['gestionale_code']]);
                                Notification::make()->title('Codice gestionale salvato')->success()->send();
                            } catch (\Illuminate\Database\QueryException) {
                                Notification::make()->title('Codice già usato da un altro cliente')->danger()->send();
                            }
                        }),
                    Tables\Actions\Action::make('conferma_collegamento_gestionale')
                        ->label(fn (Customer $record) => 'Conferma collegamento proposto: '.($record->gestionale_suggested_label ?? "#{$record->gestionale_suggested_code}"))
                        ->icon('heroicon-o-link')
                        ->color('warning')
                        ->visible(fn (Customer $record): bool => $record->gestionale_suggested_code !== null)
                        ->requiresConfirmation()
                        ->modalDescription('Il sync automatico ha trovato questo possibile collegamento su Eureka. Confermi?')
                        ->action(function (Customer $record) {
                            $record->update([
                                'gestionale_code' => $record->gestionale_suggested_code,
                                'gestionale_suggested_code' => null,
                                'gestionale_suggested_label' => null,
                            ]);
                            Notification::make()->title('Collegamento confermato')->success()->send();
                        }),
                    Tables\Actions\Action::make('scarta_collegamento_gestionale')
                        ->label('Scarta collegamento proposto')
                        ->icon('heroicon-o-x-mark')
                        ->visible(fn (Customer $record): bool => $record->gestionale_suggested_code !== null)
                        ->requiresConfirmation()
                        ->action(fn (Customer $record) => $record->update([
                            'gestionale_suggested_code' => null,
                            'gestionale_suggested_label' => null,
                        ])),
                    Tables\Actions\Action::make('segna_inviato_gestionale')
                        ->label('Segna come inviato al gestionale')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->visible(fn (Customer $record) => $record->readyForGestionaleSync())
                        ->requiresConfirmation()
                        ->action(fn (Customer $record) => $record->markSentToGestionale()),
                    Tables\Actions\Action::make('segna_aggiornato_gestionale')
                        ->label('Segna come controllato (chiudi segnalazione)')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Customer $record) => $record->gestionale_review_flagged_at !== null)
                        ->requiresConfirmation()
                        ->modalDescription('Non scrive nulla su Eureka: toglie solo la segnalazione da questo cliente, per dire "ho controllato".')
                        ->action(fn (Customer $record) => $record->dismissGestionaleReview()),
                ])
                    ->label('Eureka')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->color('gray'),
                    Tables\Actions\DeleteAction::make()
                        ->before(function (Customer $record, Tables\Actions\DeleteAction $action) {
                            if ($record->hasBlockingRelatedRecords()) {
                                Notification::make()
                                    ->title('Impossibile eliminare')
                                    ->body('Questo cliente ha ancora preventivi, offerte, rapportini o macchine installate collegate.')
                                    ->danger()
                                    ->send();

                                $action->halt();
                            }
                        }),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->before(function (\Illuminate\Database\Eloquent\Collection $records, Tables\Actions\DeleteBulkAction $action) {
                            $blocked = $records->filter(fn (Customer $record) => $record->hasBlockingRelatedRecords());

                            if ($blocked->isNotEmpty()) {
                                Notification::make()
                                    ->title('Alcuni clienti non sono stati eliminati')
                                    ->body('Hanno ancora preventivi, offerte, rapportini o macchine installate collegate: '.$blocked->pluck('full_name')->map(fn ($name) => DisplayName::titleCase($name))->implode(', ').'.')
                                    ->danger()
                                    ->send();

                                $action->halt();
                            }
                        }),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun cliente ancora')
            ->emptyStateDescription('Aggiungi il primo cliente con "Nuovo".')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            MacchinariRelationManager::class,
            QuotesRelationManager::class,
            LavaggiRelationManager::class,
            ServiceReportsRelationManager::class,
        ];
    }
}
