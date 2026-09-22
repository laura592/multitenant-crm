<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineUnitResource\Pages;
use App\Filament\Resources\MachineUnitResource\RelationManagers\PlacementsRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Support\DisplayName;
use App\Support\Gestionale\EurekaClient;
use App\Support\TariffeIntervento;
use Filament\Actions\MountableAction;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Registro dei macchinari fisici: matricola, proprieta' (puo' non coincidere
 * col tenant, es. una macchina di proprieta' "Dersut" installata presso un
 * cliente Alex) e ubicazione attuale. Lo storico degli spostamenti si vede
 * nella relation manager "Storico posizionamenti"; lo spostamento vero e
 * proprio si fa con l'azione "Sposta" (non modificando a mano il cliente
 * attuale, per non perdere lo storico).
 */
class MachineUnitResource extends Resource
{
    protected static ?string $model = MachineUnit::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Macchinari';

    protected static ?string $modelLabel = 'Macchinario';

    protected static ?string $pluralModelLabel = 'Macchinari';

    /**
     * Precarica le relazioni che l'elenco legge per ogni riga.
     *
     * Senza, Filament fa una query per riga per ciascuna relazione: sui
     * rapportini erano 56 query per 25 righe invece di 8, e il conto cresce
     * con la paginazione.
     *
     * currentCustomer e' una colonna dell'elenco; billingCustomer e product
     * vengono letti dalle azioni e dalla scheda.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['currentCustomer', 'billingCustomer', 'product']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('serial_number')
                        ->label('Matricola')
                        ->required()
                        ->maxLength(255)
                        // table: MachineUnit::class (non la stringa tabella) applica
                        // lo scope tenant del modello: senza, il controllo unicita'
                        // ignorerebbe machine_units_tenant_id_serial_number_unique
                        // e l'errore arriverebbe solo come 500 dal vincolo DB.
                        ->unique(
                            table: MachineUnit::class,
                            ignorable: fn (?MachineUnit $record) => $record,
                        )
                        ->extraAttributes(['data-tour' => 'machine-units-field-serial']),
                    Forms\Components\Select::make('product_id')
                        ->label('Modello (da catalogo)')
                        ->relationship('product', 'name', modifyQueryUsing: fn ($query) => $query->where('type', Product::TYPE_MACHINE))
                        // Il modifyQueryUsing sopra limita giustamente la RICERCA ai
                        // prodotti tipo "machine" (non ha senso agganciare un
                        // macchinario a un servizio quando se ne sceglie uno nuovo),
                        // ma Filament usa la STESSA query anche per risolvere
                        // l'etichetta del valore gia' selezionato: qualche macchina
                        // importata da Eureka e' agganciata a un prodotto di tipo
                        // "service" (es. BRAVILOR, mai censito come macchina a
                        // catalogo), quindi quella query non lo trova piu' e il
                        // campo mostra l'uuid grezzo invece del nome. Override
                        // esplicito, senza il filtro sul tipo, solo per l'etichetta.
                        ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                        ->searchable()
                        ->preload()
                        ->extraAttributes(['data-tour' => 'machine-units-field-product']),
                    // L'articolo di gestionale: e' da qui che nasce una
                    // matricola importata da Eureka (le macchine del parco
                    // installato non sono a listino e non compaiono nel
                    // selettore sopra, che filtra i prodotti tipo "machine").
                    Forms\Components\Select::make('material_id')
                        ->label('Articolo gestionale (Eureka)')
                        ->relationship('material', 'code')
                        ->getOptionLabelFromRecordUsing(fn (Material $record) => $record->display_label.' — '.$record->code)
                        ->searchable(['code', 'type', 'variant'])
                        ->helperText('Il codice articolo con cui questa macchina e\' registrata su Eureka.'),
                    Forms\Components\TextInput::make('model_name')
                        ->label('Modello (testo libero)')
                        ->helperText('Solo se non e\' a catalogo ne\' a gestionale.')
                        ->maxLength(255),
                    Forms\Components\Select::make('type')
                        ->label('Categoria impianto')
                        ->options(static::typeLabels())
                        ->helperText('Solo per impianti bevande: colonna spina (birra/vino/selz) o impianto acqua standalone. Lascia vuoto per le altre macchine (caffe, macinadosatori, ecc.).'),
                    // La manutenzione si decide guardando la macchina: qui si
                    // dice che tipo e'. Vuoto = quello del modello a
                    // catalogo, cosi' non si compilano 774 schede per dire
                    // ogni volta la stessa cosa. Il pagante (sotto, o quello
                    // del cliente) sceglie da solo la variante di listino:
                    // F2 diventa F2GOPPION senza che nessuno lo scriva.
                    Forms\Components\TextInput::make('maintenance_code')
                        ->label('Codice manutenzione')
                        ->placeholder(fn (?MachineUnit $record) => $record?->material?->maintenance_code
                            ? "{$record->material->maintenance_code} (dal modello)"
                            : 'es. F2, C3, DC2, MANA300')
                        ->helperText(fn (?MachineUnit $record) => static::aiutoCodiceManutenzione($record))
                        // Suggerimenti invece di un menu chiuso: i codici a
                        // catalogo si scelgono dall'elenco, ma resta possibile
                        // scriverne uno nuovo — il catalogo di Eureka cambia e
                        // un campo bloccato costringerebbe ad aspettare
                        // l'import per registrare una macchina.
                        ->datalist(static::codiciManutenzione())
                        ->maxLength(255),
                    // "Fatturare a" non sta piu' qui (22/09/2026): e' della
                    // posizione, non della macchina. Si sceglie con "Sposta" e
                    // si corregge con "Cambia pagante" nello storico
                    // posizionamenti; in lettura e' in "Dove si trova ora".
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options([
                            MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
                            MachineUnit::STATUS_INSTALLATA => 'Installata',
                            MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                        ])
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Cambia automaticamente con l\'azione "Sposta".'),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Senza questo, ViewMachineUnit (ViewRecord senza infolist() definito)
     * ripiega sul form() disabilitato: per una Select con ->relationship()
     * la label giusta arriva solo via una chiamata Livewire lato client
     * dopo il caricamento — se quella chiamata non va a buon fine (JS non
     * caricato, rete lenta, ecc.) resta visibile l'id grezzo (es. "Modello
     * macchina" mostrava lo uuid di product_id invece del nome). Un infolist
     * risolve i nomi lato server nell'HTML iniziale, senza dipendere dal JS.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Identificazione')
                ->columns(2)
                ->schema([
                    TextEntry::make('serial_number')->label('Matricola'),
                    TextEntry::make('product.name')->label('Modello (da catalogo)')->placeholder('—'),
                    TextEntry::make('material.display_label')->label('Articolo gestionale (Eureka)')->placeholder('—'),
                    TextEntry::make('model_name')->label('Modello (testo libero)')->placeholder('—'),
                    TextEntry::make('type')
                        ->label('Categoria impianto')
                        ->formatStateUsing(fn (?string $state) => static::typeLabels()[$state] ?? '—'),
                    TextEntry::make('notes')->label('Note')->placeholder('—')->columnSpanFull(),
                ]),
            // Dove si trova e chi paga non sono della macchina ma della sua
            // posizione (22/09/2026): qui quella attuale, sotto lo storico.
            InfolistSection::make('Dove si trova ora')
                ->columns(4)
                ->schema([
                    TextEntry::make('currentCustomer.full_name')->label('Presso')->placeholder('In magazzino')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('dal')->label('Dal')
                        ->state(fn (MachineUnit $record) => $record->placements()->whereNull('removed_at')->max('placed_at'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('fatturare_a')->label('Fatturare a')
                        // Il cliente stesso si dice "il cliente", come nello storico.
                        ->state(fn (MachineUnit $record) => $record->billing_customer_id && $record->billing_customer_id !== $record->current_customer_id
                            ? $record->billingCustomer?->full_name
                            : null)
                        ->placeholder(fn (MachineUnit $record) => $record->current_customer_id ? 'il cliente' : '—')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? 'In magazzino')
                        ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola')->searchable(),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Modello')
                    ->searchable(
                        query: fn ($query, string $search) => $query
                            ->where('model_name', 'like', "%{$search}%")
                            ->orWhereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('material', fn ($q) => $q
                                ->where('type', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%")),
                    ),
                Tables\Columns\TextColumn::make('currentCustomer.company_name')->label('Presso')->placeholder('In magazzino')->searchable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('type')
                    ->label('Categoria')
                    ->formatStateUsing(fn (?string $state) => static::typeLabels()[$state] ?? '—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('gestionale_code')
                    ->label('Da Eureka')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->tooltip('Se la macchina (matricola) o il suo modello sono collegati a Eureka. Indipendente dal collegamento del modello: una macchina puo essere importata da Eureka anche se il suo modello non e ancora agganciato a un articolo.')
                    ->getStateUsing(fn (MachineUnit $record) => filled($record->gestionale_code) || $record->source === MachineUnit::SOURCE_EUREKA || filled($record->product?->gestionale_code) || filled($record->material?->gestionale_code)),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? 'In magazzino')
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Categoria impianto')
                    ->options(static::typeLabels()),
                Tables\Filters\Filter::make('gestionale_suggested_code')
                    ->label('Collegamento proposto')
                    ->query(fn ($query) => $query->whereNotNull('gestionale_suggested_code')),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    static::confermaCollegamentoGestionaleAction(Tables\Actions\Action::make('conferma_collegamento_gestionale')),
                    static::scartaCollegamentoGestionaleAction(Tables\Actions\Action::make('scarta_collegamento_gestionale')),
                    static::cercaEurekaAction(Tables\Actions\Action::make('cerca_eureka')),
                    static::createServiceReportAction(Tables\Actions\Action::make('create_service_report')),
                    static::spostaAction(Tables\Actions\Action::make('sposta')),
                    static::annullaSpostamentoAction(Tables\Actions\Action::make('annulla_spostamento')),
                    Tables\Actions\EditAction::make(),
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
            ]);
    }

    /**
     * Azioni condivise fra il menu di riga della tabella e gli header di
     * ViewMachineUnit/EditMachineUnit: da quando il click riga apre la view
     * invece dell'edit, chi e' gia' dentro il record singolo non passa piu'
     * dalla tabella per usarle. Tables\Actions\Action e Filament\Actions\Action
     * estendono entrambe MountableAction, quindi la stessa configurazione
     * vale in entrambi i contesti (le pagine record legano $record
     * automaticamente, vedi InteractsWithRecord::configureAction()).
     */
    public static function confermaCollegamentoGestionaleAction(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (MachineUnit $record) => 'Conferma matricola Eureka: '.($record->gestionale_suggested_label ?? "#{$record->gestionale_suggested_code}"))
            ->icon('heroicon-o-link')
            ->color('warning')
            ->visible(fn (MachineUnit $record): bool => $record->gestionale_suggested_code !== null)
            ->requiresConfirmation()
            ->modalDescription('Il sync automatico ha trovato questa matricola su Eureka. Confermi?')
            ->action(function (MachineUnit $record) {
                $record->confermaCollegamentoEureka();
                Notification::make()->title('Collegamento confermato')->success()->send();
            });
    }

    public static function scartaCollegamentoGestionaleAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Scarta proposta')
            ->icon('heroicon-o-x-mark')
            ->visible(fn (MachineUnit $record): bool => $record->gestionale_suggested_code !== null)
            ->requiresConfirmation()
            ->action(fn (MachineUnit $record) => $record->update([
                'gestionale_suggested_code' => null,
                'gestionale_suggested_label' => null,
            ]));
    }

    public static function cercaEurekaAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Cerca su Eureka')
            ->icon('heroicon-o-magnifying-glass')
            ->visible(fn (MachineUnit $record): bool => $record->product !== null && (Filament::getTenant()?->hasGestionaleEurekaCredentials() ?? false))
            ->fillForm(fn (MachineUnit $record): array => ['gestionale_code' => $record->product?->gestionale_code])
            ->form([
                Forms\Components\Select::make('gestionale_code')
                    ->label('Articolo Eureka')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $client = new EurekaClient(Filament::getTenant());

                        return collect($client->cercaArticoli($search))
                            ->mapWithKeys(fn (array $item) => [$item['id_eureka'] => "{$item['codice']} — {$item['descr1']}"])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value) => "Codice Eureka: {$value}")
                    ->required()
                    ->helperText(fn (MachineUnit $record) => 'Digita il nome del modello (es. "ICON", "XT") per cercare nel catalogo Eureka. Il codice viene salvato sul prodotto collegato ("'.($record->product?->name ?? 'modello').'"), quindi vale per tutte le macchine di questo stesso modello, non solo per questa matricola.'),
            ])
            ->action(function (array $data, MachineUnit $record) {
                $record->product?->update(['gestionale_code' => $data['gestionale_code']]);
                Notification::make()->title('Codice Eureka salvato sul modello')->success()->send();
            });
    }

    public static function createServiceReportAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Crea rapportino')
            ->icon('heroicon-o-document-plus')
            ->color('success')
            ->visible(fn (MachineUnit $record): bool => $record->current_customer_id !== null)
            ->url(fn (MachineUnit $record) => ServiceReportResource::getUrl('create', ['machine_unit_id' => $record->id, 'customer_id' => $record->current_customer_id]));
    }

    public static function spostaAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Sposta')
            ->icon('heroicon-o-arrow-right-circle')
            ->form([
                Forms\Components\Select::make('customer_id')
                    ->label('Nuovo cliente')
                    ->helperText('Lascia vuoto per riportare la macchina in magazzino/rimuoverla.')
                    // Il paese fra parentesi: tante ragioni sociali si ripetono
                    // fra punti vendita diversi (DisplayName::customerOption).
                    ->options(fn () => Customer::query()->orderBy('company_name')->get()->mapWithKeys(
                        fn (Customer $customer) => [$customer->id => DisplayName::customerOption($customer) ?: 'Cliente senza nome']
                    ))
                    ->searchable()
                    ->live(),
                // Gli spostamenti si registrano spesso dopo (22/09/2026: una
                // macchina ritirata nel 2024 e reinstallata nel 2026): la data
                // e' quella vera, non quella in cui lo si scrive.
                Forms\Components\DatePicker::make('data')
                    ->label('Data dello spostamento')
                    ->default(now()->toDateString())
                    ->required()
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->minDate(fn (MachineUnit $record) => ($dal = $record->placements()->whereNull('removed_at')->max('placed_at'))
                        ? Carbon::parse($dal)->toDateString()
                        : null)
                    ->helperText(fn (MachineUnit $record) => ($dal = $record->placements()->whereNull('removed_at')->max('placed_at'))
                        ? 'Dove si trova ora è dal '.Carbon::parse($dal)->format('d/m/Y').': non si può spostare prima.'
                        : null),
                // Chi paga dipende da dove va la macchina, non dalla macchina
                // (22/09/2026): vuoto = il nuovo cliente, o chi paga per lui.
                Forms\Components\Select::make('billing_customer_id')
                    ->label('Fatturare a')
                    ->helperText('Lascia vuoto se paga il cliente presso cui va (o chi paga già per lui).')
                    ->options(fn () => Customer::query()->orderBy('company_name')->get()->mapWithKeys(
                        fn (Customer $customer) => [$customer->id => DisplayName::customerOption($customer) ?: 'Cliente senza nome']
                    ))
                    ->searchable()
                    ->visible(fn (Forms\Get $get) => filled($get('customer_id'))),
                Forms\Components\Textarea::make('notes')->label('Note sullo spostamento'),
            ])
            ->action(function (MachineUnit $record, array $data) {
                $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;
                $pagante = ($data['billing_customer_id'] ?? null) ? Customer::find($data['billing_customer_id']) : null;
                $giorno = Carbon::parse($data['data']);
                // Oggi con l'ora di adesso, un altro giorno a inizio giornata.
                $record->moveTo($customer, $data['notes'] ?? null, $giorno->isToday() ? now() : $giorno->startOfDay(), $pagante, $pagante?->gestionale_code ? (int) $pagante->gestionale_code : null);

                Notification::make()
                    ->title($customer ? 'Macchina spostata presso '.DisplayName::titleCase($customer->company_name) : 'Macchina rientrata in magazzino')
                    ->success()
                    ->send();
            });
    }

    /**
     * "Sposta" fatto per sbaglio (cliente sbagliato, macchina sbagliata):
     * rimette la macchina dov'era prima, senza lasciare nello storico un
     * passaggio mai avvenuto.
     */
    public static function annullaSpostamentoAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Annulla ultimo spostamento')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (MachineUnit $record): bool => $record->canUndoLastMove())
            ->requiresConfirmation()
            ->modalHeading('Annullare l\'ultimo spostamento?')
            ->modalDescription(function (MachineUnit $record): string {
                $open = $record->placements()->whereNull('removed_at')->latest('placed_at')->first();
                $dove = $open?->customer ? DisplayName::customerOption($open->customer) : 'magazzino';

                return "La macchina torna dov'era prima dello spostamento a {$dove}"
                    .($open ? ' del '.$open->placed_at->format('d/m/Y H:i') : '').'. La riga sbagliata sparisce dallo storico.';
            })
            ->modalSubmitActionLabel('Annulla spostamento')
            ->action(function (MachineUnit $record) {
                $record->undoLastMove();
                $record->refresh();

                Notification::make()
                    ->title($record->currentCustomer
                        ? 'Macchina rimessa presso '.DisplayName::customerOption($record->currentCustomer)
                        : 'Macchina rimessa in magazzino')
                    ->success()
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            PlacementsRelationManager::class,
        ];
    }

    /**
     * Cosa succede davvero lasciando vuoto il campo, detto con i dati di
     * QUESTA macchina: il codice del modello e la variante del suo pagante.
     */
    /**
     * I codici manutenzione a catalogo, per il menu di suggerimenti.
     *
     * Solo i BASE: le varianti per pagante (F2GOPPION, F2HTS, F2DAN) e
     * quelle festive le sceglie da sola TariffeIntervento::manutenzione()
     * guardando chi paga, e offrirle qui farebbe scrivere a mano un codice
     * che poi verrebbe ricalcolato — con l'effetto di bloccare la variante
     * sbagliata se il cliente cambia torrefattore.
     *
     * @return array<int, string>
     */
    public static function codiciManutenzione(): array
    {
        $suffissi = collect(config('tariffe.paganti', []))
            ->pluck('manutenzione_suffisso')
            ->filter()
            ->unique()
            ->values();

        return Material::query()
            ->where('type', 'like', 'manutenzione%')
            ->orderBy('code')
            ->pluck('code')
            ->reject(fn (string $code) => str_ends_with($code, 'FEST')
                || $suffissi->contains(fn (string $s) => str_ends_with($code, $s) && $code !== $s))
            ->values()
            ->all();
    }

    protected static function aiutoCodiceManutenzione(?MachineUnit $record): string
    {
        if (! $record) {
            return 'Vuoto = si eredita dal modello a catalogo.';
        }

        $dalModello = $record->material?->maintenance_code;
        $risolto = TariffeIntervento::manutenzione($record, $record->currentCustomer);

        if (! $dalModello && ! $risolto) {
            return 'Ne\' qui ne\' il modello a catalogo hanno un codice: la scorciatoia "Manutenzione ordinaria" del rapportino non avra\' niente da mettere in riga.';
        }

        if (! $record->maintenance_code && $dalModello) {
            return $risolto !== $dalModello
                ? "Vuoto: eredita {$dalModello} dal modello, che col pagante diventa {$risolto}."
                : "Vuoto: eredita {$dalModello} dal modello.";
        }

        return $risolto && $risolto !== $record->maintenance_code
            ? "Col pagante diventa {$risolto}."
            : 'Vale solo per questa macchina, non per le altre dello stesso modello.';
    }

    public static function statusLabels(): array
    {
        return [
            MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
            MachineUnit::STATUS_INSTALLATA => 'Installata',
            MachineUnit::STATUS_RIMOSSA => 'Rimossa',
        ];
    }

    public static function statusColors(): array
    {
        return [
            MachineUnit::STATUS_IN_MAGAZZINO => 'gray',
            MachineUnit::STATUS_INSTALLATA => 'success',
            MachineUnit::STATUS_RIMOSSA => 'danger',
        ];
    }

    public static function typeLabels(): array
    {
        return [
            MachineUnit::TYPE_COLONNA_SPINA => 'Colonna spina (birra/vino/selz)',
            MachineUnit::TYPE_IMPIANTO_ACQUA => 'Impianto acqua',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMachineUnits::route('/'),
            'create' => Pages\CreateMachineUnit::route('/create'),
            'view' => Pages\ViewMachineUnit::route('/{record}'),
            'edit' => Pages\EditMachineUnit::route('/{record}/edit'),
        ];
    }
}
