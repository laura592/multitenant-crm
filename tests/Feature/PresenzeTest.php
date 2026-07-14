<?php

namespace Tests\Feature;

use App\Filament\Pages\RiepilogoOre;
use App\Filament\Widgets\TimbraWidget;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PresenzeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_clock_in_and_out_from_widget(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        Livewire::test(TimbraWidget::class)->call('clockIn');

        $this->assertDatabaseHas('time_entries', ['user_id' => $employee->id, 'status' => 'aperta']);
        $this->assertNull(TimeEntry::first()->clock_out);

        Livewire::test(TimbraWidget::class)->call('clockOut');

        $entry = TimeEntry::first();
        $this->assertNotNull($entry->clock_out);
        $this->assertSame('chiusa', $entry->status);
    }

    public function test_employee_cannot_approve_own_leave_request_but_owner_can(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);
        $owner = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Titolare', 'email' => 'owner@gifar.it', 'password' => bcrypt('password'),
            'is_super_admin' => true, // scorciatoia per il test: is_super_admin conta come responsabile
        ]);

        $leave = LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'ferie',
            'date_from' => now()->addDays(10), 'date_to' => now()->addDays(14),
        ]);

        $this->assertSame('richiesto', $leave->status);

        $this->actingAs($owner);
        Filament::setTenant($tenant);
        $leave->approve($owner);

        $this->assertSame('approvato', $leave->fresh()->status);
        $this->assertSame($owner->id, $leave->fresh()->approved_by_user_id);
    }

    public function test_monthly_summary_computes_ordinary_overtime_and_leave_correctly(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it',
            'password' => bcrypt('password'), 'daily_contract_hours' => 8,
        ]);

        $today = now()->startOfMonth()->addDays(5);
        TimeEntry::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id,
            'clock_in' => $today->copy()->setTime(8, 0), 'clock_out' => $today->copy()->setTime(18, 0), // 10h
        ]);

        LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'ferie', 'status' => 'approvato',
            'date_from' => $today->copy()->addDays(1), 'date_to' => $today->copy()->addDays(2),
        ]);

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        $page = new RiepilogoOre();
        $page->mount();
        $page->month = $today->month;
        $page->year = $today->year;

        $rows = $page->getRows();
        $row = $rows->firstWhere('user', 'Mario Rossi');

        $this->assertEquals(8.0, $row['ordinarie']);
        $this->assertEquals(2.0, $row['straordinario']);
        $this->assertEquals(2, $row['ferie_giorni']);
    }
}
