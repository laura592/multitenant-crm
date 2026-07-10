<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
