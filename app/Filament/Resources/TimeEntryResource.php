<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToOwnUserUnlessResponsabile;
use App\Filament\Resources\TimeEntryResource\Pages;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class TimeEntryResource extends Resource
{
    use ScopesToOwnUserUnlessResponsabile;

    protected static ?string $model = TimeEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Personale';

    protected static ?string $navigationLabel = 'Presenze';

    protected static ?string $modelLabel = 'Timbratura';

    protected static ?string $pluralModelLabel = 'Presenze';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Turno')
                ->columns(3)
                ->schema([
                    // Non persistito: serve solo a scegliere quale dei due
                    // orari standard del dipendente (mattina/pomeriggio, es.
                    // 8-12/13-17) pre-compilare in Entrata/Uscita qui sotto.
                    // Ha senso solo in creazione: in modifica il turno reale
                    // e' gia' nei valori salvati di Entrata/Uscita, e non
                    // c'e' modo di dedurlo per riselezionarlo correttamente
                    // (mostrare sempre "Mattina" di default avrebbe fatto
                    // perdere Entrata/Uscita reali appena si toccava il
                    // dipendente su un turno di pomeriggio).
                    Forms\Components\Select::make('shift_preset')
                        ->label('Turno')
                        ->options(['mattina' => 'Mattina', 'pomeriggio' => 'Pomeriggio'])
                        ->default('mattina')
                        ->dehydrated(false)
                        ->visible(fn (string $operation) => $operation === 'create')
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, ?string $state) => static::applyShiftDefaults($set, $get('user_id'), $state)),
                    Forms\Components\Select::make('user_id')
                        ->label('Dipendente')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->disabled(fn () => ! static::isResponsabile(auth()->user()))
                        ->dehydrated()
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state, string $operation) {
                            if ($operation === 'create') {
                                static::applyShiftDefaults($set, $state, $get('shift_preset'));
                            }
                        })
                        ->required(),
                    Forms\Components\DateTimePicker::make('clock_in')
                        ->label('Entrata')
                        ->default(fn (Forms\Get $get) => static::shiftDefault($get('user_id'), $get('shift_preset'), 'clock_in'))
                        ->required(),
                    Forms\Components\DateTimePicker::make('clock_out')
                        ->label('Uscita')
                        ->default(fn (Forms\Get $get) => static::shiftDefault($get('user_id'), $get('shift_preset'), 'clock_out'))
                        ->after('clock_in'),
                    Forms\Components\Select::make('source')
                        ->label('Origine')
                        ->options(['app' => 'App (tempo reale)', 'manuale' => 'Inserimento manuale'])
                        ->default('manuale')
                        ->required(),
                ]),
            Forms\Components\Section::make('Stato')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(static::statusLabels())
                        ->default('chiusa')
                        ->required(),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('clock_in', 'desc')
            // Vista per mese: la lista piatta era scomoda da scorrere con
            // storico lungo. Raggruppare per mese (con intestazione
            // pieghevole) rende visibile a colpo d'occhio "quante timbrature
            // in questo mese" senza dover ogni volta impostare il filtro
            // Periodo a mano.
            ->groups([
                Tables\Grouping\Group::make('clock_in')
                    ->label('Mese')
                    ->getKeyFromRecordUsing(fn (TimeEntry $record) => $record->clock_in->format('Y-m'))
                    ->getTitleFromRecordUsing(fn (TimeEntry $record) => $record->clock_in->translatedFormat('F Y')),
            ])
            ->defaultGroup('clock_in')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Dipendente')->searchable()->sortable(),
                // Data in formato esteso ("lun 26 luglio 2026") in una
                // colonna a parte: prima la data era ripetuta per intero sia
                // in Entrata sia in Uscita, che invece mostrano solo l'ora.
                Tables\Columns\TextColumn::make('clock_in')
                    ->label('Giorno')
                    ->formatStateUsing(fn (\Illuminate\Support\Carbon $state) => $state->translatedFormat('D d F Y'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('clock_in')->label('Entrata')->dateTime('H:i')->sortable(),
                Tables\Columns\TextColumn::make('clock_out')->label('Uscita')->dateTime('H:i')->placeholder('In corso')->sortable(),
                Tables\Columns\TextColumn::make('worked_hours')->label('Ore')->state(fn (TimeEntry $record) => $record->worked_hours)->placeholder('—'),
                Tables\Columns\TextColumn::make('source')
                    ->label('Origine')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'app' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'app' => 'App (tempo reale)',
                        'manuale' => 'Manuale',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
                Tables\Filters\SelectFilter::make('source')
                    ->label('Origine')
                    ->options(['app' => 'App (tempo reale)', 'manuale' => 'Inserimento manuale']),
                Tables\Filters\Filter::make('clock_in')
                    ->label('Periodo')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dal'),
                        Forms\Components\DatePicker::make('until')->label('Al'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('clock_in', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('clock_in', '<=', $date));
                    }),
            ])
            ->headerActions([
                // Crea in un click le timbrature di oggi (mattina+pomeriggio)
                // in base all'orario standard configurato sul profilo
                // dell'utente loggato: prima bisognava sempre passare dal
                // form "Nuovo", una volta per turno.
                Tables\Actions\Action::make('quickToday')
                    ->label('Turno standard di oggi')
                    ->icon('heroicon-o-bolt')
                    ->color('success')
                    ->visible(fn () => (bool) auth()->user()?->hasStandardSchedule())
                    ->requiresConfirmation()
                    ->modalDescription('Crea le timbrature di oggi in base al tuo orario standard configurato nel profilo (Utenti > Orario standard).')
                    ->action(function () {
                        $user = auth()->user();
                        $today = today();

                        $alreadyLogged = TimeEntry::query()
                            ->where('user_id', $user->id)
                            ->whereDate('clock_in', $today)
                            ->exists();

                        if ($alreadyLogged) {
                            Notification::make()
                                ->title('Ci sono gia\' timbrature per oggi')
                                ->body('Controlla la lista prima di aggiungerne altre, per evitare doppioni.')
                                ->warning()
                                ->send();

                            return;
                        }

                        foreach ($user->standardShifts($today) as $shift) {
                            TimeEntry::create([
                                'user_id' => $user->id,
                                'clock_in' => $shift['clock_in'],
                                'clock_out' => $shift['clock_out'],
                                'source' => 'manuale',
                                'status' => 'chiusa',
                            ]);
                        }

                        Notification::make()
                            ->title('Turni di oggi creati')
                            ->success()
                            ->send();
                    }),
                // Per chi e' rimasto indietro con piu' giorni: "Turno
                // standard di oggi" copre solo oggi, qui si sceglie un
                // intervallo di date e si salta ogni giorno gia' registrato
                // (per non creare doppioni su giorni parzialmente inseriti
                // a mano).
                Tables\Actions\Action::make('quickBackfill')
                    ->label('Recupera turni passati')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->visible(fn () => (bool) auth()->user()?->hasStandardSchedule())
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dal')->required()->maxDate(today())->live(),
                        Forms\Components\DatePicker::make('until')->label('Al')->required()->maxDate(today())
                            ->minDate(fn (Forms\Get $get) => $get('from')),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Crea le timbrature mancanti in base al tuo orario standard per i giorni scelti (sabato e domenica esclusi). I giorni che hanno gia\' almeno una timbratura vengono saltati.')
                    ->action(function (array $data) {
                        $user = auth()->user();
                        $from = Carbon::parse($data['from'])->startOfDay();
                        $until = Carbon::parse($data['until'])->startOfDay();

                        if ($from->gt($until)) {
                            [$from, $until] = [$until, $from];
                        }

                        $created = 0;

                        for ($date = $from->copy(); $date->lte($until); $date->addDay()) {
                            if ($date->isWeekend()) {
                                continue;
                            }

                            $alreadyLogged = TimeEntry::query()
                                ->where('user_id', $user->id)
                                ->whereDate('clock_in', $date)
                                ->exists();

                            if ($alreadyLogged) {
                                continue;
                            }

                            foreach ($user->standardShifts($date) as $shift) {
                                TimeEntry::create([
                                    'user_id' => $user->id,
                                    'clock_in' => $shift['clock_in'],
                                    'clock_out' => $shift['clock_out'],
                                    'source' => 'manuale',
                                    'status' => 'chiusa',
                                ]);
                                $created++;
                            }
                        }

                        Notification::make()
                            ->title($created > 0 ? "Create {$created} timbrature" : 'Nessuna timbratura creata')
                            ->body($created > 0 ? null : 'Per i giorni scelti risultano gia\' timbrature registrate.')
                            ->success()
                            ->send();
                    }),
                // Export dei dati grezzi (non l'aggregato di RiepilogoOre):
                // prima non esisteva alcun export per le timbrature stesse.
                ExportAction::make()
                    ->label('Esporta')
                    ->exports([
                        ExcelExport::make('presenze')->fromTable(),
                    ]),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTimeEntries::route('/'),
            'create' => Pages\CreateTimeEntry::route('/create'),
            'edit' => Pages\EditTimeEntry::route('/{record}/edit'),
        ];
    }

    /**
     * Il modello non ha costanti per lo stato: le etichette/colori restano
     * centralizzati qui per non duplicarli tra form, tabella e filtro.
     */
    public static function statusLabels(): array
    {
        return ['aperta' => 'Aperta', 'chiusa' => 'Chiusa', 'corretta' => 'Corretta'];
    }

    public static function statusColors(): array
    {
        return ['aperta' => 'warning', 'chiusa' => 'success', 'corretta' => 'info'];
    }

    /**
     * Orario (Entrata/Uscita) dell'orario standard di oggi per il turno
     * scelto, per il dipendente selezionato nel form. Null se il dipendente
     * non ha quel turno configurato: il campo resta da compilare a mano
     * come prima.
     */
    protected static function shiftDefault(?string $userId, ?string $shift, string $field): ?string
    {
        if (! $userId || ! $shift) {
            return null;
        }

        $match = collect(User::find($userId)?->standardShifts() ?? [])->firstWhere('shift', $shift);

        if (! $match) {
            return null;
        }

        return $match[$field]?->format('Y-m-d H:i:s');
    }

    protected static function applyShiftDefaults(Forms\Set $set, ?string $userId, ?string $shift): void
    {
        $set('clock_in', static::shiftDefault($userId, $shift, 'clock_in'));
        $set('clock_out', static::shiftDefault($userId, $shift, 'clock_out'));
    }
}
