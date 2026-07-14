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

/**
 * Scadenzario unificato (docs/architecture.md §13.2): assicurazioni/revisioni
 * automezzi, polizze RCT e rinnovi contratto tenant, prossime manutenzioni
 * (queste ultime sincronizzate automaticamente da MaintenanceSchedule, non
 * create qui manualmente). Tabella nativa ordinata per urgenza, nessun
 * plugin esterno.
 */
class DeadlineResource extends Resource
{
    protected static ?string $model = Deadline::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Gestione';

    protected static ?string $navigationLabel = 'Scadenzario';

    protected static ?string $modelLabel = 'Scadenza';

    protected static ?string $pluralModelLabel = 'Scadenzario';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\MorphToSelect::make('deadlinable')
                ->label('Collegata a')
                ->types([
                    Forms\Components\MorphToSelect\Type::make(Vehicle::class)->titleAttribute('plate'),
                    Forms\Components\MorphToSelect\Type::make(Tenant::class)->titleAttribute('name'),
                ])
                ->required(),
            Forms\Components\Select::make('type')
                ->label('Tipo')
                ->options([
                    Deadline::TYPE_ASSICURAZIONE => 'Assicurazione',
                    Deadline::TYPE_REVISIONE => 'Revisione',
                    Deadline::TYPE_POLIZZA_RCT => 'Polizza RCT',
                    Deadline::TYPE_LICENZA => 'Licenza',
                    Deadline::TYPE_CONTRATTO => 'Contratto',
                    Deadline::TYPE_ALTRO => 'Altro',
                ])
                ->required(),
            Forms\Components\DatePicker::make('due_date')->label('Scadenza')->required(),
            Forms\Components\TextInput::make('reminder_days_before')
                ->label('Preavviso (giorni)')
                ->numeric()
                ->default(30),
            Forms\Components\Select::make('status')
                ->label('Stato')
                ->options(['attiva' => 'Attiva', 'scaduta' => 'Scaduta', 'rinnovata' => 'Rinnovata'])
                ->default('attiva')
                ->required(),
            Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_date')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Deadline::TYPE_ASSICURAZIONE => 'Assicurazione',
                        Deadline::TYPE_REVISIONE => 'Revisione',
                        Deadline::TYPE_POLIZZA_RCT => 'Polizza RCT',
                        Deadline::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione',
                        Deadline::TYPE_LICENZA => 'Licenza',
                        Deadline::TYPE_CONTRATTO => 'Contratto',
                        default => 'Altro',
                    }),
                Tables\Columns\TextColumn::make('deadlinable')
                    ->label('Collegata a')
                    ->state(fn (Deadline $record) => match (true) {
                        $record->deadlinable instanceof Vehicle => "Automezzo {$record->deadlinable->plate}",
                        $record->deadlinable instanceof Tenant => "Azienda {$record->deadlinable->name}",
                        default => class_basename($record->deadlinable_type),
                    }),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Scadenza')
                    ->date()
                    ->sortable()
                    ->color(fn (Deadline $record) => match (true) {
                        $record->due_date->isPast() => 'danger',
                        $record->isUrgent() => 'warning',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        Deadline::TYPE_ASSICURAZIONE => 'Assicurazione',
                        Deadline::TYPE_REVISIONE => 'Revisione',
                        Deadline::TYPE_POLIZZA_RCT => 'Polizza RCT',
                        Deadline::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione',
                        Deadline::TYPE_LICENZA => 'Licenza',
                        Deadline::TYPE_CONTRATTO => 'Contratto',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(['attiva' => 'Attiva', 'scaduta' => 'Scaduta', 'rinnovata' => 'Rinnovata']),
                Tables\Filters\Filter::make('urgent')
                    ->label('Solo urgenti/scadute')
                    ->query(fn ($query) => $query->where('due_date', '<=', now()->addDays(30))),
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
            'index' => Pages\ListDeadlines::route('/'),
            'create' => Pages\CreateDeadline::route('/create'),
            'edit' => Pages\EditDeadline::route('/{record}/edit'),
        ];
    }
}
