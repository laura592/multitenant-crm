<?php

namespace App\Filament\Widgets\Sezioni;

use App\Filament\Widgets\FailedGestionaleServiceReportsWidget;
use App\Filament\Widgets\PrioritaWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Filament\Widgets\UpcomingMaintenanceWidget;

/**
 * Tutto cio' che chiede un intervento: richieste aperte, scadenze, piani di
 * manutenzione, invii a Eureka da rifare. Sta in cima perche' e' l'unica
 * parte della dashboard su cui c'e' qualcosa da fare adesso.
 */
class SezioneDaFare extends SezioneWidget
{
    protected static ?int $sort = 0;

    public static function titolo(): string
    {
        return 'Da fare adesso';
    }

    public static function sottotitolo(): string
    {
        return 'Richieste, scadenze e lavorazioni che aspettano qualcuno';
    }

    public static function icona(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public static function contenuto(): array
    {
        return [
            PrioritaWidget::class,
            UpcomingDeadlinesWidget::class,
            FailedGestionaleServiceReportsWidget::class,
            UpcomingMaintenanceWidget::class,
        ];
    }
}
