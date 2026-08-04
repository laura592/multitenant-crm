<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceScheduleResource\Pages;
use App\Filament\Resources\MaintenanceScheduleResource\RelationManagers\LavaggiRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
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
     * Elenco impianti (MachineUnit) installati presso il cliente, non
     * altrimenti visibile su questa risorsa: vive su MachineUnit, non sul
     * piano di lavaggio stesso.
     */
    private static function equipmentSummary(?string $customerId): string
    {
        if (! $customerId) {
            return '—';
        }

        $labels = static::machineUnitsFor($customerId)->pluck('model_name');

        return $labels->isNotEmpty() ? $labels->implode(', ') : 'Nessun impianto registrato';
    }

    /**
     * Chi paga davvero: se il cliente ha piu' impianti con pagante diverso
     * (es. Gigi Marchetto: birra a se stesso, vino a Sutto), il
     * billing_customer_id del Customer da solo sarebbe fuorviante.
     */
    private static function billingSummary(?string $customerId): string
    {
        if (! $customerId) {
            return '—';
        }

        $customer = static::customerFor($customerId);
        if (! $customer) {
            return '—';
        }

        $units = static::machineUnitsFor($customerId);

        if ($units->isEmpty()) {
            return $customer->invoiceRecipient()->full_name;
        }

        $perUnit = $units->map(fn (MachineUnit $u) => ($u->billingCustomer?->full_name ?? 'se stesso'));

        return $perUnit->unique()->count() === 1
            ? $perUnit->first()
            : $units->map(fn (MachineUnit $u) => $u->model_name.': '.($u->billingCustomer?->full_name ?? 'se stesso'))->implode('; ');
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
                    'class' => 'rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900',
                ])
                ->schema([
                    TextEntry::make('customer.full_name')->label('Cliente')->columnSpan(5),
                    TextEntry::make('type')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'Lavaggio' : 'Manutenzione')
                        ->color(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'info' : 'gray')
                        ->columnSpan(2),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => $state === MaintenanceSchedule::STATUS_CHIUSO ? 'Chiuso' : 'Attivo')
                        ->color(fn (string $state) => $state === MaintenanceSchedule::STATUS_CHIUSO ? 'gray' : 'success')
                        ->columnSpan(2),
                    TextEntry::make('next_due_date')->label('Prossima scadenza')->date()->placeholder('—')->columnSpan(3),
                ]),
            InfolistGrid::make(12)
                ->schema([
                    InfolistSection::make('Cliente e macchina')
                        ->columnSpan(6)
                        ->schema([
                            TextEntry::make('comodatoMacchina.nome_macchina')
                                ->label('Macchina (comodato)')
                                ->placeholder('—')
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_MANUTENZIONE),
                            TextEntry::make('impianti_info')
                                ->label('Impianti installati')
                                ->state(fn (MaintenanceSchedule $record) => static::equipmentSummary($record->customer_id)),
                            TextEntry::make('pagante_info')
                                ->label('Fatturare a')
                                ->state(fn (MaintenanceSchedule $record) => static::billingSummary($record->customer_id)),
                        ]),
                    InfolistSection::make('Pianificazione')
                        ->columnSpan(6)
                        ->schema([
                            TextEntry::make('frequency')
                                ->label('Frequenza')
                                ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : '—')
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_MANUTENZIONE),
                            TextEntry::make('beverage_type')
                                ->label('Tipo impianto')
                                ->badge()
                                ->formatStateUsing(fn (?string $state) => match ($state) {
                                    MaintenanceSchedule::BEVERAGE_BIRRA => 'Birra',
                                    MaintenanceSchedule::BEVERAGE_ACQUA => 'Acqua',
                                    MaintenanceSchedule::BEVERAGE_VINO => 'Vino',
                                    default => '—',
                                })
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO),
                            TextEntry::make('frequency_days')
                                ->label('Cadenza')
                                ->formatStateUsing(fn (?string $state) => $state ? "Ogni {$state} giorni" : 'A chiamata')
                                ->visible(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO && $record->beverage_type !== MaintenanceSchedule::BEVERAGE_ACQUA),
                            TextEntry::make('filter_validity_days')
                                ->label('Validita\' filtro')
                                ->formatStateUsing(fn (?string $state) => $state ? "Ogni {$state} giorni (max 1 anno)" : 'Max 1 anno')
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
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('comodato_macchina_id')
                        ->label('Macchina (comodato)')
                        ->relationship('comodatoMacchina', 'nome_macchina')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_MANUTENZIONE),
                    Forms\Components\Placeholder::make('impianti_info')
                        ->label('Impianti installati')
                        ->content(fn (Forms\Get $get) => static::equipmentSummary($get('customer_id'))),
                    Forms\Components\Placeholder::make('pagante_info')
                        ->label('Fatturare a')
                        ->content(fn (Forms\Get $get) => static::billingSummary($get('customer_id'))),
                ]),
            Forms\Components\Section::make('Pianificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options([
                            MaintenanceSchedule::TYPE_MANUTENZIONE => 'Manutenzione',
                            MaintenanceSchedule::TYPE_LAVAGGIO => 'Lavaggio',
                        ])
                        ->default(MaintenanceSchedule::TYPE_MANUTENZIONE)
                        ->live()
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options([
                            MaintenanceSchedule::STATUS_ATTIVO => 'Attivo',
                            MaintenanceSchedule::STATUS_CHIUSO => 'Chiuso',
                        ])
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
                        ->options([
                            MaintenanceSchedule::BEVERAGE_BIRRA => 'Birra',
                            MaintenanceSchedule::BEVERAGE_ACQUA => 'Acqua',
                            MaintenanceSchedule::BEVERAGE_VINO => 'Vino',
                        ])
                        ->live()
                        ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                            if (isset(MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$state])) {
                                $set('frequency_days', MaintenanceSchedule::STANDARD_FREQUENCY_DAYS[$state]);
                            }
                        })
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO),
                    Forms\Components\TextInput::make('frequency_days')
                        ->label('Cadenza (giorni)')
                        ->numeric()
                        ->minValue(1)
                        ->live()
                        ->helperText('Es. 20 o 30. Ogni nuovo lavaggio registrato sposta in automatico la prossima scadenza di questi giorni. Lascia vuoto per un piano "a chiamata", senza cadenza fissa.')
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && $get('beverage_type') !== MaintenanceSchedule::BEVERAGE_ACQUA),
                    Forms\Components\TextInput::make('filter_validity_days')
                        ->label('Validita\' filtro (giorni)')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Scadenza = data ultima sostituzione filtro + questi giorni, con un tetto massimo di 1 anno anche se il filtro non si esaurisce prima. Lascia vuoto per usare solo il tetto annuale.')
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && $get('beverage_type') === MaintenanceSchedule::BEVERAGE_ACQUA),
                    Forms\Components\Placeholder::make('ultima_sostituzione_filtro')
                        ->label('Ultima sostituzione filtro')
                        ->content(fn (?MaintenanceSchedule $record) => $record?->lastFilterChange?->data?->format('d/m/Y') ?? 'Mai registrata')
                        ->visible(fn (Forms\Get $get, ?MaintenanceSchedule $record) => $record && $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && $get('beverage_type') === MaintenanceSchedule::BEVERAGE_ACQUA),
                    Forms\Components\DatePicker::make('next_due_date')
                        ->label('Prossima scadenza')
                        ->helperText(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO && blank($get('frequency_days')) && $get('beverage_type') !== MaintenanceSchedule::BEVERAGE_ACQUA
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
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'Lavaggio' : 'Manutenzione')
                    ->color(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'info' : 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('beverage_type')
                    ->label('Impianto')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        MaintenanceSchedule::BEVERAGE_BIRRA => 'Birra',
                        MaintenanceSchedule::BEVERAGE_ACQUA => 'Acqua',
                        MaintenanceSchedule::BEVERAGE_VINO => 'Vino',
                        default => '—',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        MaintenanceSchedule::BEVERAGE_BIRRA => 'warning',
                        MaintenanceSchedule::BEVERAGE_ACQUA => 'info',
                        MaintenanceSchedule::BEVERAGE_VINO => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('comodatoMacchina.nome_macchina')->label('Macchina')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('impianti')
                    ->label('Impianti')
                    ->state(fn (MaintenanceSchedule $record) => static::equipmentSummary($record->customer_id))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pagante')
                    ->label('Pagante')
                    ->state(fn (MaintenanceSchedule $record) => static::billingSummary($record->customer_id))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === MaintenanceSchedule::STATUS_CHIUSO ? 'Chiuso' : 'Attivo')
                    ->color(fn (string $state) => $state === MaintenanceSchedule::STATUS_CHIUSO ? 'gray' : 'success')
                    ->sortable(),
                Tables\Columns\TextColumn::make('frequenza_label')
                    ->label('Frequenza')
                    ->state(function (MaintenanceSchedule $record) {
                        if ($record->type !== MaintenanceSchedule::TYPE_LAVAGGIO) {
                            return $record->frequency ? ucfirst($record->frequency) : '—';
                        }

                        if ($record->beverage_type === MaintenanceSchedule::BEVERAGE_ACQUA) {
                            return $record->filter_validity_days ? "Filtro ogni {$record->filter_validity_days} giorni (max 1 anno)" : 'Filtro: max 1 anno';
                        }

                        return $record->frequency_days ? "Ogni {$record->frequency_days} giorni" : 'A chiamata';
                    }),
                Tables\Columns\TextColumn::make('next_due_date')
                    ->label('Prossima scadenza')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (?MaintenanceSchedule $record) => match (true) {
                        $record?->next_due_date === null => null,
                        $record->next_due_date->isPast() => 'danger',
                        $record->next_due_date->diffInDays(now()) <= 30 => 'warning',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('ultimo')
                    ->label('Ultimo')
                    ->state(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO
                        ? $record->lastLavaggio?->data?->format('d/m/Y')
                        : $record->lastServiceReport?->number)
                    ->placeholder('Mai eseguito'),
            ])
            ->filters([
                Tables\Filters\Filter::make('nascondi_chiusi')
                    ->label('Nascondi chiusi')
                    ->query(fn ($query) => $query->where('status', '!=', MaintenanceSchedule::STATUS_CHIUSO))
                    ->default(),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        MaintenanceSchedule::TYPE_MANUTENZIONE => 'Manutenzione',
                        MaintenanceSchedule::TYPE_LAVAGGIO => 'Lavaggio',
                    ]),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('beverage_type')
                    ->label('Impianto')
                    ->options([
                        MaintenanceSchedule::BEVERAGE_BIRRA => 'Birra',
                        MaintenanceSchedule::BEVERAGE_ACQUA => 'Acqua',
                        MaintenanceSchedule::BEVERAGE_VINO => 'Vino',
                    ]),
                Tables\Filters\Filter::make('due_soon')
                    ->label('In scadenza entro 30 giorni')
                    ->query(fn ($query) => $query->where('next_due_date', '<=', now()->addDays(30))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\EditAction::make()
                    ->color('gray'),
                Tables\Actions\DeleteAction::make(),
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

    public static function getRelations(): array
    {
        return [
            LavaggiRelationManager::class,
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
