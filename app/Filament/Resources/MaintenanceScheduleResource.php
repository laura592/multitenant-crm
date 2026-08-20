<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceScheduleResource\Pages;
use App\Filament\Resources\MaintenanceScheduleResource\RelationManagers\InterventiRelationManager;
use App\Filament\Resources\MaintenanceScheduleResource\RelationManagers\LavaggiRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Support\DisplayName;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid as InfolistGrid;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MaintenanceScheduleResource extends Resource
{
    protected static ?string $model = MaintenanceSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Piani di manutenzione';

    protected static ?string $modelLabel = 'Piano di manutenzione';

    protected static ?string $pluralModelLabel = 'Piani di manutenzione';

    // Senza questo, Filament forza il Title Case e capitalizza anche il "di"
    // ("Piani Di Manutenzione").
    protected static bool $hasTitleCaseModelLabel = false;

    /**
     * Cache di richiesta per customer_id: equipmentSummary()/billingSummary()
     * sono chiamate una volta per riga di tabella (piu' l'infolist), e lo
     * stesso cliente ricorre spesso su piu' piani (es. manutenzione +
     * lavaggio) - senza questa cache ogni riga rilancerebbe le stesse query
     * su MachineUnit/Customer (N+1 su liste lunghe).
     *
     * @var array<string, \Illuminate\Support\Collection<int, MachineUnit>>
     */
    private static array $machineUnitsCache = [];

    /** @var array<string, Customer|null> */
    private static array $customerCache = [];

    private static function machineUnitsFor(string $customerId): \Illuminate\Support\Collection
    {
        return static::$machineUnitsCache[$customerId] ??= MachineUnit::where('current_customer_id', $customerId)
            ->with('billingCustomer')
            ->get();
    }

    private static function customerFor(string $customerId): ?Customer
    {
        return static::$customerCache[$customerId] ??= Customer::find($customerId);
    }

    /**
     * Etichetta "composizione impianto" (birra/vino/... e quante vie) usata
     * sia nell'infolist che nel widget "Piani in scadenza": conta di piu' di
     * un semplice "lavaggio vs manutenzione", perche' per un piano lavaggio
     * quest'ultimo non diceva nulla di utile.
     */
    public static function impiantoHero(MaintenanceSchedule $record): string
    {
        if ($record->type !== MaintenanceSchedule::TYPE_LAVAGGIO) {
            return 'Manutenzione';
        }

        $label = static::beverageLabels()[$record->beverage_type] ?? 'Lavaggio';

        if (! $record->lines_count || $record->beverage_type === MaintenanceSchedule::BEVERAGE_ACQUA) {
            return $label;
        }

        return $label.' · '.$record->lines_count.($record->lines_count === 1 ? ' via' : ' vie');
    }

    /**
     * Impianto/i rilevanti per questo piano. Se il piano ha una macchina
     * collegata (machine_unit_id) mostra solo quella - e' il caso normale,
     * altrimenti un piano lavaggio birra mostrerebbe anche il macinacaffe'
     * dello stesso cliente. Senza macchina collegata (piani legacy non ancora
     * sistemati) resta il vecchio riepilogo su tutto il parco macchine del
     * cliente, cosi' il dato sparisce del tutto invece di essere sbagliato.
     */
    private static function equipmentSummary(?string $customerId, ?string $machineUnitId = null): string
    {
        if ($machineUnitId) {
            return MachineUnit::find($machineUnitId)?->display_name ?? '—';
        }

        if (! $customerId) {
            return '—';
        }

        $labels = static::machineUnitsFor($customerId)->pluck('model_name');

        return $labels->isNotEmpty() ? $labels->implode(', ') : 'Nessun impianto registrato';
    }

    /**
     * Chi paga davvero. Con una macchina collegata al piano il pagante e'
     * quello della macchina stessa (o il pagante di default del cliente se la
     * macchina non ne ha uno proprio) - non serve piu' indovinare su tutto il
     * parco macchine. Senza macchina collegata (piani legacy), stesso
     * fallback "Misto: ..." di prima: se il cliente ha piu' impianti con
     * pagante diverso (es. Gigi Marchetto: birra a se stesso, vino a Sutto),
     * il billing_customer_id del Customer da solo sarebbe fuorviante.
     */
    private static function billingSummary(?string $customerId, ?string $machineUnitId = null): string
    {
        if ($machineUnitId) {
            $unit = MachineUnit::with('billingCustomer')->find($machineUnitId);

            if ($unit) {
                return DisplayName::titleCase($unit->billingCustomer?->full_name) ?? DisplayName::titleCase(static::customerFor($customerId ?? '')?->invoiceRecipient()->full_name) ?? '—';
            }
        }

        if (! $customerId) {
            return '—';
        }

        $customer = static::customerFor($customerId);
        if (! $customer) {
            return '—';
        }

        $units = static::machineUnitsFor($customerId);

        if ($units->isEmpty()) {
            return DisplayName::titleCase($customer->invoiceRecipient()->full_name);
        }

        $perUnit = $units->map(fn (MachineUnit $u) => DisplayName::titleCase($u->billingCustomer?->full_name) ?? 'se stesso');

        return $perUnit->unique()->count() === 1
            ? $perUnit->first()
            : $units->map(fn (MachineUnit $u) => $u->model_name.': '.(DisplayName::titleCase($u->billingCustomer?->full_name) ?? 'se stesso'))->implode('; ');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            // Stesso pattern di QuoteResource/ServiceReportResource: riepilogo
            // a colpo d'occhio in alto, i dettagli restano nelle sezioni sotto
            // senza ripetere qui i campi gia' mostrati nell'hero.
            InfolistSection::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    TextEntry::make('customer.full_name')->label('Cliente')->columnSpan(4)
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    // Al colpo d'occhio conta di piu' "cosa" (birra/vino/... e
                    // quante vie) che il semplice "lavaggio vs manutenzione":
                    // sostituisce il vecchio badge "Tipo", che per un piano
                    // lavaggio non diceva nulla di utile.
                    TextEntry::make('impianto_hero')
                        ->label('Impianto')
                        ->state(fn (MaintenanceSchedule $record) => static::impiantoHero($record))
                        ->badge()
                        ->color(fn (MaintenanceSchedule $record) => static::beverageColors()[$record->beverage_type] ?? 'gray')
                        ->columnSpan(3),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? 'Attivo')
                        ->color(fn (string $state) => static::statusColors()[$state] ?? 'success')
                        ->columnSpan(2),
                    TextEntry::make('next_due_date')->label('Prossima scadenza')->date()->placeholder('—')->columnSpan(3),
                ]),
            InfolistGrid::make(12)
                ->schema([
                    InfolistSection::make('Cliente e macchina')
                        ->columnSpan(6)
                        ->schema([
                            TextEntry::make('machineUnit.display_name')
                                ->label('Macchina')
                                ->placeholder('—'),
                            TextEntry::make('impianti_info')
                                ->label('Impianti installati')
                                ->state(fn (MaintenanceSchedule $record) => static::equipmentSummary($record->customer_id, $record->machine_unit_id))
                                // Con machine_unit_id gia' impostato, equipmentSummary()
                                // torna esattamente lo stesso display_name gia' mostrato
                                // dal campo "Macchina" - pura duplicazione,
                                // niente da aggiungere. Resta utile solo come fallback
                                // sul parco macchine del cliente quando il piano NON ha
                                // ancora una macchina specifica collegata.
                                ->visible(fn (MaintenanceSchedule $record) => ! $record->machine_unit_id),
                            TextEntry::make('pagante_info')
                                ->label('Fatturare a')
                                ->state(fn (MaintenanceSchedule $record) => static::billingSummary($record->customer_id, $record->machine_unit_id)),
                        ]),
                    InfolistSection::make('Pianificazione')
                        ->columnSpan(6)
                        ->schema([
                            TextEntry::make('frequency')
                                ->label('Frequenza')
                                ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : '—')
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_MANUTENZIONE),
                            TextEntry::make('frequency_days')
                                ->label('Cadenza')
                                ->formatStateUsing(fn (?string $state) => $state ? "Ogni {$state} giorni" : 'A chiamata')
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO),
                            TextEntry::make('filter_validity_days')
                                ->label('Validita\' filtro (info)')
                                ->formatStateUsing(fn (?string $state) => $state ? "Ogni {$state} giorni" : '—')
                                ->placeholder('—')
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO && $record->beverage_type === MaintenanceSchedule::BEVERAGE_ACQUA),
                            TextEntry::make('lastFilterChange.data')
                                ->label('Ultima sostituzione filtro')
                                ->date()
                                ->placeholder('Mai registrata')
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO && $record->beverage_type === MaintenanceSchedule::BEVERAGE_ACQUA),
                            TextEntry::make('notes')->label('Note')->placeholder('—')->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cliente e macchina')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::titleCase($record->full_name))
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Macchina')
                        ->relationship(
                            'machineUnit',
                            'serial_number',
                            modifyQueryUsing: fn ($query, Forms\Get $get) => $query
                                ->where('current_customer_id', $get('customer_id')),
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                        ->searchable()
                        ->preload()
                        ->live()
                        ->disabled(fn (Forms\Get $get) => blank($get('customer_id')))
                        ->helperText('Qui compaiono solo i macchinari attualmente installati presso il cliente selezionato, in comodato o meno.'),
                    Forms\Components\Placeholder::make('impianti_info')
                        ->label('Impianti installati')
                        ->content(fn (Forms\Get $get) => static::equipmentSummary($get('customer_id'), $get('machine_unit_id')))
                        // Stessa duplicazione dell'infolist quando machine_unit_id e'
                        // gia' impostato (equipmentSummary() torna lo stesso valore
                        // del select "Macchina"): visibile solo come
                        // fallback sul parco macchine quando non c'e' ancora una
                        // macchina specifica scelta.
                        ->visible(fn (Forms\Get $get) => blank($get('machine_unit_id'))),
                    Forms\Components\Placeholder::make('pagante_info')
                        ->label('Fatturare a')
                        ->content(fn (Forms\Get $get) => static::billingSummary($get('customer_id'), $get('machine_unit_id'))),
                ]),
            Forms\Components\Section::make('Pianificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(static::typeLabels())
                        ->default(MaintenanceSchedule::TYPE_MANUTENZIONE)
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(static::statusLabels())
                        ->default(MaintenanceSchedule::STATUS_ATTIVO)
                        ->required(),
                    Forms\Components\Select::make('frequency')
                        ->label('Frequenza')
                        ->options([
                            'mensile' => 'Mensile',
                            'trimestrale' => 'Trimestrale',
                            'semestrale' => 'Semestrale',
                            'annuale' => 'Annuale',
                        ])
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE)
                        ->required(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE),
                    Forms\Components\Select::make('beverage_type')
                        ->label('Tipo impianto')
                        ->options(static::beverageLabels())
                        ->live()
                        // Il vino non ha mai una cadenza standard (resta "a
                        // chiamata"): sovrascrive esplicitamente anche un
                        // valore lasciato da un beverage_type precedente.
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set) => $set('frequency_days', MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$state] ?? null))
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO),
                    Forms\Components\TextInput::make('lines_count')
                        ->label('Numero vie')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Numero di rubinetti/linee collegati a questo impianto (es. 8 vie vino).')
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && $get('beverage_type') !== MaintenanceSchedule::BEVERAGE_ACQUA),
                    Forms\Components\TextInput::make('frequency_days')
                        ->label('Cadenza (giorni)')
                        ->numeric()
                        ->minValue(1)
                        ->live()
                        // Stessa cadenza per tutti i beverage_type, acqua
                        // inclusa: prima l'acqua aveva una regola a parte
                        // (sanificazione ogni 4 mesi dall'ultimo cambio
                        // filtro) che senza un lavaggio marcato "filtro
                        // sostituito" lasciava la scadenza sempre vuota.
                        ->helperText('Es. 20 o 30 (per l\'acqua tipicamente 120). Ogni nuovo lavaggio registrato sposta in automatico la prossima scadenza di questi giorni. Lascia vuoto per un piano "a chiamata", senza cadenza fissa.')
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO),
                    Forms\Components\TextInput::make('filter_validity_days')
                        ->label('Validita\' filtro (giorni)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Solo informativo, non calcola piu\' la scadenza (vedi "Cadenza" sopra): utile per sapere quando il filtro va sostituito a prescindere dalla prossima sanificazione.')
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && $get('beverage_type') === MaintenanceSchedule::BEVERAGE_ACQUA),
                    Forms\Components\Placeholder::make('ultima_sostituzione_filtro')
                        ->label('Ultima sostituzione filtro')
                        ->content(fn (?MaintenanceSchedule $record) => $record?->lastFilterChange?->data?->format('d/m/Y') ?? 'Mai registrata')
                        ->visible(fn (Forms\Get $get, ?MaintenanceSchedule $record) => $record && $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && $get('beverage_type') === MaintenanceSchedule::BEVERAGE_ACQUA),
                    Forms\Components\DatePicker::make('next_due_date')
                        ->label('Prossima scadenza')
                        ->helperText(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && blank($get('frequency_days'))
                            ? 'Piano a chiamata: nessuna scadenza automatica.'
                            : null)
                        ->required(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE || filled($get('frequency_days')))
                        ->default(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE ? now()->addMonth() : null),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // I piani "a chiamata" hanno next_due_date nullo: con un ORDER BY
            // ASC normale MySQL li mette per primi, seppellendo le scadenze
            // vere. "IS NULL" prima valuta a 0/1, quindi i null (1) finiscono
            // in fondo.
            ->defaultSort(fn ($query) => $query->orderByRaw('next_due_date IS NULL, next_due_date ASC'))
            // Vista di apertura raggruppata per cliente (una volta sola, con
            // tutta la sua composizione - lavaggi + manutenzioni - sotto);
            // l'ordinamento per prossima scadenza resta disponibile
            // togliendo il raggruppamento dal selettore "Raggruppa per".
            ->groups([
                Tables\Grouping\Group::make('customer.company_name')
                    ->label('Cliente')
                    ->getKeyFromRecordUsing(fn (MaintenanceSchedule $record) => $record->customer_id)
                    ->getTitleFromRecordUsing(fn (MaintenanceSchedule $record) => DisplayName::titleCase($record->customer?->full_name) ?? '—'),
            ])
            ->defaultGroup('customer.company_name')
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::typeLabels()[$state] ?? 'Manutenzione')
                    ->color(fn (string $state) => static::typeColors()[$state] ?? 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('beverage_type')
                    ->label('Impianto')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => static::beverageLabels()[$state] ?? '—')
                    ->color(fn (?string $state) => static::beverageColors()[$state] ?? 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('lines_count')
                    ->label('Vie')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('machineUnit.display_name')->label('Macchina')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('impianti')
                    ->label('Impianti')
                    ->state(fn (MaintenanceSchedule $record) => static::equipmentSummary($record->customer_id, $record->machine_unit_id))
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('pagante')
                    ->label('Pagante')
                    ->state(fn (MaintenanceSchedule $record) => static::billingSummary($record->customer_id, $record->machine_unit_id))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? 'Attivo')
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('frequenza_label')
                    ->label('Frequenza')
                    ->state(function (MaintenanceSchedule $record) {
                        if ($record->type !== MaintenanceSchedule::TYPE_LAVAGGIO) {
                            return $record->frequency ? ucfirst($record->frequency) : '—';
                        }

                        return $record->frequency_days ? "Ogni {$record->frequency_days} giorni" : 'A chiamata';
                    }),
                Tables\Columns\TextColumn::make('next_due_date')
                    ->label('Prossima scadenza')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (?MaintenanceSchedule $record) => $record?->next_due_date?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('ultimo')
                    ->label('Ultimo')
                    ->state(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO
                        ? $record->lastLavaggio?->data?->format('d/m/Y')
                        : $record->lastServiceReport?->intervention_date?->format('d/m/Y'))
                    ->placeholder('Mai eseguito'),
            ])
            ->filters([
                Tables\Filters\Filter::make('nascondi_chiusi')
                    ->label('Nascondi chiusi')
                    ->query(fn ($query) => $query->where('status', '!=', MaintenanceSchedule::STATUS_CHIUSO))
                    ->default(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(static::typeLabels()),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::titleCase($record->full_name))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('beverage_type')
                    ->label('Impianto')
                    ->options(static::beverageLabels()),
                Tables\Filters\SelectFilter::make('cadenza')
                    ->label('Cadenza')
                    ->options([
                        'programmata' => 'Programmata',
                        'a_chiamata' => 'A chiamata',
                    ])
                    // Nessuna colonna dedicata: "a chiamata" e' gia'
                    // rappresentato da frequency_days nullo (vedi
                    // MaintenanceSchedule::recalculateLavaggioNextDue) - un
                    // campo separato duplicherebbe lo stesso stato e
                    // rischierebbe di disallinearsi da esso.
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'programmata' => $query->whereNotNull('frequency_days'),
                        'a_chiamata' => $query->whereNull('frequency_days'),
                        default => $query,
                    }),
                Tables\Filters\Filter::make('due_soon')
                    ->label('In scadenza entro 30 giorni')
                    ->query(fn ($query) => $query->where('next_due_date', '<=', now()->addDays(30))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('applica_cadenza_standard')
                        ->label('Applica cadenza standard')
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription('Imposta la cadenza standard (birra 30 giorni, vino 90 giorni) sui piani selezionati con impianto birra o vino e ricalcola la prossima scadenza. I piani acqua o senza tipo impianto assegnato vengono ignorati.')
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $records
                                ->filter(fn (MaintenanceSchedule $schedule) => isset(MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$schedule->beverage_type]))
                                ->each(function (MaintenanceSchedule $schedule) {
                                    $schedule->update(['frequency_days' => MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$schedule->beverage_type]]);
                                    $schedule->recalculateLavaggioNextDue();
                                });
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function statusLabels(): array
    {
        return [
            MaintenanceSchedule::STATUS_ATTIVO => 'Attivo',
            MaintenanceSchedule::STATUS_CHIUSO => 'Chiuso',
        ];
    }

    public static function statusColors(): array
    {
        return [
            MaintenanceSchedule::STATUS_ATTIVO => 'success',
            MaintenanceSchedule::STATUS_CHIUSO => 'gray',
        ];
    }

    public static function typeLabels(): array
    {
        return [
            MaintenanceSchedule::TYPE_MANUTENZIONE => 'Manutenzione',
            MaintenanceSchedule::TYPE_LAVAGGIO => 'Lavaggio',
        ];
    }

    public static function typeColors(): array
    {
        return [
            MaintenanceSchedule::TYPE_MANUTENZIONE => 'gray',
            MaintenanceSchedule::TYPE_LAVAGGIO => 'info',
        ];
    }

    public static function beverageLabels(): array
    {
        return [
            MaintenanceSchedule::BEVERAGE_BIRRA => 'Birra',
            MaintenanceSchedule::BEVERAGE_ACQUA => 'Acqua',
            MaintenanceSchedule::BEVERAGE_VINO => 'Vino',
            MaintenanceSchedule::BEVERAGE_BIBITE => 'Bibite',
            MaintenanceSchedule::BEVERAGE_SELZ => 'Selz',
            MaintenanceSchedule::BEVERAGE_SPRITZ => 'Spritz',
        ];
    }

    public static function beverageColors(): array
    {
        return [
            MaintenanceSchedule::BEVERAGE_BIRRA => 'warning',
            MaintenanceSchedule::BEVERAGE_ACQUA => 'info',
            MaintenanceSchedule::BEVERAGE_VINO => 'danger',
            MaintenanceSchedule::BEVERAGE_BIBITE => 'success',
            MaintenanceSchedule::BEVERAGE_SELZ => 'gray',
        ];
    }

    public static function getRelations(): array
    {
        return [
            LavaggiRelationManager::class,
            InterventiRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenanceSchedules::route('/'),
            'create' => Pages\CreateMaintenanceSchedule::route('/create'),
            'view' => Pages\ViewMaintenanceSchedule::route('/{record}'),
            'edit' => Pages\EditMaintenanceSchedule::route('/{record}/edit'),
        ];
    }
}
