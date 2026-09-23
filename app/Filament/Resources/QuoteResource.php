<?php

namespace App\Filament\Resources;

use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\CustomerFiscalFields;
use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Forms\MoneyInput;
use App\Filament\Forms\OffertaCaffeFields;
use App\Filament\Resources\QuoteResource\Pages;
use App\Filament\Resources\QuoteResource\RelationManagers\QuoteProductsRelationManager;
use App\Mail\QuoteMail;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\QuoteResponse;
use App\Support\DisplayName;
use App\Support\Pdf\OffertaCaffePdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Tabs as InfolistTabs;
use Filament\Infolists\Components\Tabs\Tab as InfolistTab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Vendite';


    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Preventivi';

    protected static ?string $modelLabel = 'Preventivo';

    protected static ?string $pluralModelLabel = 'Preventivi';


    /**
     * Precarica le relazioni che l'elenco legge per ogni riga.
     *
     * Senza, Filament fa una query per riga per ciascuna relazione: sui
     * rapportini erano 56 query per 25 righe invece di 8, e il conto cresce
     * con la paginazione.
     *
     * customer e' una colonna dell'elenco; billingCustomer resta caricato per
     * l'anagrafica del cliente, che senza eager loading interroga il database una
     * volta per riga.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer.billingCustomer', 'billingCustomer']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    TextEntry::make('number')->label('Preventivo')->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('customer.full_name')->label('Cliente')->columnSpan(['default' => 1, 'lg' => 5])
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('date')->label('Data')->date()->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                        ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray')
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                ]),
            // Tab "Dati preventivo" / "Righe preventivo": stessa suddivisione e
            // stessa etichettatura della pagina di Modifica (dove "Dati
            // preventivo" e' il tab del form e "Righe preventivo" il tab del
            // RelationManager) - richiesta per coerenza tra Visualizza e Modifica.
            InfolistTabs::make('Preventivo')
                ->columnSpanFull()
                ->tabs([
                    InfolistTab::make('Dati preventivo')
                        ->schema([
                            InfolistSection::make('Offerta globale')
                                ->columnSpanFull()
                                ->columns(3)
                                ->visible(fn (Quote $record) => filled($record->quote_group_id))
                                ->extraAttributes([
                                    'class' => 'rounded-2xl border border-amber-200 bg-amber-50 shadow-sm dark:border-amber-900/40 dark:bg-amber-950/20',
                                ])
                                ->schema([
                                    TextEntry::make('quoteGroup.number')
                                        ->label('offerta globale')
                                        ->placeholder('—'),
                                    TextEntry::make('quoteGroup.status')
                                        ->label('Stato offerta')
                                        ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : '—'),
                                    TextEntry::make('offer_alternatives_count')
                                        ->label('soluzioni alternative')
                                        ->state(fn (Quote $record) => max(0, ($record->quoteGroup?->quotes()->count() ?? 1) - 1)),
                                ]),
                            // Da quale richiesta nasce il preventivo: prima si vedeva
                            // solo dal lato richiesta, e dal preventivo non c'era
                            // modo di risalirci.
                            InfolistSection::make('Richiesta informazioni')
                                ->columnSpanFull()
                                ->columns(3)
                                ->visible(fn (Quote $record) => filled($record->information_request_id))
                                ->extraAttributes([
                                    'class' => 'rounded-2xl border border-sky-200 bg-sky-50 shadow-sm dark:border-sky-900/40 dark:bg-sky-950/20',
                                ])
                                ->schema([
                                    TextEntry::make('informationRequest.number')
                                        ->label('Richiesta')
                                        ->color('primary')
                                        ->url(fn (Quote $record) => $record->informationRequest
                                            ? InformationRequestResource::getUrl('edit', ['record' => $record->informationRequest])
                                            : null),
                                    TextEntry::make('informationRequest.created_at')->label('Arrivata il')->date('d/m/Y'),
                                    TextEntry::make('informationRequest.status')
                                        ->label('Stato richiesta')
                                        ->badge()
                                        ->formatStateUsing(fn (?string $state) => InformationRequestResource::statusLabels()[$state] ?? $state)
                                        ->color(fn (?string $state) => InformationRequestResource::statusColors()[$state] ?? 'gray'),
                                    TextEntry::make('informationRequest.request_details')
                                        ->label('Cosa chiedeva')
                                        ->placeholder('—')
                                        ->limit(300)
                                        ->columnSpanFull(),
                                ]),
                            \Filament\Infolists\Components\Grid::make(12)
                                ->schema([
                                    InfolistSection::make('Dati preventivo')
                                        ->columnSpan(['default' => 1, 'lg' => 8])
                                        ->columns(2)
                                        ->extraAttributes([
                                            'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                                        ])
                                        ->schema([
                                            TextEntry::make('paymentMethodRelation.name')->label('Metodo di pagamento')->placeholder('—'),
                                            TextEntry::make('discount')->label('Sconto generale')->suffix('%'),
                                            TextEntry::make('extra_discount')->label('Sconto extra')->suffix('%')
                                                ->visible(fn ($record) => (float) ($record->extra_discount ?? 0) > 0),
                                            TextEntry::make('notes')->label('Note')->placeholder('—')->html()->columnSpanFull(),
                                        ]),
                                    InfolistSection::make('Totali')
                                        ->columnSpan(['default' => 1, 'lg' => 4])
                                        ->columns(2)
                                        ->extraAttributes([
                                            'class' => 'rounded-2xl border border-slate-200 bg-slate-50 shadow-sm dark:border-slate-800 dark:bg-slate-900',
                                        ])
                                        ->schema([
                                            TextEntry::make('subtotal')->label('Imponibile')->money('EUR'),
                                            TextEntry::make('discount')->label('Sconto generale')->suffix('%')->placeholder('—'),
                                            TextEntry::make('extra_discount')->label('Sconto extra')->suffix('%')
                                                ->visible(fn ($record) => (float) ($record->extra_discount ?? 0) > 0),
                                            TextEntry::make('tax_total')->label('IVA')->money('EUR'),
                                            TextEntry::make('total')
                                                ->label('Totale')
                                                ->money('EUR')
                                                ->weight('bold')
                                                ->size(TextEntry\TextEntrySize::Large)
                                                ->color('primary'),
                                        ]),
                                ]),
                        ]),
                    // Il PDF non era l'unico posto dove vedere cosa contiene un
                    // preventivo: prima qui non compariva nessuna riga, solo i totali.
                    InfolistTab::make('Righe preventivo')
                        ->schema([
                            InfolistSection::make()
                                ->columnSpanFull()
                                ->extraAttributes([
                                    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                                ])
                                ->schema([
                                    // Intestazione mostrata una sola volta: le TextEntry dentro
                                    // la RepeatableEntry sotto NON hanno label (altrimenti
                                    // "Prodotto"/"Qtà"/... si ripeterebbero su ogni riga,
                                    // bug segnalato "non ripetere più volte prodotto qtà").
                                    \Filament\Infolists\Components\Grid::make(6)
                                        ->schema([
                                            TextEntry::make('header_product')->hiddenLabel()->state('Prodotto')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->columnSpan(['default' => 1, 'lg' => 2]),
                                            TextEntry::make('header_quantity')->hiddenLabel()->state('Qtà')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignCenter(),
                                            TextEntry::make('header_price')->hiddenLabel()->state('Prezzo unit.')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignRight(),
                                            TextEntry::make('header_discount')->hiddenLabel()->state('Sconto')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignRight(),
                                            TextEntry::make('header_total')->hiddenLabel()->state('Imponibile')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignRight(),
                                        ]),
                                    RepeatableEntry::make('baseQuoteProducts')
                                        ->hiddenLabel()
                                        ->contained(false)
                                        ->schema([
                                            TextEntry::make('product.name')->hiddenLabel()->weight('bold')->columnSpan(['default' => 1, 'lg' => 2]),
                                            TextEntry::make('quantity')->hiddenLabel()->alignCenter(),
                                            TextEntry::make('price')->hiddenLabel()->money('EUR')->alignRight(),
                                            TextEntry::make('discount')->hiddenLabel()->suffix('%')->alignRight(),
                                            TextEntry::make('total')->hiddenLabel()->money('EUR')->alignRight()->weight('bold'),
                                            // Opzioni annidate sotto la riga macchina a cui appartengono.
                                            RepeatableEntry::make('options')
                                                ->hiddenLabel()
                                                ->contained(false)
                                                ->visible(fn ($record) => $record->options->isNotEmpty())
                                                ->columnSpanFull()
                                                ->schema([
                                                    TextEntry::make('product.name')->hiddenLabel()->formatStateUsing(fn (string $state) => "↳ {$state}")->columnSpan(['default' => 1, 'lg' => 2]),
                                                    TextEntry::make('quantity')->hiddenLabel()->alignCenter(),
                                                    TextEntry::make('price')->hiddenLabel()->money('EUR')->alignRight(),
                                                    TextEntry::make('discount')->hiddenLabel()->suffix('%')->alignRight(),
                                                    TextEntry::make('total')->hiddenLabel()->money('EUR')->alignRight(),
                                                ])
                                                ->columns(6),
                                        ])
                                        ->columns(6),
                                ])
                                ->visible(fn (Quote $record) => $record->quoteProducts->isNotEmpty()),
                        ]),
                ]),
            // Cosa ha fatto il cliente dal link nella mail (QuoteClientController).
            InfolistSection::make('Risposta del cliente')
                ->columnSpanFull()
                ->visible(fn (Quote $record) => (bool) ($record->public_token || $record->quoteGroup?->public_token))
                ->headerActions([
                    InfolistAction::make('clientLink')
                        ->label('Link cliente')
                        ->icon('heroicon-o-link')
                        ->color('gray')
                        ->modalHeading('Link per il cliente')
                        ->modalDescription('Lo stesso link della mail: si puo\' mandare anche su WhatsApp.')
                        ->modalContent(fn (Quote $record) => new HtmlString(
                            '<input readonly onclick="this.select()" value="'.e($record->clientUrl()).'" class="w-full rounded-lg border-gray-300 text-sm dark:bg-white/5">'
                        ))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Chiudi'),
                ])
                ->schema([
                    ViewEntry::make('client_responses')
                        ->hiddenLabel()
                        ->view('filament.infolists.quote-client-responses'),
                ]),
            InfolistSection::make('Storico invii email')
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                ])
                ->visible(fn (Quote $record) => $record->emails->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('emails')
                        ->hiddenLabel()
                        ->contained(false)
                        ->schema([
                            TextEntry::make('recipient_email')->label('Destinatario'),
                            TextEntry::make('created_at')->label('Inviato il')->dateTime('d/m/Y H:i'),
                            TextEntry::make('status')
                                ->label('Esito')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => $state === 'sent' ? 'Inviato' : 'Fallito')
                                ->color(fn (string $state) => $state === 'sent' ? 'success' : 'danger'),
                            TextEntry::make('error_message')
                                ->label('Errore')
                                ->placeholder('—')
                                ->columnSpanFull()
                                ->visible(fn ($record) => filled($record->error_message)),
                            InfolistActions::make([
                                InfolistAction::make('preview')
                                    ->label('Anteprima')
                                    ->icon('heroicon-o-eye')
                                    ->color('gray')
                                    ->modalHeading(fn ($record) => "Anteprima email — {$record->recipient_email}")
                                    ->modalContent(fn ($record) => new HtmlString(
                                        '<iframe srcdoc="'.e((new QuoteMail($record->quote, '', $record->message, clientUrl: $record->quote->public_token ? $record->quote->clientUrl() : null))->render()).'" style="width:100%;height:70vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
                                    ))
                                    ->modalSubmitAction(false)
                                    ->modalCancelActionLabel('Chiudi')
                                    ->modalWidth('4xl'),
                            ]),
                        ])
                        ->columns(3),
                ]),
        ]);
    }

    public static function form(Form $form): Form
    {
        $isCreating = $form->getOperation() === 'create';

        $schema = [
            Forms\Components\Section::make('Panoramica rapida')
                ->columns(5)
                ->columnSpanFull()
                ->visible(fn (?Quote $record) => $record !== null)
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    Forms\Components\Placeholder::make('summary_number')
                        ->label('Preventivo')
                        ->content(fn (?Quote $record) => $record?->number ?? '—'),
                    Forms\Components\Placeholder::make('summary_customer')
                        ->label('Cliente')
                        ->content(fn (?Quote $record) => DisplayName::titleCase($record?->customer?->full_name) ?? '—'),
                    Forms\Components\Placeholder::make('summary_date')
                        ->label('Data')
                        ->content(fn (?Quote $record) => $record
                            ? \Illuminate\Support\Carbon::parse($record->getAttribute('date'))->format('d/m/Y')
                            : '—'),
                    Forms\Components\Placeholder::make('summary_status')
                        ->label('Stato')
                        ->content(fn (?Quote $record) => new \Illuminate\Support\HtmlString(
                            '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">'
                                .($record ? ucfirst($record->status) : '—')
                                .'</span>'
                        )),
                    Forms\Components\Placeholder::make('summary_total')
                        ->label('Totale')
                        ->content(fn (?Quote $record) => new \Illuminate\Support\HtmlString(
                            '<span class="text-lg font-bold text-primary-600 dark:text-primary-400">'
                                .($record ? number_format((float) $record->total, 2, ',', '.').' €' : '—')
                                .'</span>'
                        )),
                ]),
        ];

        if ($isCreating) {
            $schema[] = Forms\Components\Section::make('Dati preventivo')
                ->columns(3)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                ])
                ->schema([
                    // Presente solo quando si crea un preventivo da "Nuovo
                    // preventivo" dentro un'Offerta (QuoteGroupResource): il
                    // link passa ?group=<id> in query string, cosi' il nuovo
                    // Quote nasce gia' agganciato al gruppo.
                    Forms\Components\Hidden::make('quote_group_id')
                        ->default(fn () => request()->query('group')),
                    Forms\Components\TextInput::make('number')
                        ->label('Numero')
                        ->required()
                        ->disabled(fn (?Quote $record) => $record !== null)
                        ->dehydrated()
                        ->default(fn () => Quote::nextNumberForTenant(Filament::getTenant()?->id)),
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required()
                        // Creando da dentro un'Offerta il cliente e' quello
                        // dell'Offerta e non deve poter essere cambiato per
                        // sbaglio: altrimenti il preventivo "alternativo"
                        // finirebbe nel gruppo ma per un cliente diverso.
                        ->default(fn () => request()->query('group')
                            ? QuoteGroup::find(request()->query('group'))?->customer_id
                            : request()->query('customer_id'))
                        ->disabled(fn () => $isCreating && filled(request()->query('group')))
                        ->dehydrated()
                        // La richiesta informazioni sotto dipende dal cliente:
                        // se ne ha una sola aperta si propone quella.
                        ->live()
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
                            'information_request_id',
                            static::richiestaUnica($state),
                        ))
                        ->extraAttributes(['data-tour' => 'quotes-field-customer'])
                        ->createOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
                            ...CustomerFiscalFields::schema(),
                            ...ItalianAddressFields::schema(),
                        ]),
                    static::informationRequestField(),
                    Forms\Components\DatePicker::make('date')
                        ->label('Data')
                        ->required()
                        ->default(now()),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(static::statusLabels())
                        ->default('bozza')
                        ->required(),
                    Forms\Components\Select::make('payment_method')
                        ->label('Metodo di pagamento')
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'slug'))
                        ->live()
                        ->extraAttributes(['data-tour' => 'quotes-field-payment']),
                    MoneyInput::make('rental_monthly_fee')
                        ->label('Canone mensile noleggio (€)')
                        ->visible(fn (Get $get) => $get('payment_method') === 'noleggio-operativo'),
                    Forms\Components\TextInput::make('rental_months')
                        ->label('Durata (mesi)')
                        ->numeric()
                        ->default(60)
                        ->visible(fn (Get $get) => $get('payment_method') === 'noleggio-operativo'),
                    Forms\Components\TextInput::make('discount')
                        ->label('Sconto generale (%)')
                        ->numeric()
                        ->suffix('%')
                        ->default(0),
                    Forms\Components\TextInput::make('extra_discount')
                        ->label('Sconto extra (%)')
                        ->helperText('Si applica su quanto resta dopo lo sconto generale, come i listini 30+5.')
                        ->numeric()
                        ->suffix('%')
                        ->default(0),
                    Forms\Components\RichEditor::make('notes')
                        ->label('Note')
                        ->toolbarButtons(['bold'])
                        ->columnSpanFull()
                        ->extraAttributes(['style' => 'min-height: 10rem;']),
                ]);
        } else {
            $schema[] = Forms\Components\Grid::make(12)
                ->schema([
                    Forms\Components\Section::make('Dati preventivo')
                        ->columnSpan(['default' => 1, 'lg' => 8])
                        ->extraAttributes([
                            'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                        ])
                        ->schema([
                            Forms\Components\Grid::make(12)
                                ->schema([
                                    Forms\Components\TextInput::make('number')
                                        ->label('Numero')
                                        ->required()
                                        ->disabled(fn (?Quote $record) => $record !== null)
                                        ->dehydrated()
                                        ->columnSpan(['default' => 1, 'lg' => 3])
                                        ->default(fn () => Quote::nextNumberForTenant(Filament::getTenant()?->id)),
                                    Forms\Components\Select::make('customer_id')
                                        ->label('Cliente')
                                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                                        ->searchable(['company_name', 'first_name', 'last_name'])
                                        ->preload()
                                        ->required()
                                        // Creando da dentro un'Offerta il cliente e' quello
                                        // dell'Offerta e non deve poter essere cambiato per
                                        // sbaglio: altrimenti il preventivo "alternativo"
                                        // finirebbe nel gruppo ma per un cliente diverso.
                                        ->default(fn () => request()->query('group') ? QuoteGroup::find(request()->query('group'))?->customer_id : null)
                                        ->disabled(fn () => $isCreating && filled(request()->query('group')))
                                        ->dehydrated()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('information_request_id', static::richiestaUnica($state)))
                                        ->columnSpan(['default' => 1, 'lg' => 4])
                                        ->createOptionForm([
                                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                                            ...CustomerContactFields::schema(),
                                            ...ItalianAddressFields::schema(),
                                        ]),
                                    Forms\Components\DatePicker::make('date')
                                        ->label('Data')
                                        ->required()
                                        ->columnSpan(['default' => 1, 'lg' => 4])
                                        ->default(now()),
                                    Forms\Components\Select::make('status')
                                        ->label('Stato')
                                        ->options(static::statusLabels())
                                        ->default('bozza')
                                        ->required()
                                        ->columnSpan(['default' => 1, 'lg' => 4]),
                                    static::informationRequestField()->columnSpan(['default' => 1, 'lg' => 4]),
                                    Forms\Components\Select::make('payment_method')
                                        ->label('Metodo di pagamento')
                                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'slug'))
                                        ->live()
                                        ->columnSpan(['default' => 1, 'lg' => 4]),
                                    MoneyInput::make('rental_monthly_fee')
                                        ->label('Canone mensile noleggio (€)')
                                        ->columnSpan(['default' => 1, 'lg' => 4])
                                        ->visible(fn (Get $get) => $get('payment_method') === 'noleggio-operativo'),
                                    Forms\Components\TextInput::make('rental_months')
                                        ->label('Durata (mesi)')
                                        ->numeric()
                                        ->default(60)
                                        ->columnSpan(['default' => 1, 'lg' => 4])
                                        ->visible(fn (Get $get) => $get('payment_method') === 'noleggio-operativo'),
                                    Forms\Components\TextInput::make('discount')
                                        ->label('Sconto generale (%)')
                                        ->numeric()
                                        ->suffix('%')
                                        ->default(0)
                                        ->columnSpan(['default' => 1, 'lg' => 4]),
                                    Forms\Components\TextInput::make('extra_discount')
                                        ->label('Sconto extra (%)')
                                        ->helperText('Si applica su quanto resta dopo lo sconto generale, come i listini 30+5.')
                                        ->numeric()
                                        ->suffix('%')
                                        ->default(0)
                                        ->columnSpan(['default' => 1, 'lg' => 4]),
                                    Forms\Components\RichEditor::make('notes')
                                        ->label('Note')
                                        ->toolbarButtons(['bold'])
                                        ->columnSpanFull()
                                        ->extraAttributes(['style' => 'min-height: 10rem;']),
                                ]),
                        ]),
                    Forms\Components\Section::make('Totali')
                        ->columnSpan(['default' => 1, 'lg' => 4])
                        ->columns(2)
                        ->extraAttributes([
                            'class' => 'rounded-2xl border border-slate-200 bg-slate-50 shadow-sm dark:border-slate-800 dark:bg-slate-900',
                        ])
                        ->schema([
                            Forms\Components\Placeholder::make('subtotal_display')
                                ->label('Imponibile')
                                ->content(fn (?Quote $record) => $record ? number_format((float) $record->subtotal, 2, ',', '.').' €' : '—'),
                            Forms\Components\Placeholder::make('discount_display')
                                ->label('Sconto generale')
                                ->content(fn (?Quote $record) => $record ? number_format((float) $record->discount, 2, ',', '.').'%' : '—'),
                            Forms\Components\Placeholder::make('tax_total_display')
                                ->label('IVA')
                                ->content(fn (?Quote $record) => $record ? number_format((float) $record->tax_total, 2, ',', '.').' €' : '—'),
                            Forms\Components\Placeholder::make('total_display')
                                ->label('Totale')
                                ->content(fn (?Quote $record) => new \Illuminate\Support\HtmlString(
                                    '<span class="text-lg font-bold text-primary-600 dark:text-primary-400">'
                                        .($record ? number_format((float) $record->total, 2, ',', '.').' €' : '—')
                                        .'</span>'
                                )),
                        ]),
                ]);
        }

        // Le righe si gestiscono nel tab "Righe preventivo" (RelationManager,
        // sotto in pagina): qui il riepilogo mostra i totali calcolati da
        // Quote::updateTotal(), non piu' solo il totale finale nudo (bug
        // segnalato: "manca tutta la parte di totali, fatta male").
        // "Ricalcola totali" nell'header rinfresca questi valori senza
        // uscire dalla pagina.
        return $form->schema($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Ordina per numero preventivo, non per created_at ne' per date:
            // created_at e' quasi identico su tutti i preventivi importati
            // dal legacy (l'istante dell'import, non la data reale), e "date"
            // ha molte righe con lo stesso giorno (ordine instabile fra loro).
            // Il numero (PRV-AAAA-NNNN, zero-padded) e' invece univoco per
            // riga e cresce in ordine di creazione: un ordinamento alfabetico
            // sulla stringa coincide con quello numerico.
            ->defaultSort('number', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')->wrap()->label('Cliente')->searchable()->sortable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                // Come in Richieste Informazioni: la zona e' il primo filtro
                // mentale quando si scorre un elenco di clienti.
                Tables\Columns\TextColumn::make('customer.province')->visibleFrom('md')
                    ->label('Prov.')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('date')->visibleFrom('md')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR')->sortable(),
                // Il cliente ha aperto il link nella mail? (vedi HasClientLink)
                Tables\Columns\TextColumn::make('client_last_viewed_at')->visibleFrom('md')
                    ->label('Visto')
                    ->since()
                    ->tooltip(fn (Quote $record) => $record->client_view_count
                        ? "Aperto {$record->client_view_count} volte, l'ultima il ".$record->client_last_viewed_at?->format('d/m/Y H:i')
                        : null)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('date')
                    ->label('Periodo')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dal'),
                        Forms\Components\DatePicker::make('until')->label('Al'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '<=', $date));
                    }),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->url(fn (Quote $record) => route('quotes.pdf', $record))
                    ->openUrlInNewTab(),
                // "Invia" e' l'unica azione a colore pieno (success) della riga:
                // e' quella che porta il preventivo verso il cliente, il resto
                // sono azioni di supporto/secondarie (stesso criterio usato per
                // Offerte, Scadenzario, Ferie: success solo per l'azione che
                // "conclude" qualcosa, danger solo per l'eliminazione).
                static::sendEmailTableAction(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('recalculate')
                        ->label('Ricalcola totali')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(fn (Quote $record) => $record->updateTotal())
                        ->successNotificationTitle('Totali ricalcolati'),
                    static::duplicateAsAlternativeAction(),
                    Tables\Actions\EditAction::make()
                        ->color('gray'),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun preventivo ancora')
            ->emptyStateDescription('Crea il primo preventivo per questo cliente con "Nuovo".')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    /**
     * Il filo fra la richiesta e i preventivi che ne nascono: la richiesta
     * poi ne segue lo stato da sola (InformationRequest::syncStatusFromQuotes).
     * Prima era un campo nascosto valorizzato solo arrivando da "Crea
     * preventivo" sulla richiesta: partendo da Preventivi il collegamento non
     * nasceva mai e la richiesta restava "Nuova" anche a preventivo inviato.
     */
    public static function informationRequestField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('information_request_id')
            ->label('Richiesta informazioni')
            // La richiesta scelta resta sempre tra le opzioni, anche se chiusa:
            // altrimenti la select mostrerebbe l'id nudo.
            ->options(fn (Get $get) => static::richiesteCollegabili($get('customer_id'), $get('information_request_id')))
            ->default(fn () => request()->query('information_request_id')
                ?? static::richiestaUnica(request()->query('customer_id')))
            ->placeholder('Nessuna')
            ->visible(fn (Get $get) => static::richiesteCollegabili($get('customer_id'), $get('information_request_id')) !== [])
            ->helperText('La richiesta passa da sola a "Preventivo inviato", "accettato" o "non accettato" seguendo questo preventivo.');
    }

    /**
     * Le richieste del cliente a cui si puo' agganciare un nuovo preventivo:
     * quelle ancora aperte o gia' con altri preventivi (un'alternativa in piu').
     * Le "Gestita" / "Chiusa" restano fuori, sono chiuse a mano. $keep e' la
     * richiesta gia' scelta, sempre presente anche se non rientra.
     *
     * @return array<string, string>
     */
    public static function richiesteCollegabili(?string $customerId, ?string $keep = null): array
    {
        if (! $customerId) {
            return [];
        }

        return InformationRequest::query()
            ->where('customer_id', $customerId)
            ->where(fn (Builder $query) => $query
                ->whereIn('status', InformationRequest::AUTO_STATUSES)
                ->when($keep, fn (Builder $query) => $query->orWhere('id', $keep)))
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(fn (InformationRequest $request) => [$request->id => implode(' · ', array_filter([
                $request->number,
                $request->created_at?->format('d/m/Y'),
                InformationRequestResource::statusLabels()[$request->status] ?? $request->status,
                $request->request_details ? Str::limit($request->request_details, 50) : null,
            ]))])
            ->all();
    }

    /**
     * La richiesta da proporre in automatico: solo se il cliente ne ha
     * esattamente una ancora da preventivare. Con due o piu' si sceglie a mano.
     */
    public static function richiestaUnica(?string $customerId): ?string
    {
        if (! $customerId) {
            return null;
        }

        $open = InformationRequest::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['nuova', 'in_lavorazione'])
            ->limit(2)
            ->pluck('id');

        return $open->count() === 1 ? $open->first() : null;
    }

    public static function buildPdf(Quote $record)
    {
        $record->load(['customer', 'tenant', 'paymentMethodRelation', 'quoteProducts.product', 'quoteProducts.options.product']);

        return Pdf::loadView('pdf.quote', [
            'quote' => $record,
            'tenant' => $record->tenant,
            'acceptance' => static::onlineAcceptance($record),
        ]);
    }

    /**
     * La conferma firmata dal cliente dal link nella mail, se c'e': il PDF
     * del preventivo accettato porta la firma nel riquadro "Per
     * accettazione" (vedi QuoteClientController::accept).
     */
    public static function onlineAcceptance(Quote $record): ?QuoteResponse
    {
        if ($record->status !== 'accettato') {
            return null;
        }

        return QuoteResponse::withoutGlobalScope('tenant')
            ->where('quote_id', $record->id)
            ->where('type', QuoteResponse::TYPE_ACCEPTED)
            ->whereNotNull('signature_path')
            ->latest()
            ->first();
    }

    public static function duplicateAsAlternativeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('duplicateAsAlternative')
            ->label('Duplica come alternativa')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Duplicare come preventivo alternativo?')
            ->modalDescription('Crea una copia in bozza per lo stesso cliente, nella stessa offerta (cosi\' potrai inviarli insieme in un\'unica email) - le righe vengono copiate, le note no.')
            ->action(fn (Quote $record) => redirect(static::getUrl('edit', ['record' => static::duplicateAsAlternative($record)])));
    }

    /**
     * Copia un preventivo come opzione alternativa per lo stesso cliente,
     * agganciando entrambi (sorgente + copia) alla stessa Offerta
     * (QuoteGroup) - se il sorgente non ne aveva gia' una, ne crea una nuova
     * al volo (docs/architecture.md §14). Stesso schema dell'azione
     * "Duplica" di MaterialOrderResource, con in piu' l'aggancio al gruppo e
     * la copia delle righe (comprese le opzioni figlie, remappando
     * parent_quote_product_id) - stessa logica di copia gia' usata da
     * ConfigureMachineAction::createQuoteProducts().
     */
    public static function duplicateAsAlternative(Quote $record): Quote
    {
        $group = $record->quoteGroup;

        if (! $group) {
            $group = QuoteGroup::create([
                'tenant_id' => $record->tenant_id,
                'customer_id' => $record->customer_id,
            ]);

            $record->update(['quote_group_id' => $group->id]);
        }

        $new = Quote::create([
            'tenant_id' => $record->tenant_id,
            'quote_group_id' => $group->id,
            'customer_id' => $record->customer_id,
            'date' => now(),
            'status' => 'bozza',
            'discount' => $record->discount,
            'payment_method' => $record->payment_method,
        ]);

        foreach ($record->baseQuoteProducts as $baseLine) {
            $newBaseLine = $new->quoteProducts()->create([
                'product_id' => $baseLine->product_id,
                'quantity' => $baseLine->quantity,
                'price' => $baseLine->price,
                'discount' => $baseLine->discount,
                'tax' => $baseLine->tax,
            ]);

            foreach ($baseLine->options as $option) {
                $new->quoteProducts()->create([
                    'product_id' => $option->product_id,
                    'parent_quote_product_id' => $newBaseLine->id,
                    'quantity' => $option->quantity,
                    'price' => $option->price,
                    'discount' => $option->discount,
                    'tax' => $option->tax,
                ]);
            }
        }

        $new->updateTotal();

        Notification::make()->title("Preventivo duplicato nell'offerta {$group->number}")->success()->send();

        return $new;
    }

    /**
     * Form dell'azione "Invia" (email col PDF allegato), condiviso fra la
     * tabella e l'header della pagina View - stesso schema di
     * ServiceReportResource::send.
     *
     * @return array<Forms\Components\Component>
     */
    public static function sendEmailFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('recipient_email')
                ->label('Email destinatario')
                ->email()
                ->required()
                ->default(fn (Quote $record) => static::emailDestinatario($record)),
            Forms\Components\TextInput::make('cc_email')
                ->label('CC (opzionale)')
                ->email()
                ->helperText('I destinatari fissi impostati in Impostazioni > Notifiche ricevono comunque una copia.'),
            Forms\Components\RichEditor::make('custom_message')
                ->label('Testo email (modificabile)')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->helperText('Questo testo viene inviato realmente nella mail, incluso il prezzo: puoi modificarlo liberamente.')
                // Stesso testo precompilato del vecchio gestionale
                // (app_preventivi_vg), perso nella riscrittura di questo
                // pannello: l'utente lo trova gia' pronto e lo personalizza
                // solo se serve, invece di scrivere da zero ad ogni invio.
                ->default(fn (Quote $record) => static::defaultQuoteEmailBody($record)),
            // Link alla pagina dove il cliente accetta firmando, rifiuta, fa
            // una domanda o chiede di essere richiamato (QuoteClientController):
            // tanti preventivi restavano "Inviato" senza piu' notizie.
            Forms\Components\Toggle::make('client_link')
                ->label('Includi il link per rispondere online (accetta e firma, rifiuta, domanda, richiamata)')
                ->default(true),
            ...OffertaCaffeFields::allegatoAllInvio(fn (?Quote $record = null) => $record?->customer, 'custom_message'),
        ];
    }

    /**
     * A chi va il preventivo: a CHI LO HA CHIESTO, mai a chi paga.
     *
     * Prima si passava da Customer::invoiceRecipient(), che segue il pagante
     * impostato sull'anagrafica: su Mariver quello e' Dersut, e la mail
     * partiva verso il torrefattore invece che verso il cliente (visto dal
     * vivo il 03/09/2026). E' la stessa regola gia' valida per i rapportini.
     *
     * Metodo e non closure in linea: cosi' la regola si puo' verificare con
     * un test, invece di vivere dentro uno schema Filament che fuori dal
     * form non si sa valutare.
     */
    public static function emailDestinatario(Quote $record): ?string
    {
        return $record->customer?->primaryEmail();
    }

    protected static function defaultQuoteEmailBody(Quote $record): string
    {
        // Si saluta il cliente, non chi paga: vedi il campo destinatario.
        $cliente = $record->customer;
        $customerName = DisplayName::titleCase($cliente?->company_name) ?: (DisplayName::titleCase($cliente?->full_name) ?? 'Cliente');
        $total = '€ '.number_format((float) $record->subtotal, 2, ',', '.').' + IVA';
        $signatureName = static::emailSignatureName($record);

        return implode('', [
            '<p>Gentile '.e($customerName).',</p>',
            '<p>Siamo lieti di inviarle il preventivo richiesto.</p>',
            '<p>Di seguito troverà tutti i dettagli e le condizioni commerciali.</p>',
            '<p>In allegato il documento in formato PDF.</p>',
            '<p><strong>'.e($total).'</strong></p>',
            '<p>Restiamo a disposizione per qualsiasi chiarimento.</p>',
            '<p>Grazie,<br>'.e($signatureName).'</p>',
        ]);
    }

    /**
     * Le mail al cliente le firma sempre l'azienda, mai l'utente collegato:
     * il nome dell'account non deve arrivare al destinatario (gli account di
     * servizio si chiamano "Super Admin") e la firma resta uguale a chiunque
     * prema il pulsante.
     */
    protected static function emailSignatureName(Quote $record): string
    {
        return $record->tenant?->legal_name
            ?: ($record->tenant?->name ?: config('app.name'));
    }

    public static function sendQuoteEmail(Quote $record, array $data): void
    {
        $pdf = static::buildPdf($record);
        $offertaCaffe = OffertaCaffeFields::daAllegare($data);

        $email = $record->emails()->create([
            'user_id' => Auth::id(),
            'recipient_email' => $data['recipient_email'],
            'cc_email' => $data['cc_email'] ?? null,
            'subject' => "Preventivo {$record->number}",
            'message' => $data['custom_message'] ?? null,
            'status' => 'sent',
        ]);

        try {
            Mail::to($data['recipient_email'])
                ->cc(static::ccRecipients($record, $data))
                ->send(new QuoteMail(
                    $record,
                    $pdf->output(),
                    $data['custom_message'] ?? null,
                    $offertaCaffe ? OffertaCaffePdf::perOfferta($offertaCaffe)->output() : null,
                    $offertaCaffe ? OffertaCaffePdf::nomeFile($offertaCaffe) : null,
                    ($data['client_link'] ?? true) ? $record->clientUrl() : null,
                ));

            if ($offertaCaffe) {
                OffertaCaffeResource::registraInvio($offertaCaffe, $data['recipient_email'], $data['cc_email'] ?? null, "Preventivo {$record->number}", $data['custom_message'] ?? null, inviataCon: "Preventivo {$record->number}");
            }

            if ($record->status === 'bozza') {
                $record->update(['status' => 'inviato']);
            }

            Notification::make()->title('Preventivo inviato')->success()->send();
        } catch (\Throwable $e) {
            $email->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Notification::make()->title('Invio fallito')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * CC manuale (facoltativo, dal form d'invio) unito ai destinatari fissi
     * del tenant configurati per i preventivi (pagina Notifiche), senza
     * duplicati.
     *
     * @return array<int, string>
     */
    protected static function ccRecipients(Quote $record, array $data): array
    {
        return array_values(array_unique(array_filter([
            ...(array) ($data['cc_email'] ?? []),
            ...($record->tenant?->notificationRecipients('quote') ?? []),
        ])));
    }

    protected static function sendEmailTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('send')
            ->label('Invia')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->form(fn () => static::sendEmailFormSchema())
            ->action(fn (Quote $record, array $data) => static::sendQuoteEmail($record, $data));
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
            'view' => Pages\ViewQuote::route('/{record}/view'),
            'edit' => Pages\EditQuote::route('/{record}'),
        ];
    }

    /**
     * Il modello non ha costanti per lo stato (campo stringa libero): le
     * etichette/colori restano centralizzati qui per non duplicarli tra
     * form, tabella, filtro e infolist (e in QuotesRelationManager).
     */
    public static function statusLabels(): array
    {
        return [
            'bozza' => 'Bozza',
            'inviato' => 'Inviato',
            'accettato' => 'Accettato',
            'rifiutato' => 'Rifiutato',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'bozza' => 'gray',
            'inviato' => 'warning',
            'accettato' => 'success',
            'rifiutato' => 'danger',
        ];
    }
}
