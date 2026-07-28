<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ServiceReportController extends Controller
{
    public function pdf(ServiceReport $serviceReport)
    {
        // TEMP debug ticket 403 service-reports.pdf (l.garbin, 2026-07-28):
        // da rimuovere una volta capita la causa reale del mismatch.
        // Log::info finirebbe scartato: in produzione LOG_LEVEL=error.
        Log::error('service-reports.pdf authorize debug', [
            'auth_user_id' => auth()->id(),
            'auth_user_email' => auth()->user()?->email,
            'auth_user_tenant_id' => auth()->user()?->tenant_id,
            'auth_user_is_super_admin' => auth()->user()?->is_super_admin,
            'auth_user_can_view_service_report' => auth()->user()?->can('view_service::report'),
            'report_id' => $serviceReport->id,
            'report_tenant_id' => $serviceReport->tenant_id,
            'tenant_match' => auth()->user()?->tenant_id === $serviceReport->tenant_id,
        ]);

        // Route fuori dal pannello Filament: lo scope tenant automatico di
        // BelongsToTenant non si applica (nessun tenant Filament attivo in
        // questo contesto), quindi senza questo controllo esplicito
        // qualunque utente autenticato potrebbe scaricare il rapportino di
        // un altro tenant conoscendone/indovinandone l'id.
        Gate::authorize('view', $serviceReport);

        $pdf = Pdf::loadView('pdf.service-report', [
            'report' => $serviceReport->load(['customer', 'technician', 'machineProduct', 'partsUsed.product', 'tenant']),
        ]);

        return $pdf->stream("rapportino-{$serviceReport->number}.pdf");
    }
}
