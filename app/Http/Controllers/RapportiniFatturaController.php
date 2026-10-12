<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\RapportiniPerFattura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * I rapportini di una fattura, uniti in un PDF solo.
 *
 * Richiesta dell'ufficio (12/10/2026): "se scelgo fattura 459 posso salvare
 * il pdf dei 3 rapportini che sono in quella fattura, uniti". Prima pagina
 * l'elenco di cosa c'e' dentro, poi ogni rapportino per intero.
 *
 * Si passa l'id Eureka della fattura e non il suo numero: i numeri
 * ricominciano da 1 ogni anno.
 */
class RapportiniFatturaController extends Controller
{
    public function __invoke(Request $request, int $idFattura)
    {
        // Il tenant per i ruoli lo collega SetPermissionsTeamId, middleware
        // del gruppo di queste rotte (routes/web.php).
        Gate::authorize('viewAny', ServiceReport::class);

        $tenant = $this->tenant($request);

        abort_unless(auth()->user()?->canAccessTenant($tenant), 403);

        $rapportini = RapportiniPerFattura::rapportiniDellaFattura($tenant, $idFattura);

        abort_if($rapportini->isEmpty(), 404, 'Nessun rapportino collegato a questa fattura.');

        // I prezzi: come per la stampa del singolo rapportino, a chi non li
        // puo' vedere non si nega il PDF, si toglie la colonna.
        $showPrices = Gate::allows('viewPrices', $rapportini->first());

        $gruppo = [
            'etichetta' => $rapportini->first()->etichettaFatturaEureka(),
            'cliente' => $this->cliente($rapportini),
            'rapportini' => $rapportini,
        ];

        $pdf = RapportiniPerFattura::pdf($gruppo, $showPrices);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            // inline e non attachment: si apre nella scheda e da li' si
            // salva. Stessa scelta della stampa del singolo rapportino.
            'Content-Disposition' => 'inline; filename="'.RapportiniPerFattura::nomeFile($gruppo).'"',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ServiceReport>  $rapportini
     */
    private function cliente($rapportini): string
    {
        $clienti = $rapportini
            ->map(fn (ServiceReport $r) => \App\Support\DisplayName::titleCase($r->customer?->company_name))
            ->filter()
            ->unique()
            ->values();

        return $clienti->count() === 1 ? (string) $clienti->first() : $clienti->count().' clienti';
    }

    /** Come RiepilogoRapportiniController: lo staff master ha tenant_id nullo. */
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
}
