<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use App\Models\Tenant;
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
 *
 * Senza importi per scelta: serve a controllare COSA e' stato fatto e su
 * quale macchina. Non c'e' quindi nessun permesso sui prezzi da rispettare
 * qui — a differenza della stampa del singolo rapportino.
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

        // Il tenant NON si prende da auth()->user()->tenant_id: lo staff
        // master ce l'ha nullo (accede a tutti i tenant dall'URL del
        // pannello, /admin/alex/...), e filtrare su null dava un riepilogo
        // vuoto senza dire perche'. Fuori dal pannello quel prefisso non
        // arriva, quindi lo passa il pulsante.
        $tenant = $this->tenant($request);

        abort_unless(auth()->user()?->canAccessTenant($tenant), 403);

        $da = $this->data($request->query('da'), now()->startOfMonth());
        $a = $this->data($request->query('a'), now());

        if ($a->lt($da)) {
            [$da, $a] = [$a, $da];
        }

        $rapportini = ServiceReport::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('intervention_date', [$da->toDateString(), $a->toDateString()])
            ->with([
                'customer', 'billingCustomer', 'customer.billingCustomer',
                'machineUnit.billingCustomer', 'materialsUsed.material', 'technician', 'tenant',
            ])
            ->orderBy('intervention_date')
            ->orderBy('number')
            ->get();

        $pdf = Pdf::loadView('pdf.riepilogo-rapportini', [
            'rapportini' => $rapportini,
            'da' => $da,
            'a' => $a,
            'tenant' => $tenant,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream(sprintf('riepilogo-rapportini-%s_%s.pdf', $da->format('Y-m-d'), $a->format('Y-m-d')));
    }

    /**
     * Il tenant su cui stampare: quello passato dal pulsante, altrimenti
     * quello dell'utente (che per lo staff master e' nullo, e allora si
     * ricade sul master).
     */
    private function tenant(Request $request): Tenant
    {
        $richiesto = trim((string) $request->query('tenant'));

        if ($richiesto !== '') {
            return Tenant::query()
                ->where('id', $richiesto)
                ->orWhere('slug', $richiesto)
                ->firstOrFail();
        }

        return auth()->user()?->tenant
            ?? Tenant::query()->where('is_master', true)->firstOrFail();
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
