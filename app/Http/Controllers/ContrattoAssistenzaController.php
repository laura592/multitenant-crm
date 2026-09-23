<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Support\Assistenza\ContrattoAssistenza;
use App\Support\Assistenza\ContrattoAssistenzaPdf;
use App\Support\Assistenza\ModelloContrattoMancante;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Il contratto di assistenza di una macchina del preventivo, da firmare:
 * vedi ContrattoAssistenzaPdf.
 */
class ContrattoAssistenzaController extends Controller
{
    public function __invoke(Quote $quote, QuoteProduct $quoteProduct)
    {
        // Il tenant per i ruoli lo collega SetPermissionsTeamId, middleware
        // del gruppo di queste rotte (routes/web.php): fuori dal pannello
        // Filament non risolve nessun tenant, e senza quel collegamento
        // $user->can() non troverebbe i ruoli e negherebbe sempre.

        Gate::authorize('view', $quote);

        // La riga deve essere di questo preventivo: il permesso si e'
        // controllato sul preventivo, non su una riga qualunque.
        abort_unless($quoteProduct->quote_id === $quote->id, 404);
        abort_unless(ContrattoAssistenza::dellaRiga($quoteProduct) !== null, 404, 'Su questa macchina non c\'è un contratto di assistenza.');

        $nome = sprintf(
            'contratto-%s-%s-%s.pdf',
            Str::slug(ContrattoAssistenza::nome($quoteProduct->contratto_assistenza)),
            Str::slug((string) $quote->customer?->company_name) ?: 'cliente',
            $quote->number ?: now()->format('Y-m-d'),
        );

        try {
            $pdf = ContrattoAssistenzaPdf::crea($quoteProduct);
        } catch (ModelloContrattoMancante $e) {
            return response()->view('errors.404', [
                'titolo' => 'Contratto non disponibile',
                'messaggio' => $e->getMessage(),
            ], 404);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
