<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Il riepilogo degli interventi di un periodo, da stampare.
 *
 * Nasce da una richiesta dell'ufficio (02/09/2026): una pagina sola con
 * cliente, chi paga, macchina e articoli, per controllare un mese di lavoro
 * senza aprire un rapportino alla volta.
 *
 * Orizzontale, perche' in verticale le sei colonne si strizzano al punto che
 * la descrizione degli articoli va a capo a ogni parola.
 */
class RiepilogoRapportiniController extends Controller
{
    public function __invoke(Request $request)
    {
        // Come ServiceReportController::pdf(): questa rotta sta fuori dal
        // pannello, quindi ne' SetPermissionsTeamId ne' lo scope tenant
        // automatico girano qui.
        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->tenant_id);

        Gate::authorize('viewAny', ServiceReport::class);

        $tenantId = auth()->user()?->tenant_id;

        $da = $this->data($request->query('da'), now()->startOfMonth());
        $a = $this->data($request->query('a'), now());

        if ($a->lt($da)) {
            [$da, $a] = [$a, $da];
        }

        $rapportini = ServiceReport::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('intervention_date', [$da->toDateString(), $a->toDateString()])
            ->with([
                'customer', 'billingCustomer', 'customer.billingCustomer',
                'machineUnit.billingCustomer', 'materialsUsed.material', 'technician', 'tenant',
            ])
            ->orderBy('intervention_date')
            ->orderBy('number')
            ->get();

        // I prezzi seguono la stessa regola della stampa del singolo
        // rapportino: i dipendenti non devono farli uscire mai.
        $conPrezzi = $rapportini->isNotEmpty()
            && Gate::allows('viewPrices', $rapportini->first());

        $pdf = Pdf::loadView('pdf.riepilogo-rapportini', [
            'rapportini' => $rapportini,
            'da' => $da,
            'a' => $a,
            'showPrices' => $conPrezzi,
            'tenant' => auth()->user()?->tenant,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream(sprintf('riepilogo-rapportini-%s_%s.pdf', $da->format('Y-m-d'), $a->format('Y-m-d')));
    }

    /** Una data malformata non deve dare un 500: si ricade sul default. */
    private function data(?string $valore, Carbon $default): Carbon
    {
        if (! $valore) {
            return $default->copy()->startOfDay();
        }

        try {
            return Carbon::parse($valore)->startOfDay();
        } catch (\Throwable) {
            return $default->copy()->startOfDay();
        }
    }
}
