<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\DashboardStatsWidget;
use App\Filament\Widgets\LatestQuotesWidget;
use App\Filament\Widgets\TimbraWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Http\Middleware\SetPermissionsTeamId;
use App\Models\Tenant;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
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
            ->brandName('Alex Partner Hub')
            ->brandLogo(asset('img/logo.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('img/logo.png'))
            ->renderHook(
                'panels::sidebar.footer',
                fn () => '<div style="text-align:center;padding:0.75rem 1rem;border-top:1px solid rgba(128,128,128,0.15);"><img src="'.asset('img/franke_partner_logo.png').'" alt="Franke Approved Partner" style="max-width:160px;height:auto;opacity:0.8;"></div>'
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->darkMode(true)
            ->sidebarCollapsibleOnDesktop()
            // Tenancy nativa Filament: schema condiviso, un tenant = un partner
            // (o il tenant master Alex). Niente self-registration: i tenant li
            // crea solo lo staff Alex (docs/architecture.md §3, §5.1).
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->plugin(FilamentShieldPlugin::make())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                DashboardStatsWidget::class,
                TimbraWidget::class,
                LatestQuotesWidget::class,
                UpcomingDeadlinesWidget::class,
                Widgets\AccountWidget::class,
            ])
            ->navigationGroups([
                'Vendite',
                'Catalogo',
                'Gestione',
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
