<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToOwnUserUnlessResponsabile;
use App\Filament\Resources\LeaveRequestResource\Pages;
use App\Mail\LeaveRequestDecisionMail;
use App\Models\LeaveRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class LeaveRequestResource extends Resource
{
    use ScopesToOwnUserUnlessResponsabile;

    protected static ?string $model = LeaveRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Personale';

    protected static ?string $navigationLabel = 'Ferie e permessi';

    protected static ?string $modelLabel = 'Richiesta ferie/permesso';

    protected static ?string $pluralModelLabel = 'Ferie e permessi';

    // Senza questo, Filament forza il Title Case sui titoli pagina e
    // capitalizza anche la "e" ("Ferie E Permessi").
    protected static bool $hasTitleCaseModelLabel = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Richiesta')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Dipendente')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        // "amministrazione" deve poter inserire una malattia (o
                        // qualunque altra assenza) per conto di un dipendente il
                        // giorno stesso, non solo il dipendente per se' o un
                        // responsabile: vedi RolePermissions, ha gia' create/
                        // update su leave::request, qui si sblocca solo la scelta
                        // di UN ALTRO dipendente nel menu a tendina.
                        ->disabled(fn () => ! static::isResponsabile(auth()->user()) && ! auth()->user()?->hasRole('amministrazione'))
                        ->dehydrated()
                        ->live()
                        ->required()
                        ->helperText(function (Forms\Get $get) {
                            $userId = $get('user_id') ?? auth()->id();
                            $user = $userId ? \App\Models\User::find($userId) : null;
                            $remaining = $user?->remainingFerieDays();

                            return $remaining === null ? null : "Residuo ferie anno corrente: {$remaining} giorni";
                        }),
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(static::typeLabels())
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::syncPermessoDateTo($get, $set))
                        ->required(),
                    // La malattia si segnala spesso a posteriori (il giorno dopo
                    // l'assenza): il vincolo "non nel passato" vale solo per
                    // ferie/permesso, che invece sono richieste pianificate.
                    Forms\Components\DatePicker::make('date_from')
                        ->label(fn (Forms\Get $get) => $get('type') === 'permesso' ? 'Giorno' : 'Dal')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::syncPermessoDateTo($get, $set))
                        ->minDate(fn (Forms\Get $get) => $get('type') === 'malattia' ? null : now()),
                    // Il permesso e' sempre in un solo giorno: il campo "Al" non ha
                    // senso in quel caso, si mostrano invece gli orari.
                    Forms\Components\DatePicker::make('date_to')
                        ->label('Al')
                        ->required()
                        ->afterOrEqual('date_from')
                        ->visible(fn (Forms\Get $get) => $get('type') !== 'permesso'),
                    // Le ore del permesso si ricavano da Dalle/Alle (vedi
                    // normalizePermessoData()): niente campo "Ore" separato da
                    // tenere allineato a mano.
                    Forms\Components\TimePicker::make('time_from')
                        ->label('Dalle')
                        ->seconds(false)
                        ->visible(fn (Forms\Get $get) => $get('type') === 'permesso')
                        ->required(fn (Forms\Get $get) => $get('type') === 'permesso'),
                    Forms\Components\TimePicker::make('time_to')
                        ->label('Alle')
                        ->seconds(false)
                        ->after('time_from')
                        ->visible(fn (Forms\Get $get) => $get('type') === 'permesso')
                        ->required(fn (Forms\Get $get) => $get('type') === 'permesso'),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Dipendente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::typeLabels()[$state] ?? $state)
                    ->color(fn (string $state) => static::typeColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('date_from')->label('Dal')->date()->sortable(),
                Tables\Columns\TextColumn::make('date_to')->label('Al')->date()->sortable(),
                // Il "permesso" e' orario: mostrare "1 giorno" (getDaysAttribute
                // conta sempre almeno un giorno, dal=al) nascondeva del tutto le
                // ore richieste, il dato che conta davvero per questo tipo.
                Tables\Columns\TextColumn::make('days')
                    ->label('Giorni/Ore')
                    ->state(fn (LeaveRequest $record) => $record->type === 'permesso'
                        ? number_format((float) $record->hours, 2).' h'
                        : $record->days.' gg'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'warning'),
            ])
            ->headerActions([
                // Prima esisteva solo l'aggregato di RiepilogoOre: nessun export
                // delle singole richieste ferie/permesso/malattia.
                ExportAction::make()
                    ->label('Esporta')
                    ->exports([
                        ExcelExport::make('ferie-permessi')->fromTable(),
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(static::typeLabels()),
                Tables\Filters\Filter::make('date_from')
                    ->label('Periodo')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dal'),
                        Forms\Components\DatePicker::make('until')->label('Al'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date_to', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date_from', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approva')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    // Nascosto solo se GIA' approvata (approvare di nuovo sarebbe
                    // un no-op): da "rifiutato" resta visibile, cosi' un
                    // responsabile puo' ribaltare la decisione. Lo stato va
                    // ricontrollato qui (non solo nella policy) perche'
                    // Gate::before in AppServiceProvider fa bypassare ogni
                    // policy allo staff master (is_super_admin).
                    ->visible(fn (LeaveRequest $record) => $record->status !== 'approvato' && auth()->user()?->can('approve', $record))
                    ->requiresConfirmation()
                    ->action(function (LeaveRequest $record) {
                        $record->approve(auth()->user());
                        Notification::make()->title('Richiesta approvata')->success()->send();
                        Notification::make()
                            ->title('Richiesta ferie/permesso approvata')
                            ->body(static::decisionNotificationBody($record))
                            ->success()
                            ->sendToDatabase($record->user);

                        if ($record->user?->email) {
                            static::sendDecisionMail($record);
                        }
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Rifiuta')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    // Simmetrico ad "approve": nascosto solo se GIA' rifiutata,
                    // resta visibile da "approvato" per poter ribaltare.
                    ->visible(fn (LeaveRequest $record) => $record->status !== 'rifiutato' && auth()->user()?->can('approve', $record))
                    ->requiresConfirmation()
                    ->action(function (LeaveRequest $record) {
                        $record->reject(auth()->user());
                        Notification::make()->title('Richiesta rifiutata')->danger()->send();
                        Notification::make()
                            ->title('Richiesta ferie/permesso rifiutata')
                            ->body(static::decisionNotificationBody($record))
                            ->danger()
                            ->sendToDatabase($record->user);

                        if ($record->user?->email) {
                            static::sendDecisionMail($record);
                        }
                    }),
                // Una volta decisa (approvato/rifiutato) solo un responsabile
                // puo' ancora modificarla/cancellarla, e farlo la riporta a
                // "richiesto" per una nuova approvazione: vedi
                // LeaveRequestPolicy::updateAfterDecision().
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->visible(fn (LeaveRequest $record) => auth()->user()?->can('updateAfterDecision', $record))
                        ->mutateFormDataUsing(function (array $data, LeaveRequest $record): array {
                            if ($record->status !== 'richiesto') {
                                $data['status'] = 'richiesto';
                                $data['approved_by_user_id'] = null;
                                $data['approved_at'] = null;
                            }

                            return $data;
                        }),
                    Tables\Actions\DeleteAction::make()
                        ->visible(fn (LeaveRequest $record) => auth()->user()?->can('updateAfterDecision', $record)),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessuna richiesta ancora')
            ->emptyStateDescription('Crea la prima richiesta di ferie o permesso con "Nuovo".')
            ->emptyStateIcon('heroicon-o-calendar');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeaveRequests::route('/'),
            'create' => Pages\CreateLeaveRequest::route('/create'),
            'view' => Pages\ViewLeaveRequest::route('/{record}'),
            'edit' => Pages\EditLeaveRequest::route('/{record}/edit'),
        ];
    }

    /**
     * L'approvazione/rifiuto e' gia' salvata quando questa mail parte: un
     * problema SMTP non deve far tornare un 500 al responsabile e fargli
     * credere che l'azione non sia andata a buon fine (vedi anche
     * CreateLeaveRequest::afterCreate() per lo stesso problema in creazione).
     */
    public static function sendDecisionMail(LeaveRequest $record): void
    {
        try {
            Mail::to($record->user->email)
                ->cc($record->tenant?->notificationRecipients('leave_request') ?? [])
                ->send(new LeaveRequestDecisionMail($record));
        } catch (\Throwable $e) {
            Log::error('Invio notifica decisione ferie/permesso fallito', [
                'leave_request_id' => $record->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public static function decisionNotificationBody(LeaveRequest $record): string
    {
        $type = static::typeLabels()[$record->type] ?? $record->type;

        return "{$type}: {$record->periodLabel()}";
    }

    /**
     * Il permesso non ha un campo "Ore" separato: le ore si ricavano sempre
     * da Dalle/Alle qui, lato server, cosi' non possono disallinearsi
     * dall'orario effettivo (e "Al", nascosto lato form per il permesso, non
     * puo' arrivare da un submit forzato con un giorno diverso da "Dal").
     */
    public static function normalizePermessoData(array $data): array
    {
        if (($data['type'] ?? null) !== 'permesso') {
            return $data;
        }

        $data['date_to'] = $data['date_from'] ?? null;
        $data['hours'] = null;

        if (! empty($data['time_from']) && ! empty($data['time_to'])) {
            $minutes = \Carbon\Carbon::parse($data['time_from'])->diffInMinutes(\Carbon\Carbon::parse($data['time_to']), false);

            if ($minutes > 0) {
                $data['hours'] = round($minutes / 60, 2);
            }
        }

        return $data;
    }

    /**
     * Il permesso e' sempre un solo giorno: tiene "Al" allineato a "Dal" ogni
     * volta che cambiano tipo o giorno, cosi' il campo nascosto non resta
     * disallineato quando l'utente passa da un altro tipo a "permesso" (o
     * torna indietro). La normalizzazione definitiva resta comunque server-
     * side in normalizePermessoData(), questo e' solo per l'anteprima nel form.
     */
    public static function syncPermessoDateTo(Forms\Get $get, Forms\Set $set): void
    {
        if ($get('type') !== 'permesso') {
            return;
        }

        $set('date_to', $get('date_from'));
    }

    /**
     * La colonna Stato mostrava il valore grezzo del DB ("richiesto") invece
     * di un'etichetta leggibile, a differenza delle altre risorse a stato
     * (vedi QuoteResource::statusLabels()).
     */
    public static function statusLabels(): array
    {
        return [
            'richiesto' => 'Richiesto',
            'approvato' => 'Approvato',
            'rifiutato' => 'Rifiutato',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'richiesto' => 'warning',
            'approvato' => 'success',
            'rifiutato' => 'danger',
        ];
    }

    public static function typeLabels(): array
    {
        return [
            'ferie' => 'Ferie',
            'permesso' => 'Permesso',
            'malattia' => 'Malattia',
        ];
    }

    public static function typeColors(): array
    {
        return [
            'ferie' => 'info',
            'permesso' => 'warning',
            'malattia' => 'danger',
        ];
    }
}
