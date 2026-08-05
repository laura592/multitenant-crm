<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

class ServiceReportController extends Controller
{
    public function pdf(ServiceReport $serviceReport)
    {
        // Route fuori dal pannello Filament: SetPermissionsTeamId (che collega
        // il tenant al "team" di spatie/laravel-permission) e' un tenant
        // middleware di Filament e qui non gira, quindi senza questa riga
        // $user->can(...) non trova il ruolo dell'utente (assegnato con un
        // tenant_id specifico in model_has_roles) e nega SEMPRE l'accesso,
        // anche a chi ha davvero il permesso (visto su l.garbin, 2026-07-28).
        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->tenant_id);

        // Route fuori dal pannello Filament: lo scope tenant automatico di
        // BelongsToTenant non si applica (nessun tenant Filament attivo in
        // questo contesto), quindi senza questo controllo esplicito
        // qualunque utente autenticato potrebbe scaricare il rapportino di
        // un altro tenant conoscendone/indovinandone l'id.
        Gate::authorize('view', $serviceReport);

        $pdf = Pdf::loadView('pdf.service-report', [
            'report' => $serviceReport->load(['customer', 'technician', 'machineProduct', 'partsUsed.product', 'materialsUsed.material', 'tenant']),
        ]);

        return $pdf->stream("rapportino-{$serviceReport->number}.pdf");
    }
}
