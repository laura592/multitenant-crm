<?php

namespace App\Filament\Widgets;

use App\Models\Deadline;
use App\Models\InformationRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cio' che richiede un'azione oggi, separato dai numeri di andamento
 * (DashboardStatsWidget) e dall'area acquisti (MagazzinoStatsWidget):
 * subito dopo la Timbratura, prima di tutto il resto.
 */
class PrioritaWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        return [
            Stat::make('Richieste da gestire', $openRequests = InformationRequest::whereIn('status', ['nuova', 'in_lavorazione'])->count())
                ->description('Richieste informazioni aperte')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color($openRequests > 0 ? 'warning' : 'success'),
            Stat::make('Scadenze urgenti', Deadline::where('status', Deadline::STATUS_ATTIVA)->get()->filter->isUrgent()->count())
                ->description('Entro il periodo di preavviso')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
