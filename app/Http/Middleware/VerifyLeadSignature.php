<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica le chiamate macchina-a-macchina del sito con una firma HMAC.
 *
 * Non serve Sanctum: c'e' un solo chiamante, conosciuto, e un segreto
 * condiviso e' sufficiente. La firma copre il corpo GREZZO della richiesta,
 * non i parametri gia' decodificati, cosi' una riserializzazione diversa
 * fra le due parti non fa fallire il confronto.
 */
class VerifyLeadSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.lead_intake.secret');

        if (blank($secret)) {
            // Meglio un 503 esplicito che accettare qualunque cosa perche'
            // qualcuno ha dimenticato la variabile in produzione.
            return response()->json(['message' => 'Intake non configurato.'], 503);
        }

        $ricevuta = (string) $request->header('X-Alex-Signature');
        $attesa = hash_hmac('sha256', $request->getContent(), $secret);

        // hash_equals e non ===: il confronto a tempo costante evita di
        // far trapelare la firma un byte alla volta.
        if (! hash_equals($attesa, $ricevuta)) {
            return response()->json(['message' => 'Firma non valida.'], 401);
        }

        return $next($request);
    }
}
