<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToOwnUserUnlessResponsabile;
use App\Filament\Resources\TimeEntryResource\Pages;
use App\Models\TimeEntry;
use Filament\Forms;
use Filament\Forms\Form;
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
                    Forms\Components\Select::make('user_id')
                        ->label('Dipendente')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->disabled(fn () => ! static::isResponsabile(auth()->user()))
                        ->dehydrated()
                        ->required(),
                    Forms\Components\DateTimePicker::make('clock_in')->label('Entrata')->required(),
                    Forms\Components\DateTimePicker::make('clock_out')
                        ->label('Uscita')
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
                Tables\Columns\TextColumn::make('clock_in')->label('Entrata')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('clock_out')->label('Uscita')->dateTime('d/m/Y H:i')->placeholder('In corso')->sortable(),
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
}
