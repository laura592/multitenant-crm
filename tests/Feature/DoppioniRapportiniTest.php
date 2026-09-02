<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\ConfrontoRapportini;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Il sync propone l'abbinamento fra un rapportino compilato qui e la scheda
 * lavoro importata da Eureka che documenta lo stesso intervento, e la
 * conferma tiene il nostro travasandogli il collegamento a Eureka.
 */
class DoppioniRapportiniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
        Mail::fake();
    }

    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $tecnico = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x'),
        ]);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Hotel Neps', 'gestionale_code' => 907,
        ]);
        $prodotto = Product::create(['sku' => 'MACCHINA', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina']);

        return [$tenant, $tecnico, $cliente, $prodotto];
    }

    private function macchina(Tenant $t, Customer $c, Product $p, string $matricola): MachineUnit
    {
        return MachineUnit::create([
            'tenant_id' => $t->id, 'current_customer_id' => $c->id,
            'product_id' => $p->id, 'serial_number' => $matricola,
        ]);
    }

    private function rapportino(Tenant $t, Customer $c, User $u, ?MachineUnit $m, string $source, string $giorno, ?int $eurekaId = null): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $t->id, 'customer_id' => $c->id, 'technician_id' => $u->id,
            'machine_unit_id' => $m?->id, 'source' => $source,
            'intervention_type' => 'riparazione',
            'intervention_date' => $giorno, 'eureka_service_report_id' => $eurekaId,
            'gestionale_number' => $eurekaId ? 604 : null,
        ]);
    }

    public function test_propone_e_conferma_lo_stesso_intervento(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');
        $filtro = Material::create([
            'code' => 'FILTRO', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Filtro',
        ]);

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17517);

        foreach ([$nostro, $importato] as $r) {
            ServiceReportMaterial::create(['service_report_id' => $r->id, 'material_id' => $filtro->id, 'quantity' => 1]);
        }

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $nostro->refresh();
        $this->assertSame($importato->id, $nostro->duplicato_suggerito_id);
        $this->assertSame(ConfrontoRapportini::CERTO, $nostro->duplicato_suggerito_motivo);

        // Il confronto affiancato deve mostrare il contenuto di entrambi:
        // senza, chi conferma decide alla cieca.
        $this->actingAs($tecnico);
        \Filament\Facades\Filament::setTenant($tenant);
        \Livewire\Livewire::test(\App\Filament\Widgets\Gestionale\GestionaleDoppioniRapportiniWidget::class)
            ->assertOk()
            ->assertSee($nostro->number)
            ->assertSee($importato->number)
            ->assertSee('Confronta');

        // La conferma tiene il nostro e gli passa il collegamento a Eureka.
        $nostro->confermaDuplicato();
        $nostro->refresh();

        $this->assertSame(17517, $nostro->eureka_service_report_id);
        $this->assertNull($nostro->duplicato_suggerito_id);
        $this->assertSoftDeleted('service_reports', ['id' => $importato->id]);
    }

    /**
     * Due macchine dello stesso cliente nella stessa visita: sono interventi
     * distinti e non devono essere proposti come doppioni.
     */
    public function test_non_propone_macchine_diverse_dello_stesso_cliente(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();

        $nostro = $this->rapportino($tenant, $cliente, $tecnico,
            $this->macchina($tenant, $cliente, $prodotto, '1858049'), ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $this->rapportino($tenant, $cliente, $tecnico,
            $this->macchina($tenant, $cliente, $prodotto, '1813615'), ServiceReport::SOURCE_EUREKA, '2026-08-06', 17700);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertNull($nostro->refresh()->duplicato_suggerito_id);
    }

    /**
     * Con due schede Eureka candidate il sync non sceglie: proporre la prima
     * che capita farebbe confermare a occhi chiusi un abbinamento che il
     * sistema stesso non sa distinguere.
     */
    public function test_non_propone_nulla_quando_i_candidati_sono_due(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17700);
        $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17701);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertNull($nostro->refresh()->duplicato_suggerito_id);
    }
}
