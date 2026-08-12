<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prima di questa correzione l'import salvava solo il prezzo unitario lordo
 * (unit_cost_snapshot), buttando via "importo" (prezzo netto x quantita',
 * sconti di riga gia' applicati) — l'unico dato che rende ricostruibile il
 * valore economico reale di una riga materiale importata da Eureka.
 */
class ImportEurekaServiceReportsMaterialPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_line_total_is_captured_from_importo(): void
    {
        $tenant = Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'is_master' => true,
        ]);

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Ristorante Terrazza',
            'gestionale_code' => 3070,
        ]);

        $technician = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico-pricing@alex.it', 'password' => bcrypt('x'),
        ]);

        $eurekaId = 17260;

        Http::fake(function ($request) use ($eurekaId) {
            $url = $request->url();

            if (preg_match('#/schedelavoro/'.$eurekaId.'(\?|$)#', $url)) {
                return Http::response([
                    'id_eureka' => $eurekaId,
                    'numero' => 588,
                    'data' => '2026-07-23T00:00:00.000+02:00',
                    'sl_dataora_appuntamento' => '2026-07-22T00:00:00.000+02:00',
                    'id_intestatario' => 3070,
                    'sl_articolo' => ['id_eureka' => 177, 'codice' => 'SPINA 3 VIE', 'descr1' => 'IMPIANTO ALLA SPINA 3 VIE'],
                    'sl_matricola' => '',
                    'sl_sintomo' => '',
                    'sl_lavorazione' => '',
                    'stato_documento' => 10,
                    'note' => '',
                    'dettaglio' => [
                        [
                            'id_articolo' => 126,
                            'codice' => 'ULTVIA',
                            'descrizione' => 'ULTERIORE VIA LAVATA',
                            'quantita' => 2.0,
                            'prezzo' => 13.2,
                            'importo' => 26.4,
                        ],
                    ],
                ], 200);
            }

            if (str_contains($url, '/schedelavoro/')) {
                return Http::response([[
                    'id' => $eurekaId,
                    'id_codice_f15' => 3070,
                    'data_documento' => '2026-07-23T00:00:00.000+02:00',
                ]], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('eureka:import-service-reports', [
            '--tenant' => 'alex',
            '--technician' => $technician->email,
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--with-detail' => true,
        ])->assertExitCode(0);

        $report = ServiceReport::where('eureka_service_report_id', $eurekaId)->firstOrFail();
        $material = $report->materialsUsed()->firstOrFail();

        $this->assertSame('2.00', $material->quantity);
        $this->assertSame('13.20', $material->unit_cost_snapshot);
        $this->assertSame('26.40', $material->line_total_snapshot);
    }
}
