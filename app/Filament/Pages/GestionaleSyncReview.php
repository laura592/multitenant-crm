<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Gestionale\GestionaleCollegamentiClientiWidget;
use App\Filament\Widgets\Gestionale\GestionaleCollegamentiMacchinariWidget;
use App\Filament\Widgets\Gestionale\GestionaleCollegamentiProdottiWidget;
use App\Filament\Widgets\Gestionale\GestionaleDaRivedereWidget;
use App\Filament\Widgets\Gestionale\GestionaleDoppioniRapportiniWidget;
use App\Filament\Widgets\Gestionale\GestionaleFusioniMacchineWidget;
use App\Filament\Widgets\Gestionale\GestionaleMacchineImportateWidget;
use App\Jobs\ImportEurekaServiceReportsJob;
use App\Jobs\RefreshMaterialPricesFromEurekaJob;
use App\Jobs\SweepEurekaMaterialsCatalogJob;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Punto unico per rivedere cosa il sync automatico con Eureka
 * (gestionale:sync, vedi App\Support\Gestionale\GestionaleSyncRunner) ha
 * trovato — le stesse informazioni erano prima consultabili solo
 * nell'email di riepilogo (GestionaleSyncDigestMail) o sparse nei filtri
 * di Clienti/Prodotti/Macchinari, poco maneggevole per un controllo
 * rapido. Le azioni qui sono le stesse gia' presenti in quelle risorse
 * (nessuna logica nuova), solo raccolte in un posto solo.
 */
class GestionaleSyncReview extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Impostazioni';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Sync Eureka';

    protected static ?string $title = 'Sync Eureka — da controllare';

    protected static string $view = 'filament.pages.gestionale-sync-review';

    // HasPageShield definisce gia' shouldRegisterNavigation() con il
    // controllo permessi (canAccess()) — un override "nudo" qui lo
    // rimpiazzerebbe silenziosamente, facendo vedere la pagina a chiunque
    // autenticato invece che solo a chi ha il permesso Shield. Replichiamo
    // esplicitamente la stessa logica del trait aggiungendo la condizione
    // sulle credenziali Eureka.
    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return static::canAccess()
            && parent::shouldRegisterNavigation($parameters)
            && (Filament::getTenant()?->hasGestionaleEurekaCredentials() ?? false);
    }

    public function getSubheading(): ?string
    {
        return 'Risultati dell\'ultimo controllo automatico con Eureka: differenze da rivedere e nuovi collegamenti proposti, mai scritti su Eureka ne\' assegnati da soli.';
    }

    // Il default di Filament (2 colonne) stringe ogni tabella a meta' pagina:
    // con le colonne di queste widget (testo lungo nelle note + due azioni
    // Conferma/Scarta) il contenuto non ci sta e finisce tagliato fuori vista,
    // scrollabile solo in orizzontale senza alcuna scrollbar visibile.
    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    /**
     * "Importa rapportini da Eureka" forza a mano quello che
     * eureka:import-service-reports fa gia' ogni notte via cron (ultimi 7
     * giorni, vedi routes/console.php) — utile per non aspettare fino al
     * giorno dopo quando manca un rapportino/materiale appena inserito lato
     * Eureka. Il periodo di default replica quello del cron, ma resta
     * modificabile per un recupero piu' ampio all'occorrenza. Sempre
     * accodato (mai sincrono): --with-detail puo' girare per minuti su un
     * intervallo ampio.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('importaRapportiniEureka')
                ->label('Importa rapportini da Eureka')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->extraAttributes(['data-tour' => 'gestionale-sync-review-import'])
                ->form([
                    Forms\Components\DatePicker::make('from')
                        ->label('Dal')
                        ->default(now()->subDays(7))
                        ->required(),
                    Forms\Components\DatePicker::make('to')
                        ->label('Al')
                        ->default(now())
                        ->required(),
                    Forms\Components\Toggle::make('with_detail')
                        ->label('Includi ricambi/materiali (dettaglio)')
                        ->helperText('Necessario per creare i materiali mancanti come NR621216 — piu\' lento su periodi ampi.')
                        ->default(true),
                ])
                ->action(function (array $data) {
                    ImportEurekaServiceReportsJob::dispatch(
                        Filament::getTenant(),
                        $data['from'],
                        $data['to'],
                        (bool) $data['with_detail'],
                        Auth::user(),
                    );

                    Notification::make()
                        ->title('Import avviato')
                        ->body('Verrai avvisato qui quando termina.')
                        ->success()
                        ->send();
                }),
            // Stesso motivo del bottone sopra: il refresh gira gia' ogni
            // notte via cron (877 chiamate, una per materiale a catalogo),
            // ma un aggiornamento immediato serve a chi non vuole aspettare
            // fino alla notte per un prezzo appena cambiato su Eureka.
            Action::make('aggiornaPrezziMateriali')
                ->label('Aggiorna prezzi materiali')
                ->icon('heroicon-o-currency-euro')
                ->color('gray')
                ->extraAttributes(['data-tour' => 'gestionale-sync-review-prices'])
                ->requiresConfirmation()
                ->modalDescription('Ricontrolla ogni materiale gia\' a catalogo su Eureka (una chiamata per materiale) e aggiorna il prezzo di listino se cambiato. Puo\' richiedere qualche minuto.')
                ->action(function () {
                    RefreshMaterialPricesFromEurekaJob::dispatch(Filament::getTenant(), Auth::user());

                    Notification::make()
                        ->title('Aggiornamento prezzi avviato')
                        ->body('Verrai avvisato qui quando termina.')
                        ->success()
                        ->send();
                }),
            // Gira gia' ogni lunedi' alle 6:00 via cron (100 ricerche a 2
            // cifre, vedi routes/console.php) — bottone per non aspettare
            // fino a lunedi' se serve un giro extra subito.
            Action::make('scansionaCatalogoMateriali')
                ->label('Scansiona catalogo materiali')
                ->icon('heroicon-o-magnifying-glass')
                ->color('gray')
                ->extraAttributes(['data-tour' => 'gestionale-sync-review-sweep'])
                ->requiresConfirmation()
                ->modalDescription('Cerca su Eureka con tutte le combinazioni a 2 cifre (100 ricerche) per scoprire materiali mai referenziati in un rapportino, e li crea a catalogo. Puo\' richiedere qualche minuto.')
                ->action(function () {
                    SweepEurekaMaterialsCatalogJob::dispatch(Filament::getTenant(), Auth::user());

                    Notification::make()
                        ->title('Scansione catalogo avviata')
                        ->body('Verrai avvisato qui quando termina.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            GestionaleDaRivedereWidget::class,
            GestionaleCollegamentiClientiWidget::class,
            GestionaleCollegamentiProdottiWidget::class,
            GestionaleCollegamentiMacchinariWidget::class,
            GestionaleMacchineImportateWidget::class,
            GestionaleDoppioniRapportiniWidget::class,
            GestionaleFusioniMacchineWidget::class,
        ];
    }
}
