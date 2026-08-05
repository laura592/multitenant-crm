<?php

namespace App\Providers;

use App\Filament\Widgets\Gestionale\GestionaleCollegamentiClientiWidget;
use App\Filament\Widgets\Gestionale\GestionaleCollegamentiMacchinariWidget;
use App\Filament\Widgets\Gestionale\GestionaleCollegamentiProdottiWidget;
use App\Filament\Widgets\Gestionale\GestionaleDaRivedereWidget;
use App\Filament\Widgets\Gestionale\GestionaleMacchineImportateWidget;
use App\Models\User;
use App\Support\EurekaClient;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Infolist;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;
use Jeffgreco13\FilamentBreezy\Livewire\TwoFactorAuthentication;
use Jeffgreco13\FilamentBreezy\Livewire\UpdatePassword;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EurekaClient::class, fn () => new EurekaClient(
            baseUrl: config('services.eureka.base_url', ''),
            username: config('services.eureka.username', ''),
            password: config('services.eureka.password', ''),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Staff Alex (is_super_admin): bypassa i permessi Shield/spatie in ogni
        // tenant in cui opera. E' un flag distinto dai ruoli per-tenant di
        // §5.3 (docs/architecture.md) - i ruoli restano scoped per team/tenant,
        // questo e solo per lo staff master che deve avere accesso completo
        // ovunque, senza dover replicare un'assegnazione di ruolo per ogni tenant.
        Gate::before(fn (User $user) => $user->is_super_admin ? true : null);

        // Bug segnalato: i campi data nei form mostravano il formato nativo
        // del browser (es. 07/20/2026, mese/giorno USA) invece che italiano,
        // perche' un <input type=date> nativo lo decide il browser/OS, non
        // l'app. Disattivando il nativo su ogni DatePicker dell'app (una sola
        // volta, qui) si ottiene un formato coerente ovunque senza doverlo
        // ripetere Resource per Resource. L'icona calendario esplicita serve
        // perche' senza nativo il campo non e' piu' riconoscibile a colpo
        // d'occhio come selettore data (segnalato su Automezzi, ma il fix va
        // qui perche' vale per ogni DatePicker dell'app).
        DatePicker::configureUsing(function (DatePicker $component) {
            $component->native(false)->displayFormat('d/m/Y')->prefixIcon('heroicon-o-calendar');
        });

        // Stesso bug del DatePicker sopra, ma per le colonne/entry ->date()
        // di tabelle e infolist: senza un formato esplicito usano il default
        // Filament in inglese ("Jul 23, 2026") invece che italiano, in modo
        // incoerente con i DatePicker dei form (gia' 'd/m/Y'). Questi sono i
        // default usati SOLO quando ->date() e' chiamato senza un formato
        // esplicito, quindi non tocca le colonne che gia' passano un formato
        // proprio (es. ->dateTime('d/m/Y H:i')).
        Table::$defaultDateDisplayFormat = 'd/m/Y';
        Infolist::$defaultDateDisplayFormat = 'd/m/Y';

        // Bug: cliccare "Abilita" sulla 2FA (o salvare nome/password) in
        // /my-profile falliva sempre con 419. filament-breezy registra questi
        // alias Livewire dentro BreezyCore::boot(Panel), che Filament esegue
        // solo per richieste instradate su una rotta con prefisso pannello
        // (via il middleware SetUpPanel). Il POST che Livewire usa per ogni
        // azione/interazione va pero' alla rotta globale /livewire/update,
        // che non ha quel middleware: l'alias non risultava mai registrato e
        // Livewire rispondeva "componente non trovato" con lo stesso status
        // HTTP (419) di un CSRF scaduto. Registrandoli qui (un ServiceProvider
        // di app, sempre eseguito) l'alias esiste per qualunque richiesta.
        Livewire::component('personal_info', PersonalInfo::class);
        Livewire::component('update_password', UpdatePassword::class);
        Livewire::component('two_factor_authentication', TwoFactorAuthentication::class);

        // Stesso bug del blocco sopra, causa diversa: Filament registra con
        // Livewire (quindi rende utilizzabili le sue azioni via
        // /livewire/update) solo i widget elencati in Panel::widgets() o
        // Resource::getWidgets() — MAI quelli restituiti solo da
        // Page::getHeaderWidgets(), come i 5 di GestionaleSyncReview (vedi
        // app/Filament/Widgets/Gestionale/). Il primo caricamento della
        // pagina funzionava comunque (Filament istanzia l'oggetto PHP
        // direttamente per il render iniziale, senza passare dal registro
        // Livewire), ma ogni azione successiva (paginazione, "Conferma",
        // "Scarta"...) falliva con un 419 "Page Expired" — in realta' un
        // Livewire\Exceptions\LivewireReleaseTokenMismatchException perche'
        // il componente non risultava trovato nel registro. Non li mettiamo
        // in AdminPanelProvider::widgets() perche' Filament\Pages\Dashboard
        // mostra di default TUTTI i widget li' elencati (motivo per cui
        // quella lista evita apposta discoverWidgets() sulla cartella
        // Gestionale/, vedi commento li'): li registriamo qui invece, solo
        // per Livewire, senza farli comparire in Dashboard.
        collect([
            GestionaleDaRivedereWidget::class,
            GestionaleCollegamentiClientiWidget::class,
            GestionaleCollegamentiProdottiWidget::class,
            GestionaleCollegamentiMacchinariWidget::class,
            GestionaleMacchineImportateWidget::class,
        ])->filter(fn (string $widget) => class_exists($widget))
            ->each(fn (string $widget) => Livewire::component(
                app(ComponentRegistry::class)->getName($widget),
                $widget,
            ));

        // Le pagine Edit di Filament, dopo il salvataggio, restano sulla
        // stessa pagina invece di tornare alla lista (comportamento di
        // default, comodo per modifiche successive). Su mobile pero' i
        // breadcrumb che conterrebbero il link alla lista sono nascosti
        // (classe "hidden sm:block" nel component header di Filament),
        // quindi non resta alcuna via per tornare indietro. Un render hook
        // globale, filtrato sulla naming convention "\Pages\Edit" delle
        // pagine Edit dei Resource, aggiunge un pulsante "Torna alla
        // lista" (visibile su ogni breakpoint, non solo mobile) senza
        // dover intervenire pagina per pagina in ogni Resource.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
            function (array $scopes) {
                $pageClass = $scopes[0] ?? null;

                if (! is_string($pageClass) || ! str_contains($pageClass, '\\Pages\\Edit')) {
                    return '';
                }

                $resourceClass = $pageClass::getResource();

                return view('filament.components.mobile-back-to-list-button', [
                    'url' => $resourceClass::getUrl('index'),
                ]);
            },
        );
    }
}
