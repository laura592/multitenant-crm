<?php

namespace App\Filament\Concerns;

use App\Models\Deadline;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
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
                Tables\Columns\TextColumn::make('due_date')->label('Scadenza')->date()
                    ->color(fn (Deadline $record) => $record->isUrgent() ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('status')->label('Stato')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
