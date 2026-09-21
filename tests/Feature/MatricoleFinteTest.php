<?php

namespace Tests\Feature;

use App\Console\Commands\StaccaMatricoleFinte;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le matricole di soli zeri sono il segnaposto di Eureka per "sconosciuta".
 * Usate per cercare la macchina, attaccavano rapportini a macchine di altri
 * clienti e davano a quelle macchine il modello sbagliato (21/09/2026: la
 * sanificazione dell'acqua di Strana Coppia su un macinadosatore diventato
 * "SPINA 3 VIE").
 */
class MatricoleFinteTest extends TestCase
{
    use RefreshDatabase;

    public function test_riconosce_le_matricole_finte(): void
    {
        foreach (['000000', '0000000', '00000', ' 0000 '] as $finta) {
            $this->assertTrue(StaccaMatricoleFinte::finta($finta), $finta);
        }

        foreach (['1543813', '000123', 'IMP-SPINA-014', '', null] as $vera) {
            $this->assertFalse(StaccaMatricoleFinte::finta($vera), (string) $vera);
        }
    }

    public function test_ripara_rapportini_e_modelli(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $majer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Majer Giudecca']);
        $strana = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Strana Coppia']);
        $tecnico = User::create(['tenant_id' => $tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
        $spina3 = Material::create(['tenant_id' => $tenant->id, 'code' => 'SPINA 3 VIE', 'category' => 'Eureka', 'type' => 'IMPIANTO ALLA SPINA 3 VIE', 'source' => Material::SOURCE_EUREKA]);
        $spina8 = Material::create(['tenant_id' => $tenant->id, 'code' => 'SPINA 8 VIE', 'category' => 'Eureka', 'type' => 'IMPIANTO ALLA SPINA 8 VIE', 'source' => Material::SOURCE_EUREKA]);

        $macinadosatore = MachineUnit::create(['tenant_id' => $tenant->id, 'current_customer_id' => $majer->id, 'serial_number' => '000000', 'model_name' => 'MACINADOSATORE FAEMA MC99', 'material_id' => $spina3->id]);
        $spinaVera = MachineUnit::create(['tenant_id' => $tenant->id, 'current_customer_id' => $strana->id, 'serial_number' => '0000000000', 'model_name' => 'IMPIANTO ALLA SPINA 8 VIE', 'material_id' => $spina8->id]);

        $sbagliato = $this->scheda($tenant, $strana, $tecnico, $macinadosatore, '000000');
        $stessoCliente = $this->scheda($tenant, $majer, $tecnico, $macinadosatore, '000000');

        $this->artisan('macchine:stacca-matricole-finte', ['--tenant' => 'alex'])
            ->expectsConfirmation('Procedo?', 'yes')
            ->assertSuccessful();

        $this->assertNull($sbagliato->fresh()->machine_unit_id, 'Macchina di un altro cliente: staccata.');
        $this->assertSame($macinadosatore->id, $stessoCliente->fresh()->machine_unit_id, 'Stesso cliente: nel dubbio si lascia.');
        $this->assertNull($macinadosatore->fresh()->material_id, 'Un macinadosatore non e\' una spina 3 vie.');
        $this->assertSame($spina8->id, $spinaVera->fresh()->material_id, 'Nome e modello coincidono: si lascia.');
    }

    private function scheda(Tenant $tenant, Customer $cliente, User $tecnico, MachineUnit $macchina, string $matricola): ServiceReport
    {
        $r = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'technician_id' => $tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2026-09-18', 'status' => 'in_gestionale',
            'machine_unit_id' => $macchina->id, 'machine_serial_number' => $matricola, 'source' => ServiceReport::SOURCE_EUREKA,
        ]);
        $r->forceFill(['eureka_service_report_id' => random_int(1, 99999)])->saveQuietly();

        return $r;
    }
}
