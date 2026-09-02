<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Contabilita\AccontiSenzaSaldoWidget;
use App\Filament\Widgets\Contabilita\FatturatoMensileWidget;
use App\Filament\Widgets\Contabilita\FatturatoOverviewWidget;
use App\Filament\Widgets\Contabilita\RibaWidget;
use App\Filament\Widgets\Contabilita\SaldiDivergentiWidget;
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
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Analisi contabili';

    protected static ?string $title = 'Analisi contabili';

    protected static ?string $slug = 'analisi-contabili';

    protected static string $view = 'filament.pages.analisi-contabili';

    /**
     * Solo staff master.
     *
     * Non un permesso nella matrice dei ruoli ma un cancello nel codice
     * (indicazione dell'utente, 02/09/2026): sono numeri contabili
     * dell'azienda, e "chi puo' vederli" non e' una casella che ha senso
     * spuntare per un ruolo — o sei staff master o non li vedi. Stessa
     * forma di TenantResource::canViewAny().
     *
     * Per questo la pagina esce anche dalla matrice di Shield (vedi
     * config/filament-shield.php, exclude.pages): lasciarci una casella
     * che non cambia niente e' peggio che non averla.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Costruite sulle fatture registrate in contabilità. I dati si aggiornano con eureka:import-fatture e eureka:import-kpi-contabili.';
    }

    protected function getHeaderWidgets(): array
    {
        // L'ordine e' un discorso: prima quanto abbiamo fatturato, poi
        // com'e' andato mese per mese, poi come lo incassiamo, e in fondo le
        // due liste di cose che non tornano. Chi apre la pagina per sapere
        // "come va" si ferma in alto; chi la apre per sistemare qualcosa
        // scorre.
        return [
            FatturatoOverviewWidget::class,
            FatturatoMensileWidget::class,
            RibaWidget::class,
            AccontiSenzaSaldoWidget::class,
            SaldiDivergentiWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }
}
