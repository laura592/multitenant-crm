<?php

namespace App\Http\Controllers;

use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Support\Assistenza\ContrattoAssistenza;
use App\Support\Assistenza\ContrattoAssistenzaPdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Il contratto di assistenza di una macchina del preventivo, da firmare:
 * vedi ContrattoAssistenzaPdf.
 */
class ContrattoAssistenzaController extends Controller
{
    public function __invoke(Quote $quote, QuoteProduct $quoteProduct)
    {
        // Fuori dal pannello: senza, i ruoli per tenant non si trovano (vedi
        // QuoteController::pdf).
        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->tenant_id);

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

        return response(ContrattoAssistenzaPdf::crea($quoteProduct), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nome.'"',
        ]);
    }
}
