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
                        ->options(['ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia'])
                        ->live()
                        ->required(),
                    // Solo il permesso orario ha bisogno delle ore: per ferie/malattia
                    // il campo restava visibile ma inutile, con rischio di lasciarlo
                    // valorizzato per errore da una richiesta precedente.
                    Forms\Components\TextInput::make('hours')
                        ->label('Ore')
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Forms\Get $get) => $get('type') === 'permesso')
                        ->required(fn (Forms\Get $get) => $get('type') === 'permesso'),
                    Forms\Components\DatePicker::make('date_from')->label('Dal')->required(),
                    Forms\Components\DatePicker::make('date_to')->label('Al')->required()->afterOrEqual('date_from'),
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
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'ferie' => 'Ferie',
                        'permesso' => 'Permesso',
                        'malattia' => 'Malattia',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'ferie' => 'info',
                        'permesso' => 'warning',
                        'malattia' => 'danger',
                        default => 'gray',
                    }),
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
                    ->color(fn (string $state) => match ($state) {
                        'approvato' => 'success',
                        'rifiutato' => 'danger',
                        default => 'warning',
                    }),
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
                    ->options(['ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia']),
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
                            Mail::to($record->user->email)
                                ->cc($record->tenant?->notificationRecipients('leave_request') ?? [])
                                ->send(new LeaveRequestDecisionMail($record));
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
                            Mail::to($record->user->email)
                                ->cc($record->tenant?->notificationRecipients('leave_request') ?? [])
                                ->send(new LeaveRequestDecisionMail($record));
                        }
                    }),
                // Una volta decisa (approvato/rifiutato) solo un responsabile
                // puo' ancora modificarla/cancellarla, e farlo la riporta a
                // "richiesto" per una nuova approvazione: vedi
                // LeaveRequestPolicy::updateAfterDecision().
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
            'edit' => Pages\EditLeaveRequest::route('/{record}/edit'),
        ];
    }

    protected static function decisionNotificationBody(LeaveRequest $record): string
    {
        $period = $record->date_from->isSameDay($record->date_to)
            ? $record->date_from->format('d/m/Y')
            : "{$record->date_from->format('d/m/Y')} - {$record->date_to->format('d/m/Y')}";

        $type = match ($record->type) {
            'ferie' => 'Ferie',
            'permesso' => 'Permesso',
            'malattia' => 'Malattia',
            default => $record->type,
        };

        return "{$type}: {$period}";
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
}
