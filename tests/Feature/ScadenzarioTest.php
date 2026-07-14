<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deadline;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScadenzarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_schedule_keeps_a_synced_deadline(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'frequency' => 'trimestrale',
            'next_due_date' => now()->addDays(10),
        ]);

        $this->assertCount(1, $schedule->deadlines);
        $this->assertTrue($schedule->deadlines->first()->due_date->isSameDay($schedule->next_due_date));
        $this->assertTrue($schedule->deadlines->first()->isUrgent());

        // aggiornare next_due_date deve aggiornare la STESSA deadline, non crearne una seconda
        $schedule->update(['next_due_date' => now()->addDays(90)]);
        $schedule->refresh();

        $this->assertCount(1, $schedule->deadlines()->get());
        $this->assertFalse($schedule->deadlines->first()->isUrgent());
    }

    public function test_deadline_panel_shows_vehicle_and_tenant_deadlines_ordered_by_urgency(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Gifar',
            'email' => 'test@gifar.it',
            'password' => bcrypt('password'),
        ]);

        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'plate' => 'AB123CD']);
        $vehicle->deadlines()->create([
            'tenant_id' => $tenant->id,
            'type' => Deadline::TYPE_ASSICURAZIONE,
            'due_date' => now()->addDays(5),
        ]);
        $tenant->deadlines()->create([
            'tenant_id' => $tenant->id,
            'type' => Deadline::TYPE_POLIZZA_RCT,
            'due_date' => now()->addYear(),
        ]);

        $response = $this->actingAs($user)->get("/admin/{$tenant->slug}/deadlines");

        $response->assertOk();
        $response->assertSee('AB123CD');
        $response->assertSee('Gifar');
    }
}
