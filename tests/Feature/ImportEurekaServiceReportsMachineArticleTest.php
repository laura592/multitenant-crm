<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * sl_articolo (il bene su cui si e' intervenuto) e' un articolo Eureka come i
 * ricambi del dettaglio: prima l'import lo materializzava come Product
 * type=service, riempiendo il catalogo preventivi di macchine del parco
 * installato che a listino non esistono — spesso gia' presenti in Materiali
 * con lo stesso codice, importate da eureka:sweep-materials-catalog.
 */
class ImportEurekaServiceReportsMachineArticleTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEureka(int $eurekaId, string $serial = ''): void
    {
        Http::fake(function ($request) use ($eurekaId, $serial) {
            $url = $request->url();

            if (preg_match('#/schedelavoro/'.$eurekaId.'(\?|$)#', $url)) {
                return Http::response([
                    'id_eureka' => $eurekaId,
                    'numero' => 601,
                    'data' => '2026-08-20T00:00:00.000+02:00',
                    'id_intestatario' => 3070,
                    'sl_articolo' => ['id_eureka' => 138, 'codice' => 'FAEMAENOVA', 'descr1' => 'FAEMA ENOVA A/2'],
                    'sl_matricola' => $serial,
                    'sl_sintomo' => 'Non eroga',
                    'sl_lavorazione' => 'Sostituita elettrovalvola',
                    'stato_documento' => 10,
                    'note' => '',
                    'dettaglio' => [],
                ], 200);
            }

            if (str_contains($url, '/schedelavoro/')) {
                return Http::response([[
                    'id' => $eurekaId,
                    'id_codice_f15' => 3070,
                    'data_documento' => '2026-08-20T00:00:00.000+02:00',
                ]], 200);
            }

            return Http::response([], 404);
        });
    }

    private function seedTenant(string $email): Tenant
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Centrale',
            'gestionale_code' => 3070,
        ]);

        User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => $email, 'password' => bcrypt('x'),
        ]);

        return $tenant;
    }

    private function runImport(string $email): void
    {
        $this->artisan('eureka:import-service-reports', [
            '--tenant' => 'alex',
            '--technician' => $email,
            '--from' => '2026-01-01',
            '--to' => '2026-12-31',
            '--with-detail' => true,
        ])->assertExitCode(0);
    }

    public function test_unknown_machine_article_becomes_a_material_not_a_product(): void
    {
        $this->seedTenant('tecnico-articolo@alex.it');
        $this->fakeEureka(17300);

        $this->runImport('tecnico-articolo@alex.it');

        $this->assertSame(0, Product::query()->count(), 'Il catalogo preventivi non deve ricevere macchine dai rapportini importati.');

        $material = Material::query()->where('code', 'FAEMAENOVA')->first();
        $this->assertNotNull($material);
        $this->assertSame(138, (int) $material->gestionale_code);
        $this->assertSame('Eureka', $material->category);
        $this->assertSame('FAEMA ENOVA A/2', $material->type);

        $report = ServiceReport::query()->where('eureka_service_report_id', 17300)->firstOrFail();
        $this->assertSame($material->id, $report->machine_material_id);
        $this->assertNull($report->machine_product_id);
        $this->assertSame('FAEMA ENOVA A/2', $report->machine_model_name);
    }

    public function test_tracked_machine_unit_gets_linked_to_the_article(): void
    {
        $tenant = $this->seedTenant('tecnico-matricola@alex.it');

        $unit = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'source' => MachineUnit::SOURCE_EUREKA,
            'serial_number' => '1444792',
            'model_name' => 'FAEMA ENOVA A/2',
        ]);

        $this->fakeEureka(17302, '1444792');
        $this->runImport('tecnico-matricola@alex.it');

        $material = Material::query()->where('code', 'FAEMAENOVA')->firstOrFail();
        $this->assertSame($material->id, $unit->refresh()->material_id);
    }

    public function test_machine_already_in_catalogue_is_reused_and_backfilled(): void
    {
        $this->seedTenant('tecnico-catalogo@alex.it');

        // Catalogo condiviso (tenant_id NULL), com'e' per le macchine a listino.
        $product = Product::create([
            'tenant_id' => null,
            'sku' => 'FAEMAENOVA',
            'type' => Product::TYPE_MACHINE,
            'name' => 'Faema Enova A/2',
            'source' => Product::SOURCE_THIRD_PARTY,
        ]);

        $this->fakeEureka(17301);
        $this->runImport('tecnico-catalogo@alex.it');

        $report = ServiceReport::query()->where('eureka_service_report_id', 17301)->firstOrFail();
        $this->assertSame($product->id, $report->machine_product_id);
        $this->assertSame(138, (int) $product->fresh()->gestionale_code, "Il codice Eureka va scritto sul prodotto gia' a catalogo.");

        // L'articolo resta comunque tracciato in Materiali: e' l'anagrafica
        // gestionale, indipendente dal fatto che vendiamo o meno la macchina.
        $this->assertSame(
            Material::query()->where('code', 'FAEMAENOVA')->value('id'),
            $report->machine_material_id,
        );
        $this->assertSame('Faema Enova A/2', $report->machine_model_name);
    }
}
