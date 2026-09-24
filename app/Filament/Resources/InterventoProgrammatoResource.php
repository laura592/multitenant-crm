<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InterventoProgrammatoResource\Pages;
use App\Models\Customer;
use App\Models\InterventoProgrammato;
use App\Models\MachineUnit;
use App\Models\User;
use App\Support\DisplayName;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * La programmazione: l'ufficio prepara il giro, il tecnico lo legge
 * (24/09/2026).
 *
 * Prima stava su un foglio a quadretti con la carta intestata — "28/09
 * chiusura Barricata + Isamar", "05/10 Garden ⇒ pomeriggio" — barrato quando
 * fatto, e i tecnici lo sapevano per telefono.
 *
 * Le due letture sono diverse e la pagina serve tutte e due: l'ufficio guarda
 * la settimana per riempire i buchi e vedere chi non ha ancora un nome
 * accanto; il tecnico guarda il proprio giro di oggi, dal telefono, e spunta.
 * Per questo un dipendente qui vede solo i propri interventi
 * (getEloquentQuery()) e non puo' assegnarli.
 */
class InterventoProgrammatoResource extends Resource
{
    protected static ?string $model = InterventoProgrammato::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Programmazione';

    protected static ?string $modelLabel = 'intervento programmato';

    protected static ?string $pluralModelLabel = 'programmazione';

    protected static bool $hasTitleCaseModelLabel = false;

    // Senza, la rotta viene "intervento-programmatos".
    protected static ?string $slug = 'programmazione';

