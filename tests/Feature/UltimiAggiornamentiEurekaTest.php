<?php

namespace Tests\Feature;

use App\Filament\Pages\GestionaleSyncReview;
use App\Jobs\SincronizzaGestionaleJob;
use App\Models\EsecuzioneEureka;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\DiarioEsecuzioni;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Ultimi aggiornamenti da Eureka" e "Sincronizza ora" (22/09/2026): l'import
 * dei rapportini era a zero schede da settimane e nessuno lo sapeva.
 */
class UltimiAggiornamentiEurekaTest extends TestCase
{
    // Fuori dai test Laravel lancia sempre gli eventi dei comandi (anche
    // da Artisan::call()); nei test vanno accesi.
    use RefreshDatabase, WithConsoleEvents;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
    }

    private function stato(string $etichetta): array
    {
        return collect(DiarioEsecuzioni::stato())->firstWhere('etichetta', $etichetta);
    }

    public function test_un_giro_riuscito_lascia_ora_e_totali(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $giro = EsecuzioneEureka::where('comando', 'gestionale:sync')->sole();
        $this->assertSame(EsecuzioneEureka::OK, $giro->esito);
        $this->assertNotNull($giro->finita_il);
        $this->assertArrayHasKey('sync-anagrafiche', $giro->riepilogo);

        $s = $this->stato('Clienti, macchine e spostamenti');
        $this->assertSame('ok', $s['stato']);
        $this->assertStringContainsString('differenze da rivedere 0', $s['riepilogo']);
    }

    /** Il caso del 21/09/2026: il sync fermato da un file mancante sul server. */
    public function test_un_giro_fallito_dice_perche(): void
    {
        Artisan::command('eureka:import-kpi-contabili', function () {
            throw new \RuntimeException('Class "App\\Support\\Gestionale\\ConfrontoMacchine" not found');
        });

        try {
            $this->artisan('eureka:import-kpi-contabili')->run();
        } catch (\RuntimeException $e) {
            // Fuori dai test lo fa il Kernel: scrive l'eccezione nel log.
            report($e);
        }

        $giro = EsecuzioneEureka::where('comando', 'eureka:import-kpi-contabili')->sole();
        $this->assertSame(EsecuzioneEureka::ERRORE, $giro->esito);
        $this->assertStringContainsString('ConfrontoMacchine" not found', $giro->errore);
        $this->assertSame('errore', $this->stato('Fatturato e cash flow')['stato']);
    }

    public function test_un_lavoro_che_non_gira_da_troppo_si_vede(): void
    {
        EsecuzioneEureka::create(['comando' => 'eureka:import-service-reports', 'avviata_il' => now()->subDays(3), 'finita_il' => now()->subDays(3), 'esito' => EsecuzioneEureka::OK]);
        EsecuzioneEureka::create(['comando' => 'eureka:import-fatture', 'avviata_il' => now()->subHours(5), 'esito' => EsecuzioneEureka::IN_CORSO]);

        $this->assertSame('fermo', $this->stato('Rapportini')['stato']);
        $this->assertSame('errore', $this->stato('Fatture')['stato'], 'In corso da ore: si e\' interrotto.');
        $this->assertSame('mai', $this->stato('Partite aperte')['stato']);
    }

    public function test_sincronizza_ora_mette_in_coda_il_sync(): void
    {
        Queue::fake();
        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Laura', 'email' => 'l@alex.it', 'password' => bcrypt('x'), 'is_super_admin' => true]);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);

        Livewire::test(GestionaleSyncReview::class)
            ->assertSee('Ultimi aggiornamenti da Eureka')
            ->callAction('sincronizzaOra');

        Queue::assertPushed(SincronizzaGestionaleJob::class);

        // Con un giro in corso il pulsante e' spento.
        EsecuzioneEureka::create(['comando' => 'gestionale:sync', 'avviata_il' => now(), 'esito' => EsecuzioneEureka::IN_CORSO]);
        Livewire::test(GestionaleSyncReview::class)->assertActionDisabled('sincronizzaOra');
    }
}
