<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\DashboardStatsWidget;
use App\Filament\Widgets\LatestQuotesWidget;
use App\Filament\Widgets\MagazzinoStatsWidget;
use App\Filament\Widgets\PrioritaWidget;
use App\Filament\Widgets\TimbraWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Http\Middleware\SetPermissionsTeamId;
use App\Models\Tenant;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Illuminate\Validation\Rules\Password;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->brandName('Alex Partner Hub')
            ->brandLogo(asset('img/logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('img/logo.png'))
            ->renderHook(
                'panels::sidebar.footer',
                // NOTA: x-show legato allo store Alpine della sidebar, come fa il logo
                // nell'header di Filament (vendor/filament/filament/resources/views/
                // components/sidebar/index.blade.php). Senza questo, il div ha una
                // larghezza fissa (160px + padding) che non dipende dallo stato
                // collassato: Filament collassa la sidebar SOLO nascondendo le label/
                // icone dei link (nessuna classe di larghezza esplicita viene applicata
                // sull'<aside> da chiuso), quindi la larghezza finale è quella del
                // contenuto più largo. Un footer sempre visibile a 160px costringe la
                // sidebar a restare larga anche "collassata".
                fn () => '<div x-show="$store.sidebar.isOpen" x-cloak x-transition:enter="lg:transition lg:delay-100" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" style="text-align:center;padding:0.75rem 1rem;border-top:1px solid rgba(128,128,128,0.15);"><img src="'.asset('img/franke_partner_logo.png').'" alt="Franke Approved Partner" style="max-width:160px;height:auto;opacity:0.8;"></div>'
            )
            ->renderHook(
                'panels::body.end',
                // Bug: qui veniva incluso solo l'entry JS. L'entry CSS
                // (resources/css/app.css) non arrivava mai al browser, quindi
                // tutte le classi Tailwind scritte a mano nelle viste custom
                // (es. clienti-vicini.blade.php) non avevano alcuna regola
                // corrispondente: solo il CSS interno di Filament veniva
                // caricato.
                fn (): string => Blade::render("@vite(['resources/css/app.css', 'resources/js/app.js'])")
            )
            // Palette brand Alex: blu preso dall'accento nella cornice del logo
            // Alex come primario (niente rosso: quello resta solo sul badge
            // "Franke Approved Partner", per non dare l'idea che il pannello
            // sia di Franke). Blu navy piu' scuro per la scala neutra (sidebar,
            // bordi, sfondi) cosi la dark mode gia' attiva risulta "brandizzata"
            // invece del grigio/zinc di default. "danger" resta il rosso di
            // default di Filament.
            ->colors([
                'primary' => Color::hex('#316EB4'),
                'gray' => Color::hex('#2D324B'),
            ])
            ->darkMode(true)
            // Serve a notificare il dipendente quando la sua richiesta ferie/
            // permesso viene approvata o rifiutata (prima nessuno la riceveva:
            // Notification::make()->send() senza destinatario mostra il flash
            // solo a chi clicca il bottone, cioe' l'approvatore stesso).
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(MaxWidth::Full)
            // Tenancy nativa Filament: schema condiviso, un tenant = un partner
            // (o il tenant master Alex). Niente self-registration: i tenant li
            // crea solo lo staff Alex (docs/architecture.md §3, §5.1).
            ->tenant(Tenant::class, slugAttribute: 'slug')
            // discoverResources() PRIMA del plugin Shield: FilamentShieldPlugin::
            // register() decide se registrare la sua RoleResource interrogando
            // $panel->getResources() nello stesso istante (Panel::plugin() chiama
            // $plugin->register($this) in modo sincrono) - se gira prima della
            // discovery, non trova ancora la RoleResource pubblicata sotto
            // app/Filament/Resources e la registra doppia (era visibile come due
            // voci "Ruoli" in sidebar, una nel gruppo fittizio "Filament Shield").
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->plugin(FilamentShieldPlugin::make())
            // Profilo self-service (nome/email, cambio password con conferma
            // della password attuale) + 2FA TOTP opzionale per utente.
            ->plugin(
                BreezyCore::make()
                    ->myProfile(
                        shouldRegisterUserMenu: true,
                        shouldRegisterNavigation: false,
                        hasAvatars: false,
                    )
                    ->passwordUpdateRules(
                        rules: [Password::default()->min(8)],
                        requiresCurrentPassword: true,
                    )
                    // Temporaneamente NON forzata (era `force: true`): il flusso di
                    // conferma di filament-breezy e' rotto (BreezyCore::verify() legge
                    // decrypt($user->two_factor_secret) come se fosse una colonna diretta,
                    // ma il trait TwoFactorAuthenticatable installato salva il segreto sul
                    // modello correlato BreezySession) - con force:true nessun utente
                    // riesce ad attivarla ne' quindi ad entrare. Rimettere a `force: true`
                    // (o alla condizione precedente `! app()->environment('testing')`)
                    // una volta risolto l'allineamento fra le due parti del pacchetto.
                    ->enableTwoFactorAuthentication(force: false)
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                TimbraWidget::class,
                PrioritaWidget::class,
                MagazzinoStatsWidget::class,
                DashboardStatsWidget::class,
                LatestQuotesWidget::class,
                UpcomingDeadlinesWidget::class,
            ])
            // Tutti collassati di default: con 5+ gruppi e fino a 6 voci
            // ciascuno la sidebar arrivava a scorrere parecchio prima ancora
            // di aprire una pagina. Filament ricorda comunque lo stato
            // aperto/chiuso per utente in sessione una volta espanso a mano.
            ->navigationGroups([
                NavigationGroup::make('Vendite')->collapsed(),
                NavigationGroup::make('Catalogo')->collapsed(),
                NavigationGroup::make('Interventi tecnici')->collapsed(),
                NavigationGroup::make('Magazzino')->collapsed(),
                NavigationGroup::make('Personale')->collapsed(),
                NavigationGroup::make('Impostazioni')->collapsed(),
                NavigationGroup::make('Amministrazione')->collapsed(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->tenantMiddleware([
                SetPermissionsTeamId::class,
            ], isPersistent: true);
    }
}
