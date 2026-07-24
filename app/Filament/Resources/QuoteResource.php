<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\StreamsPdfDownloads;
use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\QuoteResource\Pages;
use App\Filament\Resources\QuoteResource\RelationManagers\QuoteProductsRelationManager;
use App\Mail\QuoteMail;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\QuoteGroup;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Tabs as InfolistTabs;
use Filament\Infolists\Components\Tabs\Tab as InfolistTab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class QuoteResource extends Resource
{
    use StreamsPdfDownloads;

    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Preventivi';

    protected static ?string $modelLabel = 'Preventivo';

    protected static ?string $pluralModelLabel = 'Preventivi';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900',
                ])
                ->schema([
                    TextEntry::make('number')->label('Preventivo')->columnSpan(2),
                    TextEntry::make('customer.full_name')->label('Cliente')->columnSpan(5),
                    TextEntry::make('date')->label('Data')->date()->columnSpan(2),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                        ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray')
                        ->columnSpan(3),
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
                            \Filament\Infolists\Components\Grid::make(12)
                                ->schema([
                                    InfolistSection::make('Dati preventivo')
                                        ->columnSpan(8)
                                        ->columns(2)
                                        ->extraAttributes([
                                            'class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950',
                                        ])
                                        ->schema([
                                            TextEntry::make('paymentMethodRelation.name')->label('Metodo di pagamento')->placeholder('—'),
                                            TextEntry::make('discount')->label('Sconto generale')->suffix('%'),
                                            TextEntry::make('notes')->label('Note')->placeholder('—')->html()->columnSpanFull(),
                                        ]),
                                    InfolistSection::make('Totali')
                                        ->columnSpan(4)
                                        ->columns(2)
                                        ->extraAttributes([
                                            'class' => 'rounded-2xl border border-slate-200 bg-slate-50 shadow-sm dark:border-slate-800 dark:bg-slate-900',
                                        ])
                                        ->schema([
                                            TextEntry::make('subtotal')->label('Imponibile')->money('EUR'),
                                            TextEntry::make('discount')->label('Sconto generale')->suffix('%')->placeholder('—'),
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
                                            TextEntry::make('header_product')->hiddenLabel()->state('Prodotto')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->columnSpan(2),
                                            TextEntry::make('header_quantity')->hiddenLabel()->state('Qtà')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignCenter(),
                                            TextEntry::make('header_price')->hiddenLabel()->state('Prezzo unit.')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignRight(),
                                            TextEntry::make('header_discount')->hiddenLabel()->state('Sconto')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignRight(),
                                            TextEntry::make('header_total')->hiddenLabel()->state('Imponibile')->weight('bold')->size(TextEntry\TextEntrySize::ExtraSmall)->color('gray')->alignRight(),
                                        ]),
                                    RepeatableEntry::make('baseQuoteProducts')
                                        ->hiddenLabel()
                                        ->contained(false)
                                        ->schema([
                                            TextEntry::make('product.name')->hiddenLabel()->weight('bold')->columnSpan(2),
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
                                                    TextEntry::make('product.name')->hiddenLabel()->formatStateUsing(fn (string $state) => "↳ {$state}")->columnSpan(2),
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
            // Nascosta su richiesta (poco chiara/prematura cosi' com'e'
            // gestita oggi): il calcolo (Quote::commissionAttributes,
            // eseguito da updateTotal) resta attivo, i dati non si perdono -
            // per farla ricomparire basta togliere ->hidden().
            InfolistSection::make('Provvigione partner')
                ->columns(3)
                ->hidden()
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
        $canEditCommission = fn () => Auth::user()?->is_super_admin ?? false;

        $schema = [
            Forms\Components\Section::make('Panoramica rapida')
                ->columns(5)
                ->columnSpanFull()
                ->visible(fn (?Quote $record) => $record !== null)
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900',
                ])
                ->schema([
                    Forms\Components\Placeholder::make('summary_number')
                        ->label('Preventivo')
                        ->content(fn (?Quote $record) => $record?->number ?? '—'),
                    Forms\Components\Placeholder::make('summary_customer')
                        ->label('Cliente')
                        ->content(fn (?Quote $record) => $record?->customer?->full_name ?? '—'),
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
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
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
                        ->default(now()),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(static::statusLabels())
                        ->default('bozza')
                        ->required(),
                    Forms\Components\Select::make('payment_method')
                        ->label('Metodo di pagamento')
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'slug'))
                        ->live(),
                    Forms\Components\TextInput::make('rental_monthly_fee')
                        ->label('Canone mensile noleggio (€)')
                        ->numeric()
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
                        ->columnSpan(8)
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
                                        ->columnSpan(3)
                                        ->default(fn () => Quote::nextNumberForTenant(Filament::getTenant()?->id)),
                                    Forms\Components\Select::make('customer_id')
                                        ->label('Cliente')
                                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
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
                                        ->columnSpan(5)
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
                                        ->columnSpan(4)
                                        ->default(now()),
                                    Forms\Components\Select::make('status')
                                        ->label('Stato')
                                        ->options(static::statusLabels())
                                        ->default('bozza')
                                        ->required()
                                        ->columnSpan(4),
                                    Forms\Components\Select::make('payment_method')
                                        ->label('Metodo di pagamento')
                                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->pluck('name', 'slug'))
                                        ->live()
                                        ->columnSpan(4),
                                    Forms\Components\TextInput::make('rental_monthly_fee')
                                        ->label('Canone mensile noleggio (€)')
                                        ->numeric()
                                        ->columnSpan(4)
                                        ->visible(fn (Get $get) => $get('payment_method') === 'noleggio-operativo'),
                                    Forms\Components\TextInput::make('rental_months')
                                        ->label('Durata (mesi)')
                                        ->numeric()
                                        ->default(60)
                                        ->columnSpan(4)
                                        ->visible(fn (Get $get) => $get('payment_method') === 'noleggio-operativo'),
                                    Forms\Components\TextInput::make('discount')
                                        ->label('Sconto generale (%)')
                                        ->numeric()
                                        ->suffix('%')
                                        ->default(0)
                                        ->columnSpan(4),
                                    Forms\Components\RichEditor::make('notes')
                                        ->label('Note')
                                        ->toolbarButtons(['bold'])
                                        ->columnSpanFull()
                                        ->extraAttributes(['style' => 'min-height: 10rem;']),
                                ]),
                        ]),
                    Forms\Components\Section::make('Totali')
                        ->columnSpan(4)
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
        $schema[] = Forms\Components\Section::make('Provvigione partner')
            ->columns(3)
            // Collassata di default: dato di back-office secondario,
            // non deve competere visivamente coi dati principali del
            // preventivo appena si apre la pagina di modifica.
            ->collapsible()
            ->collapsed()
            // Nascosta su richiesta (poco chiara/prematura cosi' com'e'
            // gestita oggi): il calcolo resta attivo, i dati non si
            // perdono - per farla ricomparire, ripristinare la
            // condizione originale: ! $isCreating && ! tenant master.
            ->hidden()
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
            ]);

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
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('date')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR')->sortable(),
                // Nascosta su richiesta (poco chiara/prematura), vedi note
                // sulla stessa sezione nel form/infolist di questa risorsa.
                Tables\Columns\TextColumn::make('commission_amount')->label('Provvigione')->money('EUR')->placeholder('—')->sortable()->hidden(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
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
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->action(fn (Quote $record) => static::streamPdf($record)),
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
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun preventivo ancora')
            ->emptyStateDescription('Crea il primo preventivo per questo cliente con "Nuovo".')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function buildPdf(Quote $record)
    {
        $record->load(['customer', 'tenant', 'paymentMethodRelation', 'quoteProducts.product', 'quoteProducts.options.product']);

        return Pdf::loadView('pdf.quote', [
            'quote' => $record,
            'tenant' => $record->tenant,
        ]);
    }

    public static function streamPdf(Quote $record)
    {
        return static::streamPdfDownload(
            fn () => static::buildPdf($record),
            "preventivo-{$record->number}.pdf"
        );
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
                ->default(fn (Quote $record) => $record->customer?->primaryEmail()),
            Forms\Components\TextInput::make('cc_email')
                ->label('CC (opzionale)')
                ->email()
                ->helperText('I destinatari fissi impostati in Impostazioni > Notifiche ricevono comunque una copia.'),
            Forms\Components\Textarea::make('custom_message')
                ->label('Messaggio (opzionale)')
                ->rows(3)
                // Stesso testo precompilato del vecchio gestionale
                // (app_preventivi_vg), perso nella riscrittura di questo
                // pannello: l'utente lo trova gia' pronto e lo personalizza
                // solo se serve, invece di scrivere da zero ad ogni invio.
                ->default("Siamo lieti di inviarle il preventivo richiesto.\n\nDi seguito troverà tutti i dettagli e le condizioni commerciali.\n\nIn allegato il documento in formato PDF."),
        ];
    }

    public static function sendQuoteEmail(Quote $record, array $data): void
    {
        $pdf = static::buildPdf($record);

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
                ->send(new QuoteMail($record, $pdf->output(), $data['custom_message'] ?? null));

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
