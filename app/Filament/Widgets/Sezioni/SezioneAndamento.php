<?php

namespace App\Filament\Widgets\Sezioni;

use App\Filament\Widgets\DashboardStatsWidget;
use App\Filament\Widgets\LatestQuotesWidget;

/**
 * Numeri di andamento: si guardano, non si lavorano. Separati da "Da fare
 * adesso" apposta - mescolati, un preventivo accettato e una scadenza fra
 * tre giorni leggevano allo stesso modo.
 */
class SezioneAndamento extends SezioneWidget
{
    protected static ?int $sort = 4;

    public static function titolo(): string
    {
        return 'Andamento commerciale';
    }

    public static function sottotitolo(): string
    {
        return 'Come sta andando il mese, preventivi e clienti';
    }

    public static function icona(): string
    {
        return 'heroicon-o-chart-bar';
    }

    public static function contenuto(): array
    {
        return [DashboardStatsWidget::class, LatestQuotesWidget::class];
    }
}
