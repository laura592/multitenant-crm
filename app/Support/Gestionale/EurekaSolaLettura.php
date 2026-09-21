<?php

namespace App\Support\Gestionale;

use Closure;
use Psr\Http\Message\RequestInterface;

/**
 * Il blocco che rende Eureka in sola lettura (config
 * services.eureka.sola_lettura).
 *
 * Nasce da una domanda precisa dell'ufficio (21/09/2026), prima di dare le
 * credenziali delle API di produzione: "sei sicuro che leggi i dati e non
 * mi mandi nessun delete?". Oggi nel codice nessuna chiamata a Eureka
 * cancella o modifica: sono tutte GET, tranne la POST /schedelavoro/ del
 * pulsante "Invia a gestionale". Ma "oggi il codice non lo fa" non e' una
 * garanzia; questo si'.
 *
 * E' un middleware globale del client HTTP: vale per ENTRAMBI i client
 * Eureka del progetto (App\Support\EurekaClient e
 * App\Support\Gestionale\EurekaClient) e per qualunque codice futuro che
 * parli con quell'host, senza dipendere da chi scrive la chiamata. Si ferma
 * prima dell'invio: la richiesta non esce dal server.
 */
final class EurekaSolaLettura
{
    /** Solo questi metodi non cambiano niente sul gestionale. */
    private const METODI_DI_LETTURA = ['GET', 'HEAD'];

    public static function middleware(string $hostEureka): Closure
    {
        $hostEureka = strtolower($hostEureka);

        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler, $hostEureka) {
            $metodo = strtoupper($request->getMethod());

            if (strtolower($request->getUri()->getHost()) === $hostEureka
                && ! in_array($metodo, self::METODI_DI_LETTURA, true)) {
                throw new GestionaleEurekaException(sprintf(
                    'Eureka e\' in sola lettura (EUREKA_SOLA_LETTURA): bloccata %s %s. Nessuna richiesta e\' partita.',
                    $metodo,
                    $request->getUri()->getPath(),
                ));
            }

            return $handler($request, $options);
        };
    }
}
