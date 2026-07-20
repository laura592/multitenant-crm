<?php

namespace App\Filament\Widgets;

use App\Models\Quote;
use Filament\Widgets\ChartWidget;

class QuotesChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Andamento preventivi accettati (ultimi 6 mesi)';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $months = collect(range(5, 0))->map(fn (int $i) => now()->subMonths($i));

        // Una sola query per i 6 mesi invece di una per ciascuno: raggruppiamo
        // per anno/mese in PHP per restare indipendenti dal dialetto SQL del DB.
        $totalsByMonth = Quote::query()
            ->where('status', 'accettato')
            ->where('date', '>=', $months->first()->copy()->startOfMonth())
            ->get(['date', 'total'])
            ->groupBy(fn (Quote $quote) => $quote->date->format('Y-m'))
            ->map(fn ($quotes) => $quotes->sum('total'));

        $values = $months->map(fn ($month) => $totalsByMonth->get($month->format('Y-m'), 0));

        return [
            'datasets' => [
                [
                    'label' => 'Valore accettati (€)',
                    'data' => $values->values(),
                ],
            ],
            'labels' => $months->map(fn ($month) => ucfirst($month->translatedFormat('M Y')))->values(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
