<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\QuoteResource;
use App\Models\Customer;
use App\Models\Quote;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Solo andamento commerciale: cio' che richiede azione (richieste aperte,
 * scadenze urgenti) e' in PrioritaWidget, ordinato prima di questo.
 */
class DashboardStatsWidget extends BaseWidget
{
    // Apre il contenuto della sezione "Andamento commerciale" (sort 4).
    protected static ?int $sort = 5;

    // I numeri qui sono tutti preventivi (dipendente/amministrazione non
    // hanno accesso ai preventivi, vedi RolePermissions): senza questo
    // controllo li vedrebbero comunque riassunti in dashboard.
    public static function canView(): bool
    {
        return Auth::user()->can('view_any_quote');
    }

    protected function getStats(): array
    {
        $monthlyQuotes = Quote::whereMonth('date', now()->month)->whereYear('date', now()->year);
        $acceptedValue = (clone $monthlyQuotes)->where('status', 'accettato')->sum('total');

        // Ogni card porta all'elenco corrispondente: un numero da solo non
        // dice cosa farci, e finora l'unico modo di vedere "quali" era
        // ripartire dal menu laterale.
        return [
            Stat::make('Preventivi questo mese', (clone $monthlyQuotes)->count())
                ->description('Creati nel mese corrente')
                ->icon('heroicon-o-document-text')
                ->color('primary')
                ->url(QuoteResource::getUrl('index')),
            Stat::make('Valore accettati questo mese', number_format((float) $acceptedValue, 2, ',', '.').' €')
                ->description('Preventivi accettati')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->url(QuoteResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'accettato']]])),
            Stat::make('Clienti', Customer::count())
                ->description('Totale clienti')
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(CustomerResource::getUrl('index')),
        ];
    }
}
