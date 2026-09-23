<?php

namespace App\Http\Controllers;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;

class QuoteController extends Controller
{
    public function pdf(Quote $quote)
    {
        // Il tenant per i ruoli lo collega SetPermissionsTeamId, middleware
        // del gruppo di queste rotte (routes/web.php): fuori dal pannello
        // Filament non risolve nessun tenant, e senza quel collegamento
        // $user->can() non troverebbe i ruoli e negherebbe sempre.

        // Route fuori dal pannello Filament: lo scope tenant automatico di
        // BelongsToTenant non si applica qui, quindi senza questo controllo
        // esplicito qualunque utente autenticato potrebbe aprire il
        // preventivo di un altro tenant conoscendone/indovinandone l'id.
        Gate::authorize('view', $quote);

        return QuoteResource::buildPdf($quote)->stream("preventivo-{$quote->number}.pdf");
    }

    /**
     * Firma e PDF accettato di una risposta del cliente: stanno sul disco
     * privato, si aprono solo da chi puo' vedere il preventivo.
     */
    public function responseFile(QuoteResponse $quoteResponse, string $file)
    {
        $quote = $quoteResponse->quote()->withoutGlobalScope('tenant')->first();
        abort_unless($quote, 404);
        Gate::authorize('view', $quote);

        $path = $file === 'firma' ? $quoteResponse->signature_path : $quoteResponse->accepted_pdf_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $file === 'firma'
            ? "firma-{$quote->number}.png"
            : "preventivo-{$quote->number}-accettato.pdf");
    }
}
