<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Deadline;
use App\Models\InformationRequest;
use App\Models\Quote;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatsWidget extends BaseWidget
{
    protected static ?int $sort = 0;

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
            Stat::make('Richieste da gestire', $openRequests = InformationRequest::whereIn('status', ['nuova', 'in_lavorazione'])->count())
                ->description('Richieste informazioni aperte')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color($openRequests > 0 ? 'warning' : 'success'),
            Stat::make('Scadenze urgenti', Deadline::where('status', 'attiva')->get()->filter->isUrgent()->count())
                ->description('Entro il periodo di preavviso')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
        ];
    }
}
