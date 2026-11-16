<?php

namespace App\Http\Controllers;

use App\Models\LetturaCasetta;
use App\Support\Casette\RendicontoLetturaPdf;

/**
 * Il rendiconto di una lettura, aperto nel browser invece che scaricato.
 *
 * Come il contratto di noleggio (vedi ContrattoNoleggioController): e' il
 * foglio che si guarda prima di spedirlo, e un'azione che scarica riempie i
 * Download di copie indistinguibili. Si rigenera a ogni richiesta, quindi
 * mostra sempre lo stato di adesso.
 */
class RendicontoCasettaController extends Controller
{
    public function __invoke(LetturaCasetta $letturaCasetta)
    {
        // Il tenant per i ruoli lo collega SetPermissionsTeamId, middleware
        // del gruppo di queste rotte.
        abort_unless(auth()->user()?->can('view', $letturaCasetta) ?? false, 403);

        $pdf = RendicontoLetturaPdf::per($letturaCasetta);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.RendicontoLetturaPdf::nomeFile($letturaCasetta).'"',
        ]);
    }
}
