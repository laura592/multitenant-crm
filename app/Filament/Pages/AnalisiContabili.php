<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Contabilita\AccontiSenzaSaldoWidget;
use App\Filament\Widgets\Contabilita\FatturatoMensileWidget;
use App\Filament\Widgets\Contabilita\FatturatoOverviewWidget;
use App\Filament\Widgets\Contabilita\RibaWidget;
use App\Filament\Widgets\Contabilita\SaldiDivergentiWidget;
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
    /**
     * Numeri contabili dell'azienda. Fino al 03/09/2026 il cancello era
     * is_super_admin nel codice: o eri staff master o non li vedevi, e la
     * pagina restava fuori dalla matrice di Shield.
     *
     * Dal 04/09/2026 e' un permesso come gli altri (indicazione
     * dell'utente: nella schermata dei privilegi ci deve essere tutto).
     * Chi ha is_super_admin passa comunque, per il Gate::before in
     * AppServiceProvider, quindi nessuno perde l'accesso: quello che
     * cambia e' che ora si puo' concedere a un ruolo.
     */
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
