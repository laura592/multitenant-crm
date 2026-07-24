<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;

class ServiceReportController extends Controller
{
    public function pdf(ServiceReport $serviceReport)
    {
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
