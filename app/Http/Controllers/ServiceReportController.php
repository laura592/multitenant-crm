<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;

class ServiceReportController extends Controller
{
    public function pdf(ServiceReport $serviceReport)
    {
        $pdf = Pdf::loadView('pdf.service-report', [
            'report' => $serviceReport->load(['customer', 'technician', 'machineProduct', 'partsUsed.product', 'tenant']),
        ]);

        return $pdf->stream("rapportino-{$serviceReport->number}.pdf");
    }
}
