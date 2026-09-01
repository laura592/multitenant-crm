<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeadlineResource;
use App\Filament\Resources\InformationRequestResource;
use App\Models\Deadline;
use App\Models\InformationRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
            $openRequests = InformationRequest::whereIn('status', ['nuova', 'in_lavorazione'])->count();

            $stats[] = Stat::make('Richieste da gestire', $openRequests)
                ->description($openRequests > 0 ? 'Richieste informazioni aperte' : 'Nessuna richiesta in attesa')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color($openRequests > 0 ? 'warning' : 'success')
                ->url(InformationRequestResource::getUrl('index'));
        }

        if ($user->can('view_any_deadline')) {
            // Criterio in Deadline::scopeUrgent(), condiviso con il filtro
            // "Solo urgenti/scadute" di DeadlineResource su cui punta il link
            // di questa card.
            $count = Deadline::urgent()->count();
            // Il solo conteggio ("4") non dice cosa fare: la card riporta anche
            // qual e' la scadenza piu' vicina e fra quanto scade, ed e'
            // cliccabile verso l'elenco gia' filtrato sul filtro "urgent" di
            // DeadlineResource. Il colore segue il numero invece di essere
            // rosso fisso: con zero scadenze la card era rossa comunque.
            $nearest = $count > 0
                ? Deadline::urgent()->with('deadlinable')->orderBy('due_date')->first()
                : null;

            $stats[] = Stat::make('Scadenze urgenti', $count)
                ->description($nearest
                    ? 'Prima: '.Str::limit($nearest->relatedLabel(), 34).' — '.self::whenLabel($nearest)
                    : 'Nessuna scadenza entro il preavviso')
                ->icon('heroicon-o-exclamation-triangle')
                ->color(match (true) {
                    $count === 0 => 'success',
                    $nearest->due_date->isPast() => 'danger',
                    default => 'warning',
                })
                ->url(DeadlineResource::getUrl('index', ['tableFilters' => ['urgent' => ['isActive' => true]]]));
        }

        return $stats;
    }

    /**
     * "scaduta da 3 giorni" / "scade oggi" / "fra 5 giorni": il conteggio in
     * giorni di calendario, coerente con il DATEDIFF usato per selezionarle.
     */
    public static function whenLabel(Deadline $deadline): string
    {
        $days = (int) today()->diffInDays($deadline->due_date->startOfDay(), false);

        return match (true) {
            $days < 0 => 'scaduta da '.abs($days).(abs($days) === 1 ? ' giorno' : ' giorni'),
            $days === 0 => 'scade oggi',
            $days === 1 => 'scade domani',
            default => 'fra '.$days.' giorni',
        };
    }
}
