<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Gestionale\RegistroSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Il comando unico che riporta il CRM in pari con Eureka, e il diario dei
 * movimenti su cui l'ufficio va a cercare "questo prezzo da dove viene".
 */
class SincronizzaTuttoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
    }

    /**
     * L'ordine e' quello delle dipendenze, non degli orari nello schedule:
     * il catalogo prima dei rapportini (le righe articolo devono trovare il
     * materiale), e gestionale:sync per ultimo (le proposte di doppione
     * confrontano con i rapportini appena importati).
     */
    public function test_i_passi_rispettano_l_ordine_delle_dipendenze(): void
    {
        Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        $comando = new \App\Console\Commands\SincronizzaTuttoEureka;
        $metodo = new \ReflectionMethod($comando, 'passi');
        $metodo->setAccessible(true);

        $ordine = array_keys($metodo->invoke($comando, 'alex', '2026-01-01', false));

        $this->assertSame([
            'catalogo', 'prezzi', 'rapportini',
            'partite-aperte', 'fatture', 'kpi', 'paganti',
            'anagrafiche',
        ], $ordine);

        $this->assertLessThan(
            array_search('rapportini', $ordine, true),
            array_search('catalogo', $ordine, true),
            'il catalogo deve precedere i rapportini: le righe articolo devono trovare il materiale',
        );

        $this->assertSame('anagrafiche', end($ordine), 'gestionale:sync va per ultimo');
    }

    /** Un passo saltato non deve far fallire il comando. */
    public function test_saltare_tutto_non_e_un_errore(): void
    {
        Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        $this->artisan('eureka:sincronizza-tutto', [
            '--tenant' => 'alex',
            '--salta' => ['catalogo', 'prezzi', 'rapportini', 'partite-aperte', 'fatture', 'kpi', 'paganti', 'anagrafiche'],
        ])
            ->expectsOutputToContain('saltato')
            ->assertSuccessful();
    }

    /**
     * Il registro scrive su un canale suo: se finisse in laravel.log
     * annegherebbe fra le eccezioni, ed e' proprio quando qualcosa e' andato
     * storto che serve trovarlo.
     */
    public function test_il_registro_scrive_sul_canale_gestionale(): void
    {
        Log::shouldReceive('channel')->with('gestionale')->andReturnSelf();
        Log::shouldReceive('info')->once()->with(
            'prezzi: listino aggiornato',
            ['articolo' => 'CHIORD', 'da' => 44.5, 'a' => 46.2],
        );

        RegistroSync::movimento('prezzi', 'listino aggiornato', [
            'articolo' => 'CHIORD', 'da' => 44.5, 'a' => 46.2,
        ]);
    }

    /** Un problema non e' un movimento: va a warning, per poterlo grepare. */
    public function test_i_problemi_vanno_a_warning(): void
    {
        Log::shouldReceive('channel')->with('gestionale')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        RegistroSync::problema('import-rapportini', 'scheda 17499 non leggibile', ['http' => 500]);
    }

    public function test_il_canale_gestionale_esiste_e_tiene_piu_a_lungo_del_log_applicativo(): void
    {
        $gestionale = config('logging.channels.gestionale');

        $this->assertSame('daily', $gestionale['driver']);
        $this->assertStringEndsWith('gestionale.log', $gestionale['path']);
        // Le domande sui dati contabili arrivano settimane dopo il fatto.
        $this->assertGreaterThan(config('logging.channels.daily.days'), $gestionale['days']);
    }
    /**
     * --with-detail non e' opzionale: senza, le schede arrivano senza righe
     * articolo e con la data documento al posto di quella dell'appuntamento
     * (il "buco 22/07-04/08" nasceva cosi').
     */
    public function test_i_rapportini_si_importano_sempre_col_dettaglio(): void
    {
        $comando = new \App\Console\Commands\SincronizzaTuttoEureka;
        $metodo = new \ReflectionMethod($comando, 'passi');
        $metodo->setAccessible(true);

        [$nome, $argomenti] = $metodo->invoke($comando, 'alex', '2026-01-01', false)['rapportini'];

        $this->assertSame('eureka:import-service-reports', $nome);
        $this->assertTrue($argomenti['--with-detail']);
        $this->assertSame('2026-01-01', $argomenti['--from']);
    }

}
