<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressione: se un cliente ha due piani lavaggio "non ancora classificati"
 * (beverage_type null, es. residuo di un merge/import precedente), lo split
 * per tipo di impianto creava un piano "acqua" duplicato per ognuno dei due
 * invece di riconoscere che uno bastava — trovato 8 doppioni reali il
 * 2026-08-12 (vedi MergeDuplicateMaintenanceSchedules).
 */
class SplitLavaggioSchedulesDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_create_a_second_schedule_for_the_same_beverage_type(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Test',
        ]);

        MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'serial_number' => 'ACQUA-001',
            'model_name' => 'Impianto Acqua',
        ]);

        // Due piani lavaggio senza beverage_type per lo stesso cliente:
        // scenario reale che ha causato i doppioni.
        MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'status' => MaintenanceSchedule::STATUS_ATTIVO,
        ]);
        MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'status' => MaintenanceSchedule::STATUS_ATTIVO,
        ]);

        $this->artisan('maintenance-schedules:split-by-beverage-type')->assertExitCode(0);

        $acquaSchedules = MaintenanceSchedule::where('customer_id', $customer->id)
            ->where('beverage_type', MaintenanceSchedule::BEVERAGE_ACQUA)
            ->count();

        $this->assertSame(1, $acquaSchedules);
    }
}
