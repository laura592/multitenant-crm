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
 * Eureka non conosce il tipo intervento: l'import lo deduce dal testo della
 * scheda e ripiega su "riparazione". Una scheda che nel CRM e' stata segnata
 * come sanificazione non deve tornare riparazione al sync successivo.
 */
class ImportEurekaServiceReportsInterventionTypeTest extends TestCase
{
    use RefreshDatabase;

    private const EUREKA_ID = 13900;

    public function test_sanificazione_set_in_crm_survives_reimport(): void
    {
        $technician = $this->fakeEurekaScheda();

        $this->runImport($technician);

        $report = ServiceReport::where('eureka_service_report_id', self::EUREKA_ID)->firstOrFail();
        $this->assertSame(ServiceReport::TYPE_RIPARAZIONE, $report->intervention_type);

        $report->update(['intervention_type' => ServiceReport::TYPE_SANIFICAZIONE]);

        $this->runImport($technician);

        $this->assertSame(ServiceReport::TYPE_SANIFICAZIONE, $report->fresh()->intervention_type);
    }

    public function test_other_types_still_follow_eureka(): void
    {
        $technician = $this->fakeEurekaScheda();

        $this->runImport($technician);

        $report = ServiceReport::where('eureka_service_report_id', self::EUREKA_ID)->firstOrFail();
        $report->update(['intervention_type' => ServiceReport::TYPE_INSTALLAZIONE]);

        $this->runImport($technician);

        $this->assertSame(ServiceReport::TYPE_RIPARAZIONE, $report->fresh()->intervention_type);
    }

    private function fakeEurekaScheda(): User
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Camping Marina 2000', 'gestionale_code' => 399,
        ]);

        $eurekaId = self::EUREKA_ID;

        Http::fake(function ($request) use ($eurekaId) {
            $url = $request->url();

            if (preg_match('#/schedelavoro/'.$eurekaId.'(\?|$)#', $url)) {
                return Http::response([
                    'id_eureka' => $eurekaId,
                    'numero' => 900,
                    'data' => '2025-10-17T00:00:00.000+02:00',
                    'id_intestatario' => 399,
                    'sl_articolo' => ['id_eureka' => 271, 'codice' => 'SPINA 4 VIE', 'descr1' => 'IMPIANTO ALLA SPINA 4 VIE'],
                    'sl_matricola' => '',
                    'sl_sintomo' => '',
                    'sl_lavorazione' => 'Lavaggio linee',
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

        return User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico@alex.it', 'password' => bcrypt('x'),
        ]);
    }

    private function runImport(User $technician): void
    {
        $this->artisan('eureka:import-service-reports', [
            '--tenant' => 'alex',
            '--technician' => $technician->email,
            '--from' => '2025-01-01',
            '--to' => '2025-12-31',
            '--with-detail' => true,
        ])->assertExitCode(0);
    }
}
