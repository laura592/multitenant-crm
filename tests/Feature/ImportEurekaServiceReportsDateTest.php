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
 * Regressione: Eureka distingue "data" (data documento, spesso quando la
 * scheda viene archiviata in ufficio) da "sl_dataora_appuntamento" (quando
 * il tecnico e' stato davvero dal cliente, a volte giorni prima).
 * L'import mappava "data" su intervention_date, mostrando nel CRM la data
 * del documento spacciandola per data dell'intervento.
 */
class ImportEurekaServiceReportsDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_intervention_date_uses_appointment_date_not_document_date(): void
    {
        $tenant = Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'is_master' => true,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Camping Marina 2000',
            'gestionale_code' => 399,
        ]);

        $technician = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico@alex.it', 'password' => bcrypt('x'),
        ]);

        $eurekaId = 13866;

        Http::fake(function ($request) use ($eurekaId) {
            $url = $request->url();

            if (preg_match('#/schedelavoro/'.$eurekaId.'(\?|$)#', $url)) {
                return Http::response([
                    'id_eureka' => $eurekaId,
                    'numero' => 834,
                    'data' => '2025-10-17T00:00:00.000+02:00',
                    'sl_dataora_appuntamento' => '2025-10-14T00:00:00.000+02:00',
                    'id_intestatario' => 399,
                    'sl_articolo' => ['id_eureka' => 271, 'codice' => 'SPINA 4 VIE', 'descr1' => 'IMPIANTO ALLA SPINA 4 VIE'],
                    'sl_matricola' => '',
                    'sl_sintomo' => '',
                    'sl_lavorazione' => '',
                    'stato_documento' => 10,
                    'note' => '',
                    'dettaglio' => [],
                ], 200);
            }

            if (str_contains($url, '/schedelavoro/')) {
                return Http::response([[
                    'id' => $eurekaId,
                    'id_codice_f15' => 399,
                    'data_documento' => '2025-10-17T00:00:00.000+02:00',
                ]], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('eureka:import-service-reports', [
            '--tenant' => 'alex',
            '--technician' => $technician->email,
            '--from' => '2025-01-01',
            '--to' => '2025-12-31',
            '--with-detail' => true,
        ])->assertExitCode(0);

        $report = ServiceReport::where('eureka_service_report_id', $eurekaId)->firstOrFail();

        $this->assertSame('2025-10-14', $report->intervention_date->toDateString());
        $this->assertSame('2025-10-17', $report->gestionale_document_date->toDateString());
        $this->assertSame($customer->id, $report->customer_id);
    }

    public function test_intervention_date_falls_back_to_document_date_without_appointment(): void
    {
        $tenant = Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'is_master' => true,
        ]);

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Camping Marina 2000',
            'gestionale_code' => 399,
        ]);

        $technician = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico2@alex.it', 'password' => bcrypt('x'),
        ]);

        $eurekaId = 20001;

        Http::fake(function ($request) use ($eurekaId) {
            $url = $request->url();

            if (str_contains($url, '/schedelavoro/')) {
                return Http::response([[
                    'id' => $eurekaId,
                    'id_codice_f15' => 399,
                    'data_documento' => '2025-11-05T00:00:00.000+01:00',
                ]], 200);
            }

            return Http::response([], 404);
        });

        // Senza --with-detail: nessuna chiamata sl_dataora_appuntamento
        // disponibile, l'unica data e' quella del documento del summary.
        $this->artisan('eureka:import-service-reports', [
            '--tenant' => 'alex',
            '--technician' => $technician->email,
            '--from' => '2025-01-01',
            '--to' => '2025-12-31',
        ])->assertExitCode(0);

        $report = ServiceReport::where('eureka_service_report_id', $eurekaId)->firstOrFail();

        $this->assertSame('2025-11-05', $report->intervention_date->toDateString());
        $this->assertSame('2025-11-05', $report->gestionale_document_date->toDateString());
    }

    /**
     * Trovato su dati reali: sl_dataora_appuntamento con un anno palesemente
     * corrotto (es. "0245", "1024", "2027" per un documento del 2024) —
     * senza questo controllo l'import avrebbe mostrato interventi nel futuro
     * o secoli nel passato. Vedi commento sulla soglia in
     * ImportEurekaServiceReports::handle().
     */
    public function test_implausible_appointment_date_falls_back_to_document_date(): void
    {
        $tenant = Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'is_master' => true,
        ]);

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Camping Marina 2000',
            'gestionale_code' => 399,
        ]);

        $technician = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico3@alex.it', 'password' => bcrypt('x'),
        ]);

        $eurekaId = 8836;

        Http::fake(function ($request) use ($eurekaId) {
            $url = $request->url();

            if (preg_match('#/schedelavoro/'.$eurekaId.'(\?|$)#', $url)) {
                return Http::response([
                    'id_eureka' => $eurekaId,
                    'numero' => 634,
                    'data' => '2024-07-30T00:00:00.000+02:00',
                    'sl_dataora_appuntamento' => '2027-06-03T00:00:00.000+02:00',
                    'id_intestatario' => 399,
                    'sl_articolo' => ['id_eureka' => 271, 'codice' => 'X', 'descr1' => 'X'],
                    'sl_matricola' => '',
                    'sl_sintomo' => '',
                    'sl_lavorazione' => '',
                    'stato_documento' => 10,
                    'note' => '',
                    'dettaglio' => [],
                ], 200);
            }

            if (str_contains($url, '/schedelavoro/')) {
                return Http::response([[
                    'id' => $eurekaId,
                    'id_codice_f15' => 399,
                    'data_documento' => '2024-07-30T00:00:00.000+02:00',
                ]], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('eureka:import-service-reports', [
            '--tenant' => 'alex',
            '--technician' => $technician->email,
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
            '--with-detail' => true,
        ])->assertExitCode(0);

        $report = ServiceReport::where('eureka_service_report_id', $eurekaId)->firstOrFail();

        $this->assertSame('2024-07-30', $report->intervention_date->toDateString());
        $this->assertSame('2024-07-30', $report->gestionale_document_date->toDateString());
    }
}
