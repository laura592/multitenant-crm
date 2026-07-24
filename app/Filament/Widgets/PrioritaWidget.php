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

    // Meta pagina (2 colonne): affiancata a MagazzinoStatsWidget (stesso
    // sort successivo, columnSpan 1) invece di occupare una riga intera per
    // sole 2 card, che lasciava la seconda meta dello schermo vuota.
    protected int|string|array $columnSpan = 1;

    // Default Filament per 2 stat e' 3 colonne interne (lascia una terza
    // traccia vuota, visibile come buco a fianco della seconda card): qui le
    // card sono esattamente 2, quindi 2 colonne riempiono tutto lo spazio.
    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Richieste da gestire', $openRequests = InformationRequest::whereIn('status', ['nuova', 'in_lavorazione'])->count())
                ->description('Richieste informazioni aperte')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color($openRequests > 0 ? 'warning' : 'success'),
            // Stesso criterio di Deadline::isUrgent() ma calcolato in SQL invece
            // di caricare in PHP tutte le scadenze attive del tenant solo per
            // contarle (query ripetuta ad ogni apertura della dashboard).
            // Confronto su giorni di calendario (DATEDIFF su CURDATE), non
            // sull'ora esatta come now()->diffInDays(): differenza irrilevante
            // per un contatore di dashboard.
            Stat::make('Scadenze urgenti', Deadline::where('status', Deadline::STATUS_ATTIVA)
                ->whereRaw('DATEDIFF(due_date, CURDATE()) <= reminder_days_before')
                ->count())
                ->description('Entro il periodo di preavviso')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
