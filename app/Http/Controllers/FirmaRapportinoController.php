<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use App\Support\Rapportini\FirmaCliente;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * La firma del cliente su un rapportino: sta sul disco privato, la apre
 * solo chi puo' vedere quel rapportino.
 *
 * Prima il file era servito direttamente da /storage, cioe' da nessun
 * controllo: bastava l'indirizzo. Vedi App\Support\Rapportini\FirmaCliente.
 */
class FirmaRapportinoController extends Controller
{
    public function __invoke(ServiceReport $serviceReport): Response
    {
        // Il tenant per i ruoli lo collega SetPermissionsTeamId, middleware
        // del gruppo (routes/web.php). Lo scope tenant automatico invece qui
        // non c'e': senza questo controllo esplicito qualunque utente
        // autenticato aprirebbe la firma di un altro tenant.
        Gate::authorize('view', $serviceReport);

        $percorso = $serviceReport->customer_signature_path;
        $firma = FirmaCliente::contenuto($percorso);

        abort_if($firma === null, 404);

        // SignaturePad accetta PNG o JPEG e salva col suo formato vero.
        $tipo = str_ends_with(strtolower((string) $percorso), '.jpg') ? 'image/jpeg' : 'image/png';

        return response($firma, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => 'inline; filename="firma-'.$serviceReport->number.'.png"',
            // Privata: nessuna cache condivisa deve tenersi una firma.
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
