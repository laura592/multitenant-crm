<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Copertura minima per il calendario appuntamenti (docs/architecture.md §15):
 * le pagine Filament caricano senza errori runtime e i permessi restano
 * quelli decisi in RolePermissions (dipendente/admin si, collaboratore no).
 */
class AppointmentCalendarTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_dipendente_can_access_appointments_resource_and_calendar_page(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Dipendente', 'email' => 'dipendente@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        $this->actingAs($user)->get("/admin/{$tenant->slug}/appointments")->assertOk();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/appointments/create")->assertOk();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/appuntamenti-calendario")->assertOk();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/google-calendar-settings")->assertOk();
    }

    public function test_collaboratore_cannot_access_appointments(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Collaboratore', 'email' => 'collaboratore@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'collaboratore');

        $this->actingAs($user)->get("/admin/{$tenant->slug}/appointments")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/appuntamenti-calendario")->assertForbidden();
    }

    public function test_appointment_edit_page_loads_with_existing_record(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $appointment = Appointment::create([
            'tenant_id' => $tenant->id,
            'technician_id' => $user->id,
            'title' => 'Manutenzione ordinaria',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $this->actingAs($user)
            ->get("/admin/{$tenant->slug}/appointments/{$appointment->id}/edit")
            ->assertOk();
    }
}
