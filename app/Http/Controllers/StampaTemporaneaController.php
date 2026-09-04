<?php

namespace App\Http\Controllers;

use App\Support\Pdf\StampaTemporanea;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve un PDF parcheggiato da un'azione del pannello (vedi
 * App\Support\Pdf\StampaTemporanea), inline: e' l'unico modo perche' si apra
 * nel visualizzatore del browser invece di finire in "Download".
 */
class StampaTemporaneaController extends Controller
{
    public function __invoke(string $chiave): Response
    {
        $stampa = StampaTemporanea::ritira($chiave);

        // Scaduta (dieci minuti) o di un altro utente: sono lo stesso caso
        // per chi guarda, e distinguerli direbbe a un estraneo che quella
        // chiave esiste.
        abort_if($stampa === null, 404);

        return response($stampa['contenuto'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($stampa['nome']).'"',
        ]);
    }
}
