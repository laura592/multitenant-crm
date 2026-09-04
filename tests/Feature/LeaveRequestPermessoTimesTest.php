<?php

namespace Tests\Feature;

use App\Filament\Resources\LeaveRequestResource\Pages\CreateLeaveRequest;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class LeaveRequestPermessoTimesTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /**
     * Il permesso e' sempre in un solo giorno: "Dalle"/"Alle" sostituiscono
     * l'inserimento manuale delle ore, calcolate dal server (vedi
     * LeaveRequestResource::normalizePermessoData) anche se "Al" arriva
     * disallineato da "Dal" (il campo resta nel payload anche se nascosto
     * lato form per il permesso).
     */
    public function test_creating_a_permesso_computes_hours_from_time_range_and_keeps_a_single_day(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($employee, $tenant, 'dipendente');

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        $day = now()->addDays(3)->format('Y-m-d');

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm([
                'user_id' => $employee->id,
                'type' => 'permesso',
                'date_from' => $day,
                'date_to' => now()->addDays(10)->format('Y-m-d'),
                'time_from' => '10:00',
                'time_to' => '12:30',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $leave = LeaveRequest::where('user_id', $employee->id)->firstOrFail();

        $this->assertSame($day, $leave->date_from->format('Y-m-d'));
        $this->assertSame($day, $leave->date_to->format('Y-m-d'));
        $this->assertEquals(2.5, (float) $leave->hours);
    }

    public function test_time_to_before_time_from_is_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($employee, $tenant, 'dipendente');

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        Livewire::test(CreateLeaveRequest::class)
            ->fillForm([
                'user_id' => $employee->id,
                'type' => 'permesso',
                'date_from' => now()->addDays(3)->format('Y-m-d'),
                'time_from' => '12:30',
                'time_to' => '10:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['time_to']);
    }
}
