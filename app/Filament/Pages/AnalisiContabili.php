<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Contabilita\AccontiSenzaSaldoWidget;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;

/**
 * Analisi costruite sui documenti contabili di Eureka.
 *
 * Volutamente separata da "Scaduto clienti": quella pagina si regge sulle
 * PARTITE, che divergono dall'estratto conto del gestionale e non vedono il
 * portafoglio RiBa. Qui invece si parte dalle FATTURE, che abbiamo
 * verificato contro i PDF reali e che tornano. Tenere separate le due
 * famiglie evita di dare la stessa fiducia a dati di affidabilita' diversa.
 */
class AnalisiContabili extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Analisi contabili';

    protected static ?string $title = 'Analisi contabili';

    protected static ?string $slug = 'analisi-contabili';

    protected static string $view = 'filament.pages.analisi-contabili';

    public function getSubheading(): ?string
    {
        return 'Costruite sulle fatture registrate in contabilità. I dati si aggiornano con eureka:import-fatture.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AccontiSenzaSaldoWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }
}
