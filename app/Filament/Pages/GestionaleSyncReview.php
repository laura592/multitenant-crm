<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Gestionale\GestionaleCollegamentiClientiWidget;
use App\Filament\Widgets\Gestionale\GestionaleCollegamentiMacchinariWidget;
use App\Filament\Widgets\Gestionale\GestionaleCollegamentiProdottiWidget;
use App\Filament\Widgets\Gestionale\GestionaleDaRivedereWidget;
use App\Filament\Widgets\Gestionale\GestionaleMacchineImportateWidget;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Pages\Page;

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
    public function getHeaderWidgetsColumns(): int | string | array
    {
        return 1;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            GestionaleDaRivedereWidget::class,
            GestionaleCollegamentiClientiWidget::class,
            GestionaleCollegamentiProdottiWidget::class,
            GestionaleCollegamentiMacchinariWidget::class,
            GestionaleMacchineImportateWidget::class,
        ];
    }
}
