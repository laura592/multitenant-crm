<?php

namespace App\Filament\Concerns;

use App\Models\Deadline;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

/**
 * Schema condiviso per la gestione delle scadenze (assicurazione, revisione,
 * polizza RCT, contratto, licenza) da un qualsiasi "deadlinable" (Vehicle,
 * Tenant, ...). Vedi docs/architecture.md §13.
 */
trait HasDeadlinesTable
{
    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Scadenza')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(fn () => Arr::except(Deadline::typeLabels(), static::excludedDeadlineTypes()))
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

    /**
     * Tipi non selezionabili manualmente da questo relation manager (es.
     * generati automaticamente altrove). Override nelle classi che lo usano.
     */
    protected static function excludedDeadlineTypes(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->defaultSort('due_date')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Deadline::typeLabels()[$state] ?? 'Altro'),
                Tables\Columns\TextColumn::make('policy_number')->label('Numero polizza')->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')->label('Note')->limit(40)->placeholder('—')->tooltip(fn (Deadline $record) => $record->notes),
                Tables\Columns\TextColumn::make('due_date')->label('Scadenza')->date()
                    ->color(fn (Deadline $record) => $record->dueDateColor()),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Deadline::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => Deadline::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('amount')->label('Importo')->money('EUR')->placeholder('—'),
                Tables\Columns\TextColumn::make('paid_at')->label('Pagato il')->date()->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
            ]);
    }
}
