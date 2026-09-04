<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

class ServiceReportController extends Controller
{
    /**
     * Il rapportino scaricato dal pannello, con o senza prezzi.
     *
     * La scelta e' dell'amministrazione e viaggia in querystring
     * (?prezzi=0). Per chi non ha il permesso sui prezzi non e' una scelta:
     * esce sempre la copia senza, anche chiedendo ?prezzi=1 a mano.
     *
     * Questa route serve anche l'anteprima dell'allegato dentro la
     * procedura guidata di invio (ServiceReportResource, azione "send"): il
     * PDF spedito non passa di qui, ma nasce dalla stessa view con gli
     * stessi due flag, cosi' l'anteprima non puo' mostrare una copia
     * diversa da quella che parte.
     */
    public function pdf(ServiceReport $serviceReport, Request $request)
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

        // I dipendenti non devono MAI far uscire un rapportino con i prezzi:
        // qui non si nega l'accesso, si degrada alla copia senza prezzi, cosi'
        // il tecnico il suo rapportino lo stampa comunque.
        $conPrezzi = $request->boolean('prezzi', true)
            && Gate::allows('viewPrices', $serviceReport);

        // ?articoli=0 toglie la sezione ricambi: e' la copia che il tecnico
        // manda al cliente. Non serve permesso — puo' solo togliere roba —
        // ed e' la stessa che l'anteprima nel modale di invio mostra.
        $conArticoli = $request->boolean('articoli', true);

        $pdf = Pdf::loadView('pdf.service-report', [
            'report' => $serviceReport->load(['customer', 'technician', 'machineProduct', 'machineMaterial', 'machineUnit.product', 'partsUsed.product', 'materialsUsed.material', 'tenant']),
            'showPrices' => $conPrezzi,
            'showArticoli' => $conArticoli,
        ]);

        // Il nome del file dice quale copia e': con due stampe aperte sulla
        // scrivania non si distinguerebbero.
        $suffisso = ($conPrezzi ? '' : '-senza-prezzi').($conArticoli ? '' : '-senza-articoli');

        return $pdf->stream("rapportino-{$serviceReport->number}{$suffisso}.pdf");
    }
}
