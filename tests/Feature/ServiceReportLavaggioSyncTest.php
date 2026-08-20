<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceReportLavaggioSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantAndCustomer(): array
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Uno', 'email' => 'tech@gifar.it', 'password' => bcrypt('password'),
        ]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        return [$tenant, $tech, $customer];
    }

    public function test_a_draft_sanificazione_report_generates_a_lavaggio_row_immediately(): void
    {
        [$tenant, $tech, $customer] = $this->makeTenantAndCustomer();

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_VINO,
        ]);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
            'intervention_date' => '2026-08-18',
            'work_performed' => 'Sanificazione impianto vino',
            'status' => 'bozza',
        ]);

        $this->assertSame('bozza', $report->status);

        $lavaggio = Lavaggio::where('service_report_id', $report->id)->first();

        $this->assertNotNull($lavaggio, 'la riga Lavaggio deve esistere gia in bozza');
        $this->assertSame($schedule->id, $lavaggio->maintenance_schedule_id);
        $this->assertTrue($lavaggio->data->isSameDay($report->intervention_date));

        // Cancellando la bozza il lavaggio generato sparisce con lei.
        $report->delete();
        $this->assertNull(Lavaggio::where('service_report_id', $report->id)->first());
    }

    public function test_explicit_maintenance_schedule_selection_limits_which_plans_get_a_lavaggio_row(): void
    {
        [$tenant, $tech, $customer] = $this->makeTenantAndCustomer();

        $vino = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_VINO,
        ]);

        $acqua = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_ACQUA,
        ]);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
            'intervention_date' => '2026-08-18',
            'work_performed' => 'Sanificazione impianto vino',
            'status' => 'bozza',
        ]);

        // Senza selezione esplicita, il comportamento implicito di sempre
        // genera un lavaggio per ENTRAMBI i piani attivi del cliente.
        $this->assertSame(2, Lavaggio::where('service_report_id', $report->id)->count());

        // Selezione esplicita di un solo impianto: deve restringersi a quello.
        $report->maintenanceSchedules()->attach($vino->id);
        $report->syncMaintenanceSchedule();

        $rows = Lavaggio::where('service_report_id', $report->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($vino->id, $rows->first()->maintenance_schedule_id);

        // Selezionandone due esplicitamente, entrambi tornano.
        $report->maintenanceSchedules()->attach($acqua->id);
        $report->syncMaintenanceSchedule();

        $this->assertSame(2, Lavaggio::where('service_report_id', $report->id)->count());
        $this->assertSame(
            collect([$acqua->id, $vino->id])->sort()->values()->all(),
            Lavaggio::where('service_report_id', $report->id)->pluck('maintenance_schedule_id')->sort()->values()->all()
        );
    }

}
