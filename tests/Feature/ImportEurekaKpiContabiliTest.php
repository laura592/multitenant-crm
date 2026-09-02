<?php

namespace Tests\Feature;

use App\Models\EurekaCashflowMese;
use App\Models\EurekaCashflowVoce;
use App\Models\EurekaFatturatoMese;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fatturato e cash flow sono gli unici due numeri contabili che NON
 * ricostruiamo da soli: arrivano già calcolati da Eureka. Il che sposta il
 * rischio dall'aritmetica alla trascrizione — e la trascrizione ha già una
 * trappola nota, le date del dettaglio cash flow, che tornano in formato
 * italiano mentre tutti gli altri endpoint parlano ISO.
 */
class ImportEurekaKpiContabiliTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_scrive_fatturato_cashflow_e_voci(): void
    {
        $this->tenant();
        $this->fingiEureka();

        $this->artisan('eureka:import-kpi-contabili', ['--tenant' => 'alex'])->assertExitCode(0);

        $this->assertSame(2, EurekaFatturatoMese::where('tipo', 'cliente')->count());
        $this->assertSame(1, EurekaFatturatoMese::where('tipo', 'fornitore')->count());
        $this->assertEquals(86460.83, EurekaFatturatoMese::where('tipo', 'cliente')->where('mese', 1)->value('netto'));

        $gennaio = EurekaCashflowMese::where('mese', 1)->first();
        $this->assertEquals(208.51, $gennaio->entrate);
        $this->assertEquals(59100.57, $gennaio->uscite);
        // Le componenti restano separate: una scadenza fattura non e' un
        // ordine, e il riquadro le distingue.
        $this->assertEquals(208.51, $gennaio->entrateCerte());
        $this->assertEquals(0.0, $gennaio->entrateDaFatturare());
    }

    /**
     * /contabilita/cashflow/dettaglio scrive le date come gg/mm/aaaa, tutto
     * il resto del modulo in ISO. Lasciando fare a Carbon, "10/09/2026" —
     * il 10 settembre — diventava il 9 ottobre: una scadenza spostata di un
     * mese su una schermata che serve a sapere quando escono i soldi.
     */
    public function test_le_date_italiane_del_dettaglio_non_diventano_un_altro_mese(): void
    {
        $this->tenant();
        $this->fingiEureka();

        $this->artisan('eureka:import-kpi-contabili', ['--tenant' => 'alex'])->assertExitCode(0);

        $voce = EurekaCashflowVoce::where('numero', '524/A')->first();

        $this->assertSame('2026-09-10', $voce->data_scadenza->toDateString());
        $this->assertSame('2026-06-25', $voce->data_documento->toDateString());
        // Il segno E' il verso: negativo esce.
        $this->assertFalse($voce->eEntrata());
    }

    /**
     * Un mese senza movimenti non merita una chiamata: su un orizzonte di
     * due anni sarebbero quasi tutte per niente, e l'API del fornitore è già
     * andata in disservizio sotto carico.
     */
    public function test_non_chiede_il_dettaglio_dei_mesi_vuoti(): void
    {
        $this->tenant();
        $this->fingiEureka();

        $this->artisan('eureka:import-kpi-contabili', ['--tenant' => 'alex'])->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cashflow/dettaglio')
            && str_contains($request->url(), 'mese=3'));
    }

    /**
     * Se il cash flow non arriva, il fatturato si scrive lo stesso e la
     * previsione di ieri resta dov'è: sono due archivi separati e un
     * problema su uno non deve svuotare l'altro.
     */
    public function test_un_pezzo_in_errore_non_cancella_l_altro(): void
    {
        $tenant = $this->tenant();

        EurekaCashflowMese::create([
            'tenant_id' => $tenant->id, 'anno' => 2026, 'mese' => 5,
            'entrate' => 100, 'uscite' => 50, 'saldo_mese' => 50, 'saldo_progressivo' => 50,
        ]);

        Http::fake([
            '*contabilita/fatturato*' => Http::response([
                'tipo_conto' => 'C', 'netto' => 1000, 'nr_doc' => 3,
                'mesi' => [['anno' => 2026, 'mese' => 1, 'dare' => 0, 'avere' => 0, 'netto' => 1000]],
            ], 200),
            '*contabilita/cashflow*' => Http::response('errore interno', 500),
        ]);

        $this->artisan('eureka:import-kpi-contabili', ['--tenant' => 'alex'])->assertExitCode(1);

        $this->assertSame(1, EurekaFatturatoMese::where('tipo', 'cliente')->count());
        $this->assertSame(1, EurekaCashflowMese::count(), 'la previsione precedente non si tocca');
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Alex', 'slug' => 'alex', 'is_master' => true,
            'notify_customer_gestionale_emails' => ['ufficio@alexcaffe.it'],
        ]);
    }

    private function fingiEureka(): void
    {
        $mese = fn (int $m, float $entrate, float $uscite) => [
            'anno' => 2026, 'mese' => $m,
            'entrate' => $entrate, 'uscite' => $uscite,
            'entrate_ftc' => $entrate, 'entrate_oc' => 0, 'entrate_bc' => 0,
            'uscite_ftf' => $uscite, 'uscite_of' => 0, 'uscite_bf' => 0,
            'saldo_mese' => $entrate - $uscite, 'saldo_progressivo' => $entrate - $uscite,
        ];

        Http::fake([
            '*contabilita/fatturato?tipo=F*' => Http::response([
                'tipo_conto' => 'F', 'netto' => 500, 'nr_doc' => 2,
                'mesi' => [['anno' => 2026, 'mese' => 1, 'dare' => 0, 'avere' => 0, 'netto' => 500]],
            ], 200),
            '*contabilita/fatturato*' => Http::response([
                'tipo_conto' => 'C', 'netto' => 111920.0, 'nr_doc' => 12,
                'mesi' => [
                    ['anno' => 2026, 'mese' => 1, 'dare' => 0, 'avere' => 0, 'netto' => 86460.83],
                    ['anno' => 2026, 'mese' => 2, 'dare' => 0, 'avere' => 0, 'netto' => 25459.02],
                ],
            ], 200),
            '*contabilita/cashflow/dettaglio*' => Http::response([[
                'data_documento' => '25/06/2026',
                'data_scadenza' => '10/09/2026',
                'numero' => '524/A',
                'descrizione' => 'BEVCO SRL',
                'tipo' => 'FTF',
                'tipo_doc' => '',
                'importo_totale' => -982.69,
                'importo' => -982.69,
            ]], 200),
            '*contabilita/cashflow*' => Http::response([
                'totale_entrate' => 208.51,
                'totale_uscite' => 59100.57,
                'saldo_netto' => -58892.06,
                'mesi' => [
                    $mese(1, 208.51, 59100.57),
                    // Mese vuoto: non deve costare una chiamata.
                    $mese(3, 0, 0),
                ],
            ], 200),
        ]);
    }
}
