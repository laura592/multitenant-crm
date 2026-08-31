<?php

namespace Tests\Feature;

use App\Support\EurekaClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * L'API del fornitore restituisce 500 a intermittenza sulle stesse identiche
 * query. La ricerca per periodo di eureka:import-service-reports e' l'unica
 * chiamata non ridondante del comando (i dettagli sono in pool e tollerano gia'
 * i buchi), quindi un solo 500 di passaggio faceva fallire l'intero import
 * notturno senza importare niente — successo il 2026-08-30 alle 04:00.
 */
class EurekaClientRetryTest extends TestCase
{
    private function client(): EurekaClient
    {
        return new EurekaClient('https://eureka.test', 'utente', 'segreto');
    }

    public function test_transient_server_error_is_retried_instead_of_failing_the_import(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push('', 500)
            ->push([['id' => 17262, 'data' => '2026-08-24']], 200);

        $rows = $this->client()->searchServiceReports([
            'data_da' => '2026-08-23T00:00:00',
            'data_a' => '2026-08-30T23:59:59',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(17262, $rows[0]['id']);
    }

    public function test_retries_are_spread_out_because_the_errors_arrive_in_bursts(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push('', 500)
            ->push('', 500)
            ->push('', 500)
            ->push([['id' => 17262, 'data' => '2026-08-24']], 200);

        $rows = $this->client()->searchServiceReports(['data_da' => '2026-08-23T00:00:00']);

        $this->assertCount(1, $rows);

        // Ritentare entro pochi secondi ricadrebbe dentro la stessa raffica di
        // 500: l'attesa complessiva deve superare abbondantemente il minuto.
        Sleep::assertSleptTimes(3);
        Sleep::assertSequence([
            Sleep::for(5)->seconds(),
            Sleep::for(15)->seconds(),
            Sleep::for(45)->seconds(),
        ]);
    }

    public function test_client_error_is_not_retried(): void
    {
        Http::preventStrayRequests();

        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;

            return Http::response('', 404);
        });

        $this->expectException(\Throwable::class);

        try {
            $this->client()->searchServiceReports(['data_da' => '2026-08-23T00:00:00']);
        } finally {
            // Un 404 e' un problema di dato o di configurazione: ripeterlo
            // martellerebbe soltanto l'API del fornitore.
            $this->assertSame(1, $attempts);
        }
    }
}
