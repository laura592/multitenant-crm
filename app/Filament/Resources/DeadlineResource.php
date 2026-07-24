<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeadlineResource\Pages;
use App\Models\Deadline;
use App\Models\Tenant;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

/**
 * Scadenzario amministrativo (docs/architecture.md §13.2): assicurazioni/
 * revisioni/bollo automezzi, polizze RCT e rinnovi contratto tenant. I piani
 * di manutenzione (App\Filament\Resources\MaintenanceScheduleResource) sono
 * volutamente slegati da qui: sono uno strumento operativo dei tecnici sul
 * campo, non una scadenza amministrativa, e hanno la propria gestione
 * autonoma di next_due_date.
 */
class DeadlineResource extends Resource
{
    protected static ?string $model = Deadline::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?string $navigationLabel = 'Scadenzario';

    protected static ?string $modelLabel = 'Scadenza';

    protected static ?string $pluralModelLabel = 'Scadenzario';

    /**
     * Stesso conteggio di PrioritaWidget ("Scadenze urgenti"), ma visibile
     * direttamente in sidebar senza dover aprire la Dashboard.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Deadline::where('status', Deadline::STATUS_ATTIVA)->get()->filter->isUrgent()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Scadenza')
                ->columns(2)
                ->schema([
                    Forms\Components\MorphToSelect::make('deadlinable')
                        ->label('Collegata a')
                        ->types([
                            Forms\Components\MorphToSelect\Type::make(Vehicle::class)->titleAttribute('plate'),
                            Forms\Components\MorphToSelect\Type::make(Tenant::class)->titleAttribute('name'),
                        ])
                        ->searchable()
                        ->columnSpanFull()
                        ->required(),
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(fn () => Deadline::typeLabels())
                        ->required(),
                    Forms\Components\TextInput::make('policy_number')
                        ->label('Numero polizza/riferimento')
                        ->maxLength(255),
                    Forms\Components\DatePicker::make('due_date')->label('Scadenza')->required(),
                    Forms\Components\TextInput::make('reminder_days_before')
                        ->label('Preavviso (giorni)')
                        ->numeric()
                        ->default(30)
                        ->helperText('Da quanti giorni prima della scadenza viene segnalata come urgente.'),
                ]),
            Forms\Components\Section::make('Stato')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(fn () => Deadline::statusLabels())
                        ->default(Deadline::STATUS_ATTIVA)
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->label('Importo pagato')
                        ->numeric()
                        ->prefix('€'),
                    Forms\Components\DatePicker::make('paid_at')->label('Data pagamento'),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Storico dei rinnovi (bollo/assicurazione con anni di righe
            // passate) fuori dallo Scadenzario per default: e' la vista
            // "cosa devo fare", non un archivio - lo storico completo resta
            // consultabile dal tab Scadenze di ogni veicolo/tenant.
            ->modifyQueryUsing(fn ($query) => $query
                ->where('status', '!=', Deadline::STATUS_RINNOVATA)
                ->with(['deadlinable' => fn ($morphTo) => $morphTo->morphWith([
                    Vehicle::class => ['assignedUser'],
                ])]))
            ->defaultSort('due_date')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Deadline::typeLabels()[$state] ?? 'Altro'),
                Tables\Columns\TextColumn::make('deadlinable')
                    ->label('Collegata a')
                    // Un automezzo assegnato a un utente e' un mezzo
                    // personale (es. Range Rover/moto di Alessandro), non
                    // aziendale (i furgoni della flotta non hanno un
                    // assegnatario) - distinzione chiesta dall'utente per
                    // capire a colpo d'occhio di cosa si tratta.
                    ->state(fn (Deadline $record) => match (true) {
                        $record->deadlinable instanceof Vehicle => $record->deadlinable->assigned_user_id
                            ? "{$record->deadlinable->plate} — personale ({$record->deadlinable->assignedUser->name})"
                            : "{$record->deadlinable->plate} — aziendale",
                        $record->deadlinable instanceof Tenant => "Azienda {$record->deadlinable->name}",
                        default => class_basename($record->deadlinable_type),
                    }),
                Tables\Columns\TextColumn::make('policy_number')->label('Numero polizza')->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')->label('Note')->limit(40)->placeholder('—')->tooltip(fn (Deadline $record) => $record->notes),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Scadenza')
                    ->date()
                    ->sortable()
                    ->color(fn (Deadline $record) => $record->dueDateColor()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Deadline::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => Deadline::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('amount')->label('Importo')->money('EUR')->placeholder('—'),
                Tables\Columns\TextColumn::make('paid_at')->label('Pagato il')->date()->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(fn () => Deadline::typeLabels()),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    // "Rinnovata" esclusa: la query di base la nasconde
                    // sempre, il filtro darebbe risultati vuoti.
                    ->options(fn () => Arr::except(Deadline::statusLabels(), Deadline::STATUS_RINNOVATA)),
                Tables\Filters\Filter::make('urgent')
                    ->label('Solo urgenti/scadute')
                    ->query(fn ($query) => $query
                        ->where('status', Deadline::STATUS_ATTIVA)
                        ->where('due_date', '<=', now()->addDays(30))),
            ])
            ->actions([
                Tables\Actions\Action::make('rinnova')
                    ->label('Rinnova')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (Deadline $record) => $record->status !== Deadline::STATUS_RINNOVATA)
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Importo pagato')
                            ->numeric()
                            ->prefix('€'),
                        Forms\Components\DatePicker::make('paid_at')
                            ->label('Data pagamento')
                            ->default(now()),
                        Forms\Components\DatePicker::make('due_date')
                            ->label('Nuova scadenza')
                            ->required(),
                    ])
                    ->modalHeading('Rinnova scadenza')
                    ->modalSubmitActionLabel('Rinnova')
                    ->action(fn (Deadline $record, array $data) => $record->renew($data)),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessuna scadenza ancora')
            ->emptyStateDescription('Aggiungi la prima scadenza con "Nuovo".')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeadlines::route('/'),
            'create' => Pages\CreateDeadline::route('/create'),
            'edit' => Pages\EditDeadline::route('/{record}/edit'),
        ];
    }
}
