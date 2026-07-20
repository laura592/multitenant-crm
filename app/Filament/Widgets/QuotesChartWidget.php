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

        $values = $months->map(fn ($month) => Quote::whereMonth('date', $month->month)
            ->whereYear('date', $month->year)
            ->where('status', 'accettato')
            ->sum('total'));

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
