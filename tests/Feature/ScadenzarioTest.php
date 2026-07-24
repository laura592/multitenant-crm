<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deadline;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class ScadenzarioTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    /**
     * I piani di manutenzione sono uno strumento operativo dei tecnici sul
     * campo, non una scadenza amministrativa: creare/aggiornare un piano non
     * deve piu' generare/toccare nessuna riga Deadline (era cosi' prima,
     * quando lo Scadenzario era ancora una vista unificata aperta a tutti i
     * ruoli - ora che e' riservato ad amministrazione/admin, tenerli
     * accoppiati avrebbe nascosto le manutenzioni ai tecnici che le usano
     * ogni giorno).
     */
    public function test_maintenance_schedule_does_not_create_any_deadline(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'frequency' => 'trimestrale',
            'next_due_date' => now()->addDays(10),
        ]);

        $schedule->update(['next_due_date' => now()->addDays(90)]);

        $this->assertSame(0, Deadline::where('deadlinable_id', $schedule->id)->count());
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
        $this->giveRole($user, $tenant, 'amministrazione');

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

    /**
     * Assicurazione/revisione non hanno piu' colonne dedicate su vehicles
     * (§13, dopo l'unificazione col sistema Deadline generico): la lista
     * automezzi deve continuare a mostrare la scadenza attiva letta dalla
     * relazione, non una colonna vuota per assenza del campo rimosso.
     */
    public function test_vehicle_list_shows_the_active_deadline_from_the_relation(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Gifar',
            'email' => 'test@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'amministrazione');

        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'plate' => 'AB123CD']);
        $vehicle->deadlines()->create([
            'tenant_id' => $tenant->id,
            'type' => Deadline::TYPE_ASSICURAZIONE,
            'due_date' => now()->addMonths(2)->startOfDay(),
        ]);

        $response = $this->actingAs($user)->get("/admin/{$tenant->slug}/vehicles");

        $response->assertOk();
        $response->assertSee($vehicle->activeDeadline(Deadline::TYPE_ASSICURAZIONE)->due_date->translatedFormat('M j, Y'));
    }
}
