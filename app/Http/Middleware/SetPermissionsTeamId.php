<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collega il tenant corrente al "team" di spatie/laravel-permission, cosi
 * ogni tenant ha ruoli/permessi indipendenti (docs/architecture.md §5.3).
 *
 * Gira in due posti:
 *
 * 1. dentro il pannello, come tenant middleware persistente: il tenant lo
 *    ha gia' risolto Filament dalla URL (/admin/{tenant}/...), ed e' quello
 *    che conta anche quando lo staff master sta operando in casa d'altri;
 * 2. sulle rotte autenticate FUORI dal pannello (PDF, stampe, allegati),
 *    dove Filament non ha risolto nessun tenant: li' vale quello
 *    dell'utente.
 *
 * Il punto 2 esisteva gia', ma copiato a mano in cima a ogni controller.
 * Funzionava, e falliva male: senza quella riga $user->can() non trova il
 * ruolo (assegnato con un tenant_id preciso in model_has_roles) e nega
 * SEMPRE, anche a chi ha davvero il permesso — un errore che non si vede
 * al momento di scrivere la rotta nuova, si vede in produzione (successo
 * su l.garbin, 2026-07-28). Come middleware del gruppo, una rotta nuova
 * e' coperta perche' sta nel gruppo, non perche' qualcuno si e' ricordato.
 */
class SetPermissionsTeamId
{
    public function handle(Request $request, Closure $next): Response
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(
            Filament::getTenant()?->id ?? $request->user()?->tenant_id,
        );

        return $next($request);
    }
}
