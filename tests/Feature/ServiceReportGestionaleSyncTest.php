<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class ServiceReportGestionaleSyncTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private function makeTenantWithEurekaCredentials(): Tenant
    {
        return Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'gestionale_eureka_base_url' => 'https://alex.api.gestionale-eureka.it',
            'gestionale_eureka_username' => 'serviziorest',
            'gestionale_eureka_password' => 'secret',
        ]);
    }

    private function makeSignedReport(Tenant $tenant, User $tech, Customer $customer, Product $product): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'machine_product_id' => $product->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'problem_description' => 'Non eroga caffè',
            'work_performed' => 'Sostituita pompa',
            'status' => 'inviato',
        ]);
    }

    public function test_send_is_blocked_when_customer_has_no_gestionale_code(): void
    {
        Http::fake();

        $tenant = $this->makeTenantWithEurekaCredentials();
        $tech = User::create(['tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('password')]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Senza Codice']);
        $product = Product::create(['sku' => 'MACCHINA-X', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina X']);

        $report = $this->makeSignedReport($tenant, $tech, $customer, $product);

        $this->actingAs($tech);
        \Filament\Facades\Filament::setTenant($tenant);

        Livewire::test(ListServiceReports::class)
            ->callTableAction('invia_gestionale', $report);

        Http::assertNothingSent();
        $this->assertNull($report->fresh()->gestionale_sync_status);
    }

    public function test_send_succeeds_and_includes_destinazione_when_billed_to_another_customer(): void
    {
        Http::fake([
            // 'id_eureka', non 'id': la risposta reale identifica il documento
            // cosi' (vedi la stessa nota in EurekaClient.php / GET /schedelavoro/17264).
            '*/schedelavoro/*' => Http::response(['id_eureka' => 999, 'numero_doc' => 42], 201),
        ]);

        $tenant = $this->makeTenantWithEurekaCredentials();
        $tech = User::create(['tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't2@alex.it', 'password' => bcrypt('password')]);
        $this->giveRole($tech, $tenant, 'dipendente');

        $payer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Torrefazione XY', 'gestionale_code' => 500]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Centrale',
            'gestionale_code' => 501,
            'billing_customer_id' => $payer->id,
        ]);
        $product = Product::create(['sku' => 'MACCHINA-Y', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina Y', 'gestionale_code' => 700]);

        $report = $this->makeSignedReport($tenant, $tech, $customer, $product);

        $this->actingAs($tech);
        \Filament\Facades\Filament::setTenant($tenant);

        Livewire::test(ListServiceReports::class)
            ->callTableAction('invia_gestionale', $report);

        Http::assertSent(function ($request) use ($payer) {
            return str_contains($request->url(), '/schedelavoro/')
                && $request['intestatario']['id_eureka'] === 501
                && $request['destinazione']['id_eureka'] === $payer->gestionale_code
                && $request['sl_articolo']['id_eureka'] === 700
                && filled($request['objectId']);
        });

        $report->refresh();
        $this->assertSame('sent', $report->gestionale_sync_status);
        $this->assertSame(999, $report->gestionale_scheda_lavoro_id);
        $this->assertNotNull($report->gestionale_synced_at);
    }

    /**
     * Il campo "dettaglio" del payload verso Eureka si costruisce da
     * materialsUsed (Material), non piu' da partsUsed (Product) — vedi
     * ServiceReport::toGestionalePayload().
     */
    public function test_send_includes_dettaglio_built_from_materials_used(): void
    {
        Http::fake([
            '*/schedelavoro/*' => Http::response(['id_eureka' => 1000, 'numero_doc' => 43], 201),
        ]);

        $tenant = $this->makeTenantWithEurekaCredentials();
        $tech = User::create(['tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't3@alex.it', 'password' => bcrypt('password')]);
        $this->giveRole($tech, $tenant, 'dipendente');

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Roma', 'gestionale_code' => 600]);
        $product = Product::create(['sku' => 'MACCHINA-Z', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina Z', 'gestionale_code' => 800]);
        $material = Material::create([
            'tenant_id' => $tenant->id, 'source' => Material::SOURCE_EUREKA, 'gestionale_code' => 640,
            'code' => 'RIC-ALIM-01', 'category' => 'Eureka', 'type' => 'Scheda alimentazione',
        ]);

        $report = $this->makeSignedReport($tenant, $tech, $customer, $product);
        $report->materialsUsed()->create(['material_id' => $material->id, 'quantity' => 2]);

        $this->actingAs($tech);
        \Filament\Facades\Filament::setTenant($tenant);

        Livewire::test(ListServiceReports::class)
            ->callTableAction('invia_gestionale', $report);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/schedelavoro/')
                && count($request['dettaglio']) === 1
                && $request['dettaglio'][0]['id_articolo'] === 640
                && $request['dettaglio'][0]['descrizione'] === 'Scheda alimentazione'
                && $request['dettaglio'][0]['quantita'] === 2.0;
        });
    }
}