    /**
     * Il tecnico vede il proprio giro, non quello degli altri: una lista di
     * tutti i lavori di tutti, sul telefono, non e' un foglio di marcia.
     * Chi prepara il programma (ufficio, admin) li vede tutti.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $utente = auth()->user();

        if ($utente && ! $utente->can('assegna', InterventoProgrammato::class)) {
            $query->where('technician_id', $utente->getKey());
        }

        return $query;
    }

    /** Il pallino sul menu: quanti lavori ho davanti oggi (o quanti ne restano da assegnare). */
    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()
            ->daFare()
            ->whereDate('data', '<=', Carbon::today())
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Quando')
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    Forms\Components\DatePicker::make('data')
                        ->label('Giorno')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(now()),
                    Forms\Components\Select::make('momento')
                        ->label('Quando nella giornata')
                        ->options(InterventoProgrammato::momenti())
                        ->default(InterventoProgrammato::MOMENTO_GIORNATA)
                        ->required()
                        ->helperText('Si scrive solo quando conta: di norma l\'ordine lo decide il tecnico.'),
                    Forms\Components\Select::make('technician_id')
                        ->label('Chi ci va')
                        ->options(fn () => User::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->placeholder('Ancora da assegnare')
                        // Un tecnico puo' spuntare quello che ha fatto, non
                        // decidere chi ci va.
                        ->disabled(fn () => ! auth()->user()?->can('assegna', InterventoProgrammato::class)),
                ]),

            Forms\Components\Section::make('Che cosa')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    Forms\Components\Select::make('tipo')
                        ->label('Tipo di lavoro')
                        ->options(InterventoProgrammato::tipi())
                        ->default(InterventoProgrammato::TIPO_CHIUSURA)
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name')
                        ->getOptionLabelFromRecordUsing(fn (Customer $record) => DisplayName::customerOption($record))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('machine_unit_id', null))
                        ->helperText('Si puo\' lasciare vuoto: sul foglio ci sono anche i promemoria dell\'ufficio.'),
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Macchina')
                        ->options(fn (Get $get) => $get('customer_id')
                            ? MachineUnit::query()
                                ->where('current_customer_id', $get('customer_id'))
                                ->orderBy('serial_number')
                                ->get()
                                ->mapWithKeys(fn (MachineUnit $m) => [$m->id => $m->display_name.' — '.$m->serial_number])
                                ->all()
                            : [])
                        ->searchable()
                        ->placeholder('Tutte, o non ancora deciso'),
                    Forms\Components\TextInput::make('titolo')
                        ->label('In due parole')
                        ->maxLength(255)
                        ->placeholder('Es. "Chiusura Barricata + Isamar", "Scrivere agenzia per rapportino sparito"')
                        ->helperText('Quello che si leggeva sul foglio. Senza cliente, e\' l\'unica cosa che si vede.')
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    Forms\Components\Textarea::make('note')
                        ->label('Note')
                        ->rows(2)
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                ]),

            Forms\Components\Section::make('Stato')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    Forms\Components\Select::make('stato')
                        ->label('Stato')
                        ->options(InterventoProgrammato::stati())
                        ->default(InterventoProgrammato::STATO_DA_FARE)
                        ->required(),
                    Forms\Components\Placeholder::make('rapportino')
                        ->label('Rapportino')
                        ->content(fn (?InterventoProgrammato $record) => $record?->serviceReport?->number ?: '—')
                        ->visible(fn (?InterventoProgrammato $record) => $record?->service_report_id !== null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultGroup('data')
            ->groups([
                Tables\Grouping\Group::make('data')
                    ->label('Giorno')
                    ->date()
                    ->collapsible(),
                Tables\Grouping\Group::make('technician.name')
                    ->label('Tecnico')
                    ->collapsible(),
            ])
            // L'ordine del giro: prima i giorni, dentro il giorno la mattina
            // prima del pomeriggio.
            ->modifyQueryUsing(fn (Builder $query) => $query->ordinato())
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('data')
                    ->label('Giorno')
                    ->date('d/m/Y')
                    ->sortable()
                    ->description(fn (InterventoProgrammato $r) => $r->momento === InterventoProgrammato::MOMENTO_GIORNATA
                        ? null
                        : InterventoProgrammato::momenti()[$r->momento] ?? null)
                    ->color(fn (InterventoProgrammato $r) => $r->inRitardo() ? 'danger' : null),
                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('Dove')
                    ->formatStateUsing(fn ($state, InterventoProgrammato $r) => $r->etichetta())
                    ->description(fn (InterventoProgrammato $r) => $r->customer?->city)
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Lavoro')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => InterventoProgrammato::tipi()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        InterventoProgrammato::TIPO_CHIUSURA => 'warning',
                        InterventoProgrammato::TIPO_APERTURA => 'success',
                        InterventoProgrammato::TIPO_RITIRO => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('stato')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => InterventoProgrammato::stati()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        InterventoProgrammato::STATO_FATTO => 'success',
                        InterventoProgrammato::STATO_ANNULLATO => 'gray',
                        default => 'warning',
                    }),
                // Da tablet in su: sul telefono la riga deve restare leggibile
                // senza scorrere di lato (vedi docs/regole-di-business.md §10).
                Tables\Columns\TextColumn::make('technician.name')
                    ->label('Chi ci va')
                    ->placeholder('Da assegnare')
                    ->visibleFrom('md')
                    ->searchable(),
                Tables\Columns\TextColumn::make('machineUnit.serial_number')
                    ->label('Macchina')
                    ->placeholder('—')
                    ->visibleFrom('lg')
                    ->searchable(),
                Tables\Columns\TextColumn::make('note')
                    ->label('Note')
                    ->limit(40)
                    ->placeholder('—')
                    ->visibleFrom('lg')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stato')
                    ->label('Stato')
                    ->options(InterventoProgrammato::stati())
                    ->default(InterventoProgrammato::STATO_DA_FARE),
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo di lavoro')
                    ->options(InterventoProgrammato::tipi()),
                Tables\Filters\SelectFilter::make('technician_id')
                    ->label('Tecnico')
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn () => auth()->user()?->can('assegna', InterventoProgrammato::class)),
                Tables\Filters\Filter::make('da_assegnare')
                    ->label('Senza un nome accanto')
                    ->query(fn (Builder $query) => $query->whereNull('technician_id'))
                    ->visible(fn () => auth()->user()?->can('assegna', InterventoProgrammato::class)),
                Tables\Filters\Filter::make('questa_settimana')
                    ->label('Questa settimana')
                    ->query(fn (Builder $query) => $query->whereBetween('data', [
                        Carbon::today()->startOfWeek(),
                        Carbon::today()->endOfWeek(),
                    ])),
            ])
            ->actions([
                // L'azione del tecnico: due tocchi dal telefono.
                Tables\Actions\Action::make('fatto')
                    ->label('Fatto')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (InterventoProgrammato $r) => $r->stato === InterventoProgrammato::STATO_DA_FARE
                        && auth()->user()?->can('update', $r))
                    ->requiresConfirmation()
                    ->modalHeading('Segnare come fatto?')
                    ->modalDescription(fn (InterventoProgrammato $r) => $r->etichetta().' — '.$r->data->format('d/m/Y'))
                    ->action(function (InterventoProgrammato $r) {
                        $r->segnaFatto();

                        Notification::make()->title('Segnato come fatto.')->success()->send();
                    }),
                Tables\Actions\Action::make('rapportino')
                    ->label('Fai il rapportino')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->visible(fn (InterventoProgrammato $r) => $r->customer_id !== null
                        && $r->service_report_id === null)
                    ->url(fn (InterventoProgrammato $r) => ServiceReportResource::getUrl('create', array_filter([
                        'customer_id' => $r->customer_id,
                        'machine_unit_id' => $r->machine_unit_id,
                        'intervention_type' => $r->tipoRapportino(),
                        'programmato_id' => $r->id,
                    ]))),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Niente in programma')
            ->emptyStateDescription('Quando l\'ufficio prepara il giro, i lavori compaiono qui.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInterventiProgrammati::route('/'),
            'create' => Pages\CreateInterventoProgrammato::route('/create'),
            'edit' => Pages\EditInterventoProgrammato::route('/{record}/edit'),
        ];
    }
}
