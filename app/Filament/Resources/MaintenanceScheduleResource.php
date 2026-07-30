<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaintenanceScheduleResource\Pages;
use App\Filament\Resources\MaintenanceScheduleResource\RelationManagers\LavaggiRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use Filament\Forms;
use Filament\Forms\Form;
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
     * Elenco impianti (MachineUnit) installati presso il cliente, non
     * altrimenti visibile su questa risorsa: vive su MachineUnit, non sul
     * piano di lavaggio stesso.
     */
    private static function equipmentSummary(?string $customerId): string
    {
        if (! $customerId) {
            return '—';
        }

        $labels = MachineUnit::where('current_customer_id', $customerId)->pluck('model_name');

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

        $customer = Customer::find($customerId);
        if (! $customer) {
            return '—';
        }

        $units = MachineUnit::where('current_customer_id', $customerId)->get();

        if ($units->isEmpty()) {
            return $customer->invoiceRecipient()->full_name;
        }

        $perUnit = $units->map(fn (MachineUnit $u) => ($u->billingCustomer?->full_name ?? 'se stesso'));

        return $perUnit->unique()->count() === 1
            ? $perUnit->first()
            : $units->map(fn (MachineUnit $u) => $u->model_name.': '.($u->billingCustomer?->full_name ?? 'se stesso'))->implode('; ');
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
                    Forms\Components\TextInput::make('frequency_days')
                        ->label('Cadenza (giorni)')
                        ->numeric()
                        ->minValue(1)
                        ->live()
                        ->helperText('Es. 20 o 30. Ogni nuovo lavaggio registrato sposta in automatico la prossima scadenza di questi giorni. Lascia vuoto per un piano "a chiamata", senza cadenza fissa.')
                        ->visible(fn (Forms\Get $get) => $get('type') === MaintenanceSchedule::TYPE_LAVAGGIO),
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
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'Lavaggio' : 'Manutenzione')
                    ->color(fn (string $state) => $state === MaintenanceSchedule::TYPE_LAVAGGIO ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    ->color(fn (string $state) => $state === MaintenanceSchedule::STATUS_CHIUSO ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('frequenza_label')
                    ->label('Frequenza')
                    ->state(fn (MaintenanceSchedule $record) => $record->type === MaintenanceSchedule::TYPE_LAVAGGIO
                        ? ($record->frequency_days ? "Ogni {$record->frequency_days} giorni" : 'A chiamata')
                        : ($record->frequency ? ucfirst($record->frequency) : '—')),
                Tables\Columns\TextColumn::make('next_due_date')
                    ->label('Prossima scadenza')
                    ->date()
                    ->placeholder('—')
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
                Tables\Filters\Filter::make('due_soon')
                    ->label('In scadenza entro 30 giorni')
                    ->query(fn ($query) => $query->where('next_due_date', '<=', now()->addDays(30))),
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
            'edit' => Pages\EditMaintenanceSchedule::route('/{record}/edit'),
        ];
    }
}
