<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Quando Eureka indica il pagante, vale quello — anche se e' il cliente
 * stesso (23/09/2026, SPINAMIKI: l'impianto alla spina di Bar Miki arriva
 * dal gestionale con codice pagante 233, cioe' Bar Miki. Il CRM lo lasciava
 * vuoto, "vuoto" voleva dire "non l'ha detto nessuno", e allora valeva il
 * pagante dell'anagrafica: Dersut. Le manutenzioni dell'impianto suo
 * finivano al torrefattore).
 */
class PaganteMacchinaEurekaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $dersut;

    private Customer $bar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->dersut = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Dersut Caffe\' SPA', 'gestionale_code' => 580]);
        $this->bar = Customer::create([
            'tenant_id' => $this->tenant->id,
            'company_name' => 'Bar Miki di Siviero Igor',
            'gestionale_code' => 233,
            'billing_customer_id' => $this->dersut->id,
        ]);
    }

    private function macchina(string $matricola, ?int $codiceEureka): MachineUnit
    {
        $m = MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'serial_number' => $matricola,
            'model_name' => 'IMPIANTO ALLA SPINA 2 VIE',
            'source' => MachineUnit::SOURCE_EUREKA,
        ]);
        $m->moveTo($this->bar, placedAt: Carbon::parse('2024-01-01'), codicePaganteEureka: $codiceEureka);

        return $m->fresh();
    }

    private function rapportino(MachineUnit $m): ServiceReport
    {
        $tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x')]);

        return ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->bar->id,
            'technician_id' => $tecnico->id,
            'machine_unit_id' => $m->id,
            'source' => ServiceReport::SOURCE_MANUALE,
            'intervention_type' => 'manutenzione',
            'intervention_date' => '2026-09-23',
        ]);
    }

    public function test_il_rapportino_segue_il_pagante_di_eureka_anche_quando_e_il_cliente_stesso(): void
    {
        $spinamiki = $this->macchina('SPINAMIKI', 233);

        $this->assertSame($this->bar->id, $spinamiki->paganteEffettivo()?->id);
        $this->assertSame($this->bar->id, $this->rapportino($spinamiki)->invoiceRecipient()->id, 'L\'impianto e\' suo: non lo paga Dersut.');

        $this->assertSame('il cliente stesso', $spinamiki->placements()->whereNull('removed_at')->sole()->paganteInParole());
    }

    public function test_se_eureka_indica_un_altro_paga_quello(): void
    {
        $macchina = $this->macchina('V24003882', 580);

        $this->assertSame($this->dersut->id, $this->rapportino($macchina)->invoiceRecipient()->id);
    }

    public function test_senza_nessuna_indicazione_vale_il_pagante_dell_anagrafica(): void
    {
        $macchina = $this->macchina('AZ7043', null);

        $this->assertNull($macchina->paganteEffettivo());
        $this->assertSame($this->dersut->id, $this->rapportino($macchina)->invoiceRecipient()->id);
    }

    public function test_il_comando_scrive_per_esteso_anche_paga_il_cliente_stesso(): void
    {
        $spinamiki = $this->macchina('SPINAMIKI', 233);
        $dersutPaga = $this->macchina('V24003882', 580);

        $this->artisan('eureka:apply-machine-billing-payer', ['--tenant' => 'alex', '--dry-run' => true])->assertSuccessful();
        $this->assertNull($spinamiki->fresh()->billing_customer_id, 'In prova non scrive.');

        $this->artisan('eureka:apply-machine-billing-payer', ['--tenant' => 'alex'])->assertSuccessful();

        $this->assertSame($this->bar->id, $spinamiki->fresh()->billing_customer_id);
        $this->assertSame($this->dersut->id, $dersutPaga->fresh()->billing_customer_id);

        // E anche sulla posizione aperta, che e' dove vive lo storico.
        $this->assertSame($this->bar->id, $spinamiki->placements()->whereNull('removed_at')->sole()->billing_customer_id);
    }
}
