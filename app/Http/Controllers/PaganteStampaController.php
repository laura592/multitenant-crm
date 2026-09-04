<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * L'elenco delle macchine che un pagante si accolla, da stampare e mandargli.
 *
 * Orizzontale come il riepilogo interventi: cliente, matricola, macchina e
 * dove si trova stanno su una riga sola, e in verticale si strizzerebbero.
 */
class PaganteStampaController extends Controller
{
    public function __invoke(Request $request, string $pagante)
    {
        // Rotta fuori dal pannello: ne' SetPermissionsTeamId ne' lo scope
        // tenant automatico girano qui (vedi ServiceReportController::pdf()).
        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->tenant_id);

        Gate::authorize('viewAny', MachineUnit::class);

        $tenant = $this->tenant($request);
        abort_unless(auth()->user()?->canAccessTenant($tenant), 403);

        $soggetto = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->whereKey($pagante)
            ->firstOrFail();

        $macchine = MachineUnit::query()
            ->where('billing_customer_id', $soggetto->id)
            ->with('currentCustomer')
            ->get()
            ->sortBy([
                fn (MachineUnit $m) => mb_strtolower((string) $m->currentCustomer?->company_name),
                fn (MachineUnit $m) => (string) $m->serial_number,
            ])
            ->values();

        $pdf = Pdf::loadView('pdf.pagante-macchine', [
            'pagante' => $soggetto,
            'macchine' => $macchine,
            'tenant' => $tenant,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('macchine-pagate-'.(Str::slug($soggetto->company_name ?: $soggetto->full_name) ?: 'pagante').'.pdf');
    }

    private function tenant(Request $request): Tenant
    {
        $richiesto = trim((string) $request->query('tenant'));

        if ($richiesto !== '') {
            return Tenant::query()->where('id', $richiesto)->orWhere('slug', $richiesto)->firstOrFail();
        }

        return auth()->user()?->tenant
            ?? Tenant::query()->where('is_master', true)->firstOrFail();
    }
}
