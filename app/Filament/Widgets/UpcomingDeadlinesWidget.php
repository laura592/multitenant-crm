<?php

namespace App\Filament\Widgets;

use App\Models\Deadline;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class UpcomingDeadlinesWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return Auth::user()->can('view_any_deadline');
    }

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Prossime scadenze';

    public function table(Table $table): Table
    {
        return $table
            ->query(Deadline::query()
                ->where('status', Deadline::STATUS_ATTIVA)
                ->where('due_date', '>=', now()->startOfDay())
                ->orderBy('due_date')
                ->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Deadline::typeLabels()[$state] ?? 'Altro'),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Scadenza')
                    ->date()
                    ->color(fn (Deadline $record) => $record->dueDateColor()),
            ])
            ->paginated(false);
    }
}
