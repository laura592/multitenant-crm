<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\StreamsPdfDownloads;
use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\CustomerFiscalFields;
use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\InformationRequestResource\Pages;
use App\Filament\Resources\InformationRequestResource\RelationManagers;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InformationRequestResource extends Resource
{
    use StreamsPdfDownloads;

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
                        // Tante ragioni sociali identiche tra clienti diversi (catene/
                        // franchising con più punti vendita): la città in coda aiuta a
                        // distinguerli nell'elenco invece di vederli tutti uguali.
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->city
                            ? DisplayName::titleCase($record->full_name)." ({$record->city})"
                            : DisplayName::titleCase($record->full_name))
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required()
                        ->extraAttributes(['data-tour' => 'information-requests-field-customer'])
                        // Serve al placeholder "Contatti cliente" sotto, che deve
                        // aggiornarsi subito quando si cambia/crea/modifica il cliente.
                        ->live()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
                            ...CustomerFiscalFields::schema(),
                            ...ItalianAddressFields::schema(),
                        ])
                        // Come in ServiceReportResource: permette di correggere email/
                        // telefono del cliente già selezionato senza uscire da questa
                        // richiesta per cercarlo in CustomerResource.
                        ->editOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
                            ...CustomerFiscalFields::schema(),
                            ...ItalianAddressFields::schema(),
                        ])
                        ->editOptionAction(fn (Forms\Components\Actions\Action $action) => $action->after(
                            fn (Forms\Components\Select $component) => $component->getSelectedRecord()
                                ?->notifyGestionaleReviewIfLinked(array_keys($component->getSelectedRecord()->getChanges()))
                        )),
                    Forms\Components\Placeholder::make('customer_contact_info')
                        ->label('Contatti cliente')
                        ->content(function (Forms\Get $get) {
                            $customer = $get('customer_id') ? Customer::find($get('customer_id')) : null;

                            if (! $customer) {
                                return '— (seleziona un cliente)';
                            }

                            return new HtmlString(collect([
                                $customer->primaryEmail() ? "✉️ {$customer->primaryEmail()}" : null,
                                $customer->primaryPhone() ? "📞 {$customer->primaryPhone()}" : null,
                                $customer->city
                                    ? trim($customer->city.($customer->province ? " ({$customer->province})" : ''))
                                    : ($customer->province ?: null),
                            ])->filter()->implode('&emsp;') ?: '— (nessun contatto salvato)');
                        }),
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
                        ->columnSpanFull()
                        ->extraAttributes(['data-tour' => 'information-requests-field-details']),
                ]),
            Forms\Components\Section::make('Appuntamento')
                ->columns(2)
                ->schema([
                    Forms\Components\DateTimePicker::make('appointment_at')
                        ->label('Data appuntamento')
                        ->native(false)
                        ->displayFormat('d/m/Y H:i'),
                    Forms\Components\Textarea::make('appointment_notes')
                        ->label('Note appuntamento')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Note')
                ->schema([
                    // Diario libero (es. "mandata mail con listino il 20/08"):
                    // a differenza dell'appuntamento sopra (un solo prossimo
                    // evento programmato), qui si accumula lo storico di cosa
                    // e' gia' stato fatto, una riga per contatto.
                    Forms\Components\Repeater::make('notes')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Forms\Components\DatePicker::make('logged_at')
                                ->label('Data')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->default(now())
                                ->required(),
                            Forms\Components\Textarea::make('body')
                                ->label('Nota')
                                ->rows(1)
                                ->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Aggiungi nota')
                        ->reorderable(false)
                        ->itemLabel(fn (array $state) => filled($state['logged_at'] ?? null)
                            ? Carbon::parse($state['logged_at'])->format('d/m/Y').(filled($state['body'] ?? null) ? " — {$state['body']}" : '')
                            : null)
                        ->collapsed()
                        ->collapsible(),
                ])
                ->visibleOn('edit'),
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
            // customer/notes gia' usati da piu' colonne sotto (email, telefono,
            // ultima nota): senza eager load sarebbe una query per riga.
            ->modifyQueryUsing(fn ($query) => $query->with(['customer', 'notes']))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                // Provincia in tabella: le richieste arrivano da tutto il
                // Veneto e oltre, e capire "dov'e'" senza aprire il cliente
                // e' il primo filtro mentale quando si decide chi richiamare.
                Tables\Columns\TextColumn::make('customer.province')
                    ->label('Prov.')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                // Recapiti diretti in tabella: prima bisognava aprire il cliente per
                // vedere email/telefono, ora sono a colpo d'occhio e copiabili.
                Tables\Columns\TextColumn::make('customer_email')
                    ->label('Email')
                    ->getStateUsing(fn (InformationRequest $record) => $record->customer?->primaryEmail())
                    ->placeholder('—')
                    ->copyable()
                    ->copyMessage('Email copiata')
                    ->icon('heroicon-o-envelope')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('customer_phone')
                    ->label('Telefono')
                    ->getStateUsing(fn (InformationRequest $record) => $record->customer?->primaryPhone())
                    ->placeholder('—')
                    ->copyable()
                    ->copyMessage('Telefono copiato')
                    ->icon('heroicon-o-phone')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                // Lo stato "preventivo inviato" non e' un altro stato della
                // richiesta da tenere aggiornato a mano: e' quello del
                // preventivo collegato, letto da qui. Se i preventivi sono
                // piu' d'uno si vedono tutti, con il numero dell'offerta
                // quando sono stati raggruppati.
                Tables\Columns\TextColumn::make('quotes.number')
                    ->label('Preventivi')
                    ->badge()
                    ->color(fn (?string $state, InformationRequest $record) => QuoteResource::statusColors()[
                        $record->quotes->firstWhere('number', $state)?->status
                    ] ?? 'gray')
                    ->formatStateUsing(function (?string $state, InformationRequest $record) {
                        $quote = $record->quotes->firstWhere('number', $state);

                        if (! $quote) {
                            return $state;
                        }

                        $status = QuoteResource::statusLabels()[$quote->status] ?? $quote->status;

                        return $quote->quoteGroup
                            ? "{$state} · {$status} · {$quote->quoteGroup->number}"
                            : "{$state} · {$status}";
                    })
                    ->placeholder('—')
                    ->url(fn (InformationRequest $record) => $record->quotes->count() === 1
                        ? QuoteResource::getUrl('edit', ['record' => $record->quotes->first()])
                        : null),
                Tables\Columns\TextColumn::make('appointment_at')
                    ->label('Appuntamento')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    // Rosso se l'appuntamento è passato e la richiesta non è ancora
                    // stata chiusa: segnala a colpo d'occhio cosa richiede attenzione.
                    ->color(fn (?InformationRequest $record) => $record?->appointment_at
                        && $record->appointment_at->isPast()
                        && ! in_array($record->status, ['gestita', 'chiusa'], true)
                            ? 'danger'
                            : null),
                Tables\Columns\TextColumn::make('latest_note')
                    ->label('Ultima nota')
                    ->getStateUsing(function (InformationRequest $record) {
                        $note = $record->notes->first();

                        return $note ? $note->logged_at->format('d/m/Y').' — '.$note->body : null;
                    })
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('handledByUser.name')->label('Gestita da')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Ricevuta il')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->headerActions([
                // Lista da stampare e portarsi in giro: gli appuntamenti presi
                // sulle richieste, in ordine di giorno e ora, con riferimenti
                // (numero richiesta, contatti) e zona (citta'/provincia) —
                // gli stessi dati che altrimenti si ricopiano a mano su
                // un'agenda prima di uscire.
                Tables\Actions\Action::make('stampaAppuntamenti')
                    ->label('Stampa appuntamenti')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Dal')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now()->startOfDay())
                            ->required(),
                        Forms\Components\DatePicker::make('to')
                            ->label('Al')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(now()->addDays(30))
                            ->afterOrEqual('from')
                            ->required(),
                    ])
                    ->action(fn (array $data) => static::stampaAppuntamenti($data)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    // Fissare/spostare l'appuntamento è l'azione più frequente su una
                    // richiesta già presa in carico: un modal rapido evita di aprire
                    // tutto il form di modifica solo per questo.
                    Tables\Actions\Action::make('setAppointment')
                        ->label('Fissa appuntamento')
                        ->icon('heroicon-o-calendar-days')
                        ->form([
                            Forms\Components\DateTimePicker::make('appointment_at')
                                ->label('Data appuntamento')
                                ->native(false)
                                ->displayFormat('d/m/Y H:i'),
                            Forms\Components\Textarea::make('appointment_notes')
                                ->label('Note appuntamento')
                                ->rows(2),
                        ])
                        ->fillForm(fn (InformationRequest $record) => [
                            'appointment_at' => $record->appointment_at,
                            'appointment_notes' => $record->appointment_notes,
                        ])
                        ->action(fn (InformationRequest $record, array $data) => $record->update($data)),
                    // Come sopra: loggare "mandata mail il 20/08" non deve
                    // richiedere di aprire tutto il form di modifica.
                    Tables\Actions\Action::make('addNote')
                        ->label('Aggiungi nota')
                        ->icon('heroicon-o-pencil-square')
                        ->form([
                            Forms\Components\DatePicker::make('logged_at')
                                ->label('Data')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->default(now())
                                ->required(),
                            Forms\Components\Textarea::make('body')
                                ->label('Nota')
                                ->rows(2)
                                ->required(),
                        ])
                        ->action(fn (InformationRequest $record, array $data) => $record->notes()->create($data)),
                    // Il preventivo nasce gia' agganciato alla richiesta e
                    // con il cliente dentro; i prodotti di interesse
                    // diventano le prime righe (CreateQuote::afterCreate()).
                    Tables\Actions\Action::make('creaPreventivo')
                        ->label('Crea preventivo')
                        ->icon('heroicon-o-document-plus')
                        ->visible(fn () => Auth::user()?->can('create_quote') ?? false)
                        ->url(fn (InformationRequest $record) => QuoteResource::getUrl('create', [
                            'customer_id' => $record->customer_id,
                            'information_request_id' => $record->id,
                        ])),
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

    /**
     * Il PDF si genera qui e si scarica dallo stream dell'azione: nessuna
     * route dedicata, cosi' resta dietro l'autenticazione del pannello e
     * dentro lo scope del tenant corrente (stesso approccio di
     * RiepilogoOre/MaterialOrderResource).
     */
    protected static function stampaAppuntamenti(array $data): ?StreamedResponse
    {
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        $requests = InformationRequest::query()
            ->whereNotNull('appointment_at')
            ->whereBetween('appointment_at', [$from, $to])
            ->with(['customer', 'products'])
            ->orderBy('appointment_at')
            ->get();

        return static::streamPdfDownload(
            fn () => OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.appuntamenti', [
                'requests' => $requests,
                'from' => $from,
                'to' => $to,
                'tenant' => Filament::getTenant(),
            ])),
            'appuntamenti-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.pdf',
        );
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\QuotesRelationManager::class,
        ];
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
