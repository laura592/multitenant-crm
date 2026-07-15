<?php

namespace App\Filament\Widgets;

use App\Models\Deadline;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class UpcomingDeadlinesWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Prossime scadenze';

    public function table(Table $table): Table
    {
        return $table
            ->query(Deadline::query()->where('status', 'attiva')->orderBy('due_date')->limit(5))
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
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Scadenza')
                    ->date()
                    ->color(fn (Deadline $record) => $record->due_date->isPast() ? 'danger' : ($record->isUrgent() ? 'warning' : 'success')),
                Tables\Columns\TextColumn::make('notes')->label('Note')->limit(30)->placeholder('—'),
            ])
            ->paginated(false);
    }
}
