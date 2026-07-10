<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collega il tenant Filament corrente al "team" di spatie/laravel-permission,
 * cosi ogni tenant ha ruoli/permessi indipendenti (docs/architecture.md §5.3).
 * Registrata come tenant middleware persistente: gira dopo che Filament ha
 * gia risolto il tenant dalla URL.
 */
class SetPermissionsTeamId
{
    public function handle(Request $request, Closure $next): Response
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(Filament::getTenant()?->id);

        return $next($request);
    }
}
