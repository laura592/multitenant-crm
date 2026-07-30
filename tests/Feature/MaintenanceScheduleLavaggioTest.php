<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceScheduleLavaggioTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_lavaggio_recalculates_the_linked_schedule_next_due_date(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => 20,
            'next_due_date' => now()->addMonth(),
        ]);

        $first = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(5),
            'descrizione' => '5 vie + apertura',
        ]);

        $schedule->refresh();
        $this->assertSame($first->id, $schedule->last_lavaggio_id);
        $this->assertTrue($schedule->next_due_date->isSameDay($first->data->copy()->addDays(20)));

        $second = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now(),
            'descrizione' => 'Chiusura stagionale',
        ]);

        $schedule->refresh();
        $this->assertSame($second->id, $schedule->last_lavaggio_id);
        $this->assertTrue($schedule->next_due_date->isSameDay($second->data->copy()->addDays(20)));

        $second->delete();

        $schedule->refresh();
        $this->assertSame($first->id, $schedule->last_lavaggio_id);
        $this->assertTrue($schedule->next_due_date->isSameDay($first->data->copy()->addDays(20)));
    }

    public function test_a_chiamata_schedule_without_frequency_still_tracks_last_lavaggio(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => null,
        ]);

        $lavaggio = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(5),
            'descrizione' => 'Lavaggio su chiamata',
        ]);

        $schedule->refresh();
        $this->assertSame($lavaggio->id, $schedule->last_lavaggio_id);
        $this->assertNull($schedule->next_due_date);
    }

    public function test_moving_a_lavaggio_to_another_schedule_recalculates_both(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customerA = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $customerB = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Stazione']);

        $scheduleA = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerA->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => 20,
            'next_due_date' => now()->addMonth(),
        ]);

        $scheduleB = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerB->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => 30,
            'next_due_date' => now()->addMonth(),
        ]);

        $olderOnA = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerA->id,
            'maintenance_schedule_id' => $scheduleA->id,
            'data' => now()->subDays(15),
            'descrizione' => 'Lavaggio precedente su A',
        ]);

        $moved = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerA->id,
            'maintenance_schedule_id' => $scheduleA->id,
            'data' => now(),
            'descrizione' => 'Lavaggio da spostare',
        ]);

        $scheduleA->refresh();
        $this->assertSame($moved->id, $scheduleA->last_lavaggio_id);

        // Sposta il lavaggio piu' recente sul piano del cliente B (es. errore
        // di inserimento corretto in edit).
        $moved->update([
            'customer_id' => $customerB->id,
            'maintenance_schedule_id' => $scheduleB->id,
        ]);

        $scheduleB->refresh();
        $this->assertSame($moved->id, $scheduleB->last_lavaggio_id);
        $this->assertTrue($scheduleB->next_due_date->isSameDay($moved->data->copy()->addDays(30)));

        // Il piano A non deve restare agganciato al lavaggio che non gli
        // appartiene piu': deve tornare a puntare all'unico lavaggio rimasto.
        $scheduleA->refresh();
        $this->assertSame($olderOnA->id, $scheduleA->last_lavaggio_id);
        $this->assertTrue($scheduleA->next_due_date->isSameDay($olderOnA->data->copy()->addDays(20)));
    }
}