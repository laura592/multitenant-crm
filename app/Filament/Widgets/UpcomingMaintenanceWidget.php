<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MaintenanceScheduleResource;
use App\Models\MaintenanceSchedule;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class UpcomingMaintenanceWidget extends BaseWidget
{
    // Stesso sort/columnSpan di UpcomingDeadlinesWidget e LatestQuotesWidget:
    // le tre tabelle (colSpan 1) finiscono affiancate sulla dashboard.
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return Auth::user()->can('view_any_maintenance::schedule');
    }

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Piani in scadenza';

    public function table(Table $table): Table
    {
        return $table
            // Nessun limite superiore sulla data: i piani gia' scaduti
            // (next_due_date passata) devono comparire per primi, non sparire
            // dalla vista solo perche' sono oltre i "prossimi 30 giorni".
            ->query(MaintenanceSchedule::query()
                ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
                ->whereNotNull('next_due_date')
                ->orderBy('next_due_date')
                ->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => MaintenanceScheduleResource::typeLabels()[$state] ?? 'Manutenzione')
                    ->color(fn (string $state) => MaintenanceScheduleResource::typeColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('next_due_date')
                    ->label('Scadenza')
                    ->date()
                    ->color(fn (MaintenanceSchedule $record) => $record->next_due_date?->isPast() ? 'danger' : null),
            ])
            ->recordUrl(fn (MaintenanceSchedule $record) => MaintenanceScheduleResource::getUrl('view', ['record' => $record], tenant: $record->tenant))
            ->paginated(false);
    }
}
