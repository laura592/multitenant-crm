<?php

namespace App\Filament\Widgets\Sezioni;

use App\Filament\Widgets\CreaPreventivoWidget;
use App\Filament\Widgets\TimbraWidget;

class SezioneAzioniRapide extends SezioneWidget
{
    // Prima di CreaPreventivoWidget (sort -2), cioe' in cima a tutto.
    protected static ?int $sort = -3;

    public static function titolo(): string
    {
        return 'Azioni rapide';
    }

    public static function sottotitolo(): string
    {
        return 'Quello che si fa ogni giorno, senza passare dal menu';
    }

    public static function icona(): string
    {
        return 'heroicon-o-bolt';
    }

    public static function contenuto(): array
    {
        return [CreaPreventivoWidget::class, TimbraWidget::class];
    }
}
