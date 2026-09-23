<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le intestazioni di sicurezza che il browser sa gia' far rispettare.
 *
 * Non c'era niente: il pannello si poteva incorniciare in un iframe su un
 * sito qualunque (clickjacking: l'utente crede di cliccare altro e clicca
 * "Elimina"), e il Referer portava in giro indirizzi che e' meglio non
 * regalare — sulla pagina pubblica del preventivo il token del cliente sta
 * dentro la URL.
 *
 * Nessuna Content-Security-Policy completa, di proposito: Filament e Alpine
 * vivono di script e stili in linea, una CSP stretta andrebbe scritta con
 * nonce su tutto il pannello e, sbagliata, lascia una pagina bianca senza
 * dire perche'. Qui c'e' la sola direttiva che serve davvero e che non puo'
 * rompere niente (frame-ancestors), che vale anche sui browser dove
 * X-Frame-Options non basta piu'.
 *
 * Globale, non nel gruppo "web": il pannello Filament ha una sua lista di
 * middleware (AdminPanelProvider) che il gruppo web non tocca.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $predefinite = [
            // Niente iframe da fuori: il pannello non si incornicia. "self"
            // e non "none" perche' Filament mostra anteprime (PDF, mail)
            // dentro iframe della stessa origine.
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",

            // Un file servito come PDF resta un PDF: niente indovinelli sul
            // tipo, che sono il modo classico per far eseguire come script
            // un file caricato da qualcun altro.
            'X-Content-Type-Options' => 'nosniff',

            // Verso l'esterno esce l'origine, mai il percorso.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',

            // La geolocalizzazione serve ("Clienti vicini", che chiede la
            // posizione dal browser), il resto no.
            'Permissions-Policy' => 'camera=(), microphone=(), payment=(), usb=()',
        ];

        // HSTS solo dove ha senso: in locale si lavora in http, e un
        // max-age preso una volta resta nel browser per mesi.
        if ($request->secure() && app()->isProduction()) {
            $predefinite['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($predefinite as $nome => $valore) {
            // Solo se nessuno ha gia' deciso: sulla pagina pubblica del
            // preventivo NoIndex mette "Referrer-Policy: no-referrer", piu'
            // stretto di questo, perche' li' il token del cliente sta dentro
            // la URL. Questo middleware e' globale, quindi gira per ultimo e
            // sovrascriverebbe la scelta piu' prudente presa sulla rotta.
            if (! $response->headers->has($nome)) {
                $response->headers->set($nome, $valore);
            }
        }

        return $response;
    }
}
