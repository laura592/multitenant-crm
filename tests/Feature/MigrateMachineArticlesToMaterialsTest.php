<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2: le macchine che gli import rapportini avevano creato in Prodotti
 * tornano in Materiali, dove Eureka le tiene insieme ai ricambi.
 */
class MigrateMachineArticlesToMaterialsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
        $this->technician = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico-migrazione@alex.it', 'password' => bcrypt('x'),
        ]);
    }

    private function phantomProduct(string $sku, string $name, int $articleId): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => $sku,
            'type' => Product::TYPE_SERVICE,
            'name' => $name,
            'eureka_article_id' => $articleId,
            'gestionale_code' => $articleId,
            'source' => Product::SOURCE_THIRD_PARTY,
        ]);
    }

    private function report(?Product $product): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'technician_id' => $this->technician->id,
            'machine_product_id' => $product?->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'intervention_date' => now(),
            'work_performed' => 'Sostituita elettrovalvola',
        ]);
    }

    public function test_missing_article_is_created_in_materials_and_product_is_removed(): void
    {
        $product = $this->phantomProduct('PRESTIGEA/2', 'FAEMA PRESTIGE A/2', 15808);
        $report = $this->report($product);

        $this->artisan('eureka:migrate-machine-articles')->assertExitCode(0);

        $material = Material::query()->where('code', 'PRESTIGEA/2')->first();
        $this->assertNotNull($material);
        $this->assertSame(15808, (int) $material->gestionale_code);
        $this->assertSame('FAEMA PRESTIGE A/2', $material->type);
        $this->assertSame('Eureka', $material->category);

        $report->refresh();
        $this->assertSame($material->id, $report->machine_material_id);
        $this->assertNull($report->machine_product_id);
        $this->assertSame('FAEMA PRESTIGE A/2', $report->machine_model_name);

        $this->assertNull(Product::query()->find($product->id));
    }

    public function test_existing_material_is_reused_and_gets_the_eureka_code(): void
    {
        $material = Material::create([
            'tenant_id' => $this->tenant->id,
            'source' => Material::SOURCE_EUREKA,
            'code' => 'A600FM',
            'category' => 'Eureka',
            'type' => 'MACCHINA PER CAFFE\' FRANKE A600FM',
        ]);

        $product = $this->phantomProduct('A600FM', 'MACCHINA PER CAFFE\' FRANKE A600FM', 19339);
        $report = $this->report($product);

        $this->artisan('eureka:migrate-machine-articles')->assertExitCode(0);

        $this->assertSame(1, Material::query()->where('code', 'A600FM')->count(), 'Il materiale gia\' presente non va duplicato.');
        $this->assertSame(19339, (int) $material->fresh()->gestionale_code);
        $this->assertSame($material->id, $report->refresh()->machine_material_id);
    }

    public function test_machine_unit_is_linked_to_the_article_it_came_from(): void
    {
        $product = $this->phantomProduct('EMBLEMA A/3', 'FAEMA EMBLEMA A 3 GRUPPI', 165);
        $unit = MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'source' => MachineUnit::SOURCE_EUREKA,
            'product_id' => $product->id,
            'serial_number' => '1444792',
            'model_name' => 'FAEMA EMBLEMA A 3 GRUPPI',
        ]);

        $this->artisan('eureka:migrate-machine-articles')->assertExitCode(0);

        $material = Material::query()->where('code', 'EMBLEMA A/3')->firstOrFail();
        $unit->refresh();
        $this->assertSame($material->id, $unit->material_id);
        $this->assertNull($unit->product_id);
        $this->assertSame('FAEMA EMBLEMA A 3 GRUPPI', $unit->display_name);
    }

    public function test_machine_unit_without_article_inherits_it_from_its_reports(): void
    {
        $material = Material::create([
            'tenant_id' => $this->tenant->id,
            'source' => Material::SOURCE_EUREKA,
            'code' => 'X20',
            'category' => 'Eureka',
            'gestionale_code' => 4020,
            'type' => 'FAEMA X20 CP10',
        ]);

        $unit = MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'source' => MachineUnit::SOURCE_EUREKA,
            'serial_number' => '1813615',
            'model_name' => 'FAEMA X20 CP10',
        ]);

        $report = $this->report(null);
        $report->update(['machine_unit_id' => $unit->id, 'machine_material_id' => $material->id]);

        $this->artisan('eureka:migrate-machine-articles')->assertExitCode(0);

        $this->assertSame($material->id, $unit->refresh()->material_id);
    }

    public function test_machine_unit_with_conflicting_reports_is_left_alone(): void
    {
        $first = Material::create([
            'tenant_id' => $this->tenant->id, 'source' => Material::SOURCE_EUREKA,
            'code' => 'MACESP', 'category' => 'Eureka', 'type' => 'MACCHINA PER ESPRESSO',
        ]);
        $second = Material::create([
            'tenant_id' => $this->tenant->id, 'source' => Material::SOURCE_EUREKA,
            'code' => 'MACIN', 'category' => 'Eureka', 'type' => 'MACINADOSATORE',
        ]);

        $unit = MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'source' => MachineUnit::SOURCE_EUREKA,
            'serial_number' => 'RIUSATA-001',
        ]);

        foreach ([$first, $second] as $material) {
            $this->report(null)->update([
                'machine_unit_id' => $unit->id,
                'machine_material_id' => $material->id,
            ]);
        }

        $this->artisan('eureka:migrate-machine-articles')->assertExitCode(0);

        $this->assertNull($unit->refresh()->material_id, 'Rapportini in disaccordo: nessuna deduzione.');
    }

    public function test_machine_unit_keeps_its_model_name_when_detached(): void
    {
        $product = $this->phantomProduct('E71GTIA/3', 'FAEMA E 71 GTI A/3', 4711);
        $unitWithName = MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'source' => MachineUnit::SOURCE_EUREKA,
            'product_id' => $product->id,
            'serial_number' => '1858049',
            'model_name' => 'FAEMA E 71 GTI A/3',
        ]);
        $unitWithoutName = MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'source' => MachineUnit::SOURCE_EUREKA,
            'product_id' => $product->id,
            'serial_number' => '1858050',
        ]);

        $this->artisan('eureka:migrate-machine-articles')->assertExitCode(0);

        $this->assertNull($unitWithName->refresh()->product_id);
        $this->assertSame('FAEMA E 71 GTI A/3', $unitWithName->model_name);
        $this->assertSame('FAEMA E 71 GTI A/3', $unitWithoutName->refresh()->model_name);
        $this->assertNull(Product::query()->find($product->id));
    }

    public function test_product_used_in_a_quote_is_kept(): void
    {
        $product = $this->phantomProduct('DCPRO3', 'DALLA CORTE DC PRO 3 GRUPPI', 2401);
        $report = $this->report($product);

        $quote = Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'date' => now(),
            'status' => 'bozza',
            'discount' => 0,
        ]);
        QuoteProduct::create([
            'quote_id' => $quote->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 6900, 'discount' => 0, 'tax' => 22,
        ]);

        $this->artisan('eureka:migrate-machine-articles')->assertExitCode(0);

        $this->assertNotNull(Product::query()->find($product->id), 'Un prodotto ancora a preventivo non va eliminato.');

        // Il rapportino passa comunque a Materiali: e' l'articolo giusto.
        $this->assertNotNull($report->refresh()->machine_material_id);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $product = $this->phantomProduct('SPINA 2 VIE', 'IMPIANTO ALLA SPINA 2 VIE', 190);
        $report = $this->report($product);

        $this->artisan('eureka:migrate-machine-articles', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Material::query()->count());
        $this->assertNotNull(Product::query()->find($product->id));
        $this->assertNull($report->refresh()->machine_material_id);
        $this->assertSame($product->id, $report->machine_product_id);
    }
}
