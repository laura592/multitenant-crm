<?php

namespace App\Http\Controllers;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

class QuoteController extends Controller
{
    public function pdf(Quote $quote)
    {
        // Route fuori dal pannello Filament: SetPermissionsTeamId (che collega
        // il tenant al "team" di spatie/laravel-permission) e' un tenant
        // middleware di Filament e qui non gira, quindi senza questa riga
        // $user->can(...) non trova il ruolo dell'utente (assegnato con un
        // tenant_id specifico in model_has_roles) e nega SEMPRE l'accesso,
        // anche a chi ha davvero il permesso (stesso problema di
        // ServiceReportController::pdf, visto su l.garbin, 2026-07-28).
        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->tenant_id);

        // Route fuori dal pannello Filament: lo scope tenant automatico di
        // BelongsToTenant non si applica qui, quindi senza questo controllo
        // esplicito qualunque utente autenticato potrebbe aprire il
        // preventivo di un altro tenant conoscendone/indovinandone l'id.
        Gate::authorize('view', $quote);

        return QuoteResource::buildPdf($quote)->stream("preventivo-{$quote->number}.pdf");
    }
}
