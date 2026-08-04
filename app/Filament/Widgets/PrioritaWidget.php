<?php

namespace App\Filament\Widgets;

use App\Models\Deadline;
use App\Models\InformationRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

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
    // card sono esattamente 2 (o 1, se il ruolo non vede una delle due
    // risorse, vedi getStats()), quindi il numero di colonne segue il
    // numero di card effettivamente mostrate.
    protected function getColumns(): int
    {
        return max(count($this->getStats()), 1);
    }

    // Ognuna delle due card copre una risorsa diversa (richieste
    // informazioni, scadenze): niente widget_PrioritaWidget dedicato, il
    // widget resta visibile finche' il ruolo vede almeno una delle due,
    // mostrando solo la card per cui ha il permesso (partner non vede
    // scadenze, amministrazione non vede richieste informazioni).
    public static function canView(): bool
    {
        return Auth::user()->can('view_any_information::request') || Auth::user()->can('view_any_deadline');
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $stats = [];

        if ($user->can('view_any_information::request')) {
            $stats[] = Stat::make('Richieste da gestire', $openRequests = InformationRequest::whereIn('status', ['nuova', 'in_lavorazione'])->count())
                ->description('Richieste informazioni aperte')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color($openRequests > 0 ? 'warning' : 'success');
        }

        if ($user->can('view_any_deadline')) {
            // Stesso criterio di Deadline::isUrgent() ma calcolato in SQL invece
            // di caricare in PHP tutte le scadenze attive del tenant solo per
            // contarle (query ripetuta ad ogni apertura della dashboard).
            // Confronto su giorni di calendario (DATEDIFF su CURDATE), non
            // sull'ora esatta come now()->diffInDays(): differenza irrilevante
            // per un contatore di dashboard.
            $stats[] = Stat::make('Scadenze urgenti', Deadline::where('status', Deadline::STATUS_ATTIVA)
                ->whereRaw('DATEDIFF(due_date, CURDATE()) <= reminder_days_before')
                ->count())
                ->description('Entro il periodo di preavviso')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger');
        }

        return $stats;
    }
}
