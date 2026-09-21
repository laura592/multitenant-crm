<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Providers\AppServiceProvider;
use App\Support\Gestionale\EurekaClient;
use App\Support\Gestionale\EurekaSolaLettura;
use App\Support\Gestionale\GestionaleEurekaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Eureka in sola lettura (EUREKA_SOLA_LETTURA). La domanda dell'ufficio,
 * prima di dare le API di produzione: "sei sicuro che leggi i dati e non mi
 * mandi nessun delete?" (21/09/2026). Qui la risposta e' un fatto: le
 * scritture non partono.
 */
class EurekaSolaLetturaTest extends TestCase
{
    use RefreshDatabase;

    private const EUREKA = 'https://eureka.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.eureka.base_url' => self::EUREKA,
            'services.eureka.username' => 'u',
            'services.eureka.password' => 'p',
            'services.eureka.sola_lettura' => true,
        ]);

        Http::fake(['*' => Http::response(['ok' => true])]);
        Http::globalMiddleware(EurekaSolaLettura::middleware('eureka.test'));
    }

    /** @return array<string, array{0: string}> */
    public static function scritture(): array
    {
        return ['POST' => ['post'], 'PUT' => ['put'], 'PATCH' => ['patch'], 'DELETE' => ['delete']];
    }

    /** @dataProvider scritture */
    public function test_nessuna_scrittura_verso_eureka_parte(string $metodo): void
    {
        try {
            Http::withBasicAuth('u', 'p')->{$metodo}(self::EUREKA.'/schedelavoro/1');
            $this->fail("{$metodo} doveva essere bloccata");
        } catch (GestionaleEurekaException $e) {
            $this->assertStringContainsString('sola lettura', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_le_letture_passano(): void
    {
        Http::withBasicAuth('u', 'p')->get(self::EUREKA.'/show/q/sl_fattura', ['q' => 1])->throw();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET');
    }

    /** Il blocco riguarda Eureka e basta: il geocoder e il resto non si toccano. */
    public function test_gli_altri_servizi_non_si_toccano(): void
    {
        Http::post('https://nominatim.example/search', ['q' => 'Jesolo'])->throw();

        Http::assertSentCount(1);
    }

    /** L'unica scrittura che il CRM fa davvero: il pulsante "Invia a gestionale". */
    public function test_invia_a_gestionale_e_bloccato(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        $this->expectException(GestionaleEurekaException::class);

        try {
            (new EurekaClient($tenant))->inviaSchedaLavoro(['cliente' => 1], 'CRM-prova');
        } finally {
            Http::assertNothingSent();
        }
    }

    /** Spenta (come in produzione) la configurazione non aggiunge niente. */
    public function test_il_provider_lo_accende_solo_se_richiesto(): void
    {
        $spia = new class
        {
            public int $aggiunti = 0;
        };

        config(['services.eureka.sola_lettura' => false]);
        Http::swap(new class($spia) extends \Illuminate\Http\Client\Factory
        {
            public function __construct(private object $spia) { parent::__construct(); }

            public function globalMiddleware($middleware)
            {
                $this->spia->aggiunti++;

                return parent::globalMiddleware($middleware);
            }
        });

        (new AppServiceProvider($this->app))->boot();
        $this->assertSame(0, $spia->aggiunti, 'Spenta: nessun blocco.');

        config(['services.eureka.sola_lettura' => true]);
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame(1, $spia->aggiunti, 'Accesa: il blocco c\'e\'.');
    }
}
