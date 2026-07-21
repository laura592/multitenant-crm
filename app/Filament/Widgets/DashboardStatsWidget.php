<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Quote;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Solo andamento commerciale: cio' che richiede azione (richieste aperte,
 * scadenze urgenti) e' in PrioritaWidget, ordinato prima di questo.
 */
class DashboardStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $monthlyQuotes = Quote::whereMonth('date', now()->month)->whereYear('date', now()->year);
        $acceptedValue = (clone $monthlyQuotes)->where('status', 'accettato')->sum('total');

        return [
            Stat::make('Preventivi questo mese', (clone $monthlyQuotes)->count())
                ->description('Creati nel mese corrente')
                ->icon('heroicon-o-document-text')
                ->color('primary'),
            Stat::make('Valore accettati questo mese', number_format((float) $acceptedValue, 2, ',', '.').' €')
                ->description('Preventivi accettati')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Clienti', Customer::count())
                ->description('Totale clienti')
                ->icon('heroicon-o-users')
                ->color('gray'),
        ];
    }
}
