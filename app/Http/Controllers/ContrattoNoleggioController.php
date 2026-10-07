<?php

namespace App\Http\Controllers;

use App\Filament\Resources\NoleggioResource;
use App\Models\Noleggio;

/**
 * Il contratto di noleggio, aperto nel browser invece che scaricato.
 *
 * Prima era un'azione del pannello che restituiva uno streamDownload: ogni
 * volta che si controllava una virgola finiva un PDF nei Download, e dopo
 * dieci prove non si sapeva piu' quale fosse l'ultimo. Si rigenera a ogni
 * richiesta, quindi quello che si vede e' sempre lo stato di adesso.
 */
class ContrattoNoleggioController extends Controller
{
    public function __invoke(Noleggio $noleggio)
    {
        // Il tenant per i ruoli lo collega SetPermissionsTeamId, middleware
        // del gruppo di queste rotte: vedi ContrattoAssistenzaController.
        abort_unless(NoleggioResource::canView($noleggio), 403);

        $pdf = NoleggioResource::buildPdf($noleggio);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.NoleggioResource::nomeFile($noleggio).'"',
        ]);
    }
}
