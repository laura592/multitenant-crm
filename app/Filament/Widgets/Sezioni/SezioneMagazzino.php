<?php

namespace App\Filament\Widgets\Sezioni;

use App\Filament\Widgets\MagazzinoStatsWidget;

class SezioneMagazzino extends SezioneWidget
{
    protected static ?int $sort = 7;

    public static function titolo(): string
    {
        return 'Magazzino';
    }

    public static function sottotitolo(): string
    {
        return 'Consistenza del catalogo materiali';
    }

    public static function icona(): string
    {
        return 'heroicon-o-cube';
    }

    public static function contenuto(): array
    {
        return [MagazzinoStatsWidget::class];
    }
}
