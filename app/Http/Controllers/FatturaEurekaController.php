<?php

namespace App\Http\Controllers;

use App\Models\ServiceReport;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Il PDF di una fattura Eureka, aperto da un rapportino.
 *
 * Passa dal CRM e non con un link diretto a Eureka per due ragioni: le
 * credenziali Eureka non escono dal server, e qui si controlla chi la vede.
 * Una fattura ha i prezzi — e spesso e' la riepilogativa di un torrefattore,
 * con gli interventi di tutti i bar che paga — quindi la vede solo chi puo'
 * vedere i prezzi del rapportino (ServiceReportPolicy::viewPrices).
 */
class FatturaEurekaController extends Controller
{
    public function __invoke(ServiceReport $serviceReport, int $idFattura)
    {
        // Fuori dal pannello: senza, i ruoli assegnati per tenant non si
        // trovano e can() nega sempre (vedi ServiceReportController::pdf).
        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->tenant_id);

        Gate::authorize('viewPrices', $serviceReport);

        $idScheda = $serviceReport->idSchedaEureka();
        abort_if($idScheda === null, 404, 'Questo rapportino non è su Eureka.');

        $client = new EurekaClient($serviceReport->tenant);

        // La fattura deve essere davvero di questa scheda. Senza il
        // controllo, cambiando il numero nell'indirizzo si aprirebbe
        // qualunque fattura dell'azienda passando da un rapportino qualsiasi.
        $fatture = $client->fattureDellaScheda($idScheda);
        abort_if($fatture === null, 503, 'Eureka non risponde: riprova fra qualche minuto.');

        $fattura = collect($fatture)->firstWhere('id_fattura', $idFattura);
        abort_if($fattura === null, 404, 'Questa fattura non è collegata al rapportino.');

        $pdf = $client->pdfFattura($idFattura);
        abort_if($pdf === null, 503, 'Eureka non ha restituito il PDF: riprova fra qualche minuto.');

        $anno = substr((string) ($fattura['data_fattura'] ?? ''), 0, 4);
        $nome = sprintf('fattura-%s-%s-%s.pdf', $fattura['tipo_doc'] ?? 'FT', $fattura['numero_fattura'] ?? $idFattura, $anno ?: 'eureka');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
