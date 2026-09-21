<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagine pubbliche raggiungibili solo da un link personale (preventivo al
 * cliente): non devono finire nei motori di ricerca ne' essere tenute in
 * cache da proxy intermedi.
 */
class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
