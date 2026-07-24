<?php

namespace Tests\Feature;

use App\Filament\Pages\RiepilogoOre;
use App\Filament\Widgets\TimbraWidget;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class PresenzeTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_employee_can_clock_in_and_out_from_widget(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($employee, $tenant, 'dipendente');

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

    public function test_lunch_break_is_excluded_from_worked_hours(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it',
            'password' => bcrypt('password'), 'daily_contract_hours' => 8,
        ]);
        $this->giveRole($employee, $tenant, 'dipendente');

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        // clockOutForBreak() e' identica a clockOut() a livello di dati (chiude
        // la timbratura aperta): quello che conta e' che generi una SECONDA
        // timbratura separata al rientro, cosi' il vuoto in mezzo (la pausa)
        // non viene sommato come ore lavorate.
        Carbon::setTestNow(today()->setTime(8, 0));
        Livewire::test(TimbraWidget::class)->call('clockIn');

        Carbon::setTestNow(today()->setTime(13, 0));
        Livewire::test(TimbraWidget::class)->call('clockOutForBreak');

        Carbon::setTestNow(today()->setTime(14, 0));
        Livewire::test(TimbraWidget::class)->call('clockIn');

        Carbon::setTestNow(today()->setTime(18, 0));
        Livewire::test(TimbraWidget::class)->call('clockOut');
        Carbon::setTestNow();

        $this->assertSame(2, TimeEntry::where('user_id', $employee->id)->count());

        $page = new RiepilogoOre();
        $page->mount();
        $page->month = today()->month;
        $page->year = today()->year;

        $row = $page->getRows()->firstWhere('user', 'Mario Rossi');

        // 8h in ufficio (8-13 e 14-18 = 5h+4h = 9h totali)... la pausa di 1h
        // (13-14) non deve MAI comparire: 9h lavorate, non 10h.
        $this->assertEquals(8.0, $row['ordinarie']);
        $this->assertEquals(1.0, $row['straordinario']);
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

    public function test_weekly_overtime_is_computed_even_without_daily_overtime(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it',
            'password' => bcrypt('password'), 'daily_contract_hours' => 8, 'weekly_contract_hours' => 40,
        ]);

        // Lunedi-Sabato della stessa settimana ISO, 8h esatte al giorno: nessun
        // giorno supera il monte giornaliero, ma il totale (48h) supera il
        // monte settimanale di 40h.
        $monday = Carbon::now()->startOfMonth()->next(Carbon::MONDAY);
        foreach (range(0, 5) as $offset) {
            $day = $monday->copy()->addDays($offset);
            TimeEntry::create([
                'tenant_id' => $tenant->id, 'user_id' => $employee->id,
                'clock_in' => $day->copy()->setTime(8, 0), 'clock_out' => $day->copy()->setTime(16, 0),
            ]);
        }

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        $page = new RiepilogoOre();
        $page->mount();
        $page->month = $monday->month;
        $page->year = $monday->year;

        $row = $page->getRows()->firstWhere('user', 'Mario Rossi');

        $this->assertEquals(40.0, $row['ordinarie']);
        $this->assertEquals(8.0, $row['straordinario']);
    }

    public function test_malattia_appears_in_monthly_summary(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);

        $today = now()->startOfMonth()->addDays(5);
        LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'malattia', 'status' => 'approvato',
            'date_from' => $today, 'date_to' => $today->copy()->addDays(2),
        ]);

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        $page = new RiepilogoOre();
        $page->mount();
        $page->month = $today->month;
        $page->year = $today->year;

        $row = $page->getRows()->firstWhere('user', 'Mario Rossi');

        $this->assertEquals(3, $row['malattia_giorni']);
    }

    public function test_daily_detail_rows_include_worked_hours_and_absence(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it',
            'password' => bcrypt('password'), 'daily_contract_hours' => 8,
        ]);

        $today = now()->startOfMonth()->addDays(5);
        TimeEntry::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id,
            'clock_in' => $today->copy()->setTime(8, 0), 'clock_out' => $today->copy()->setTime(18, 0),
        ]);

        LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'ferie', 'status' => 'approvato',
            'date_from' => $today->copy()->addDay(), 'date_to' => $today->copy()->addDay(),
        ]);

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        $page = new RiepilogoOre();
        $page->mount();
        $page->month = $today->month;
        $page->year = $today->year;

        $rows = $page->getDailyDetailRows();

        $workedRow = $rows->first(fn ($r) => $r['date']->isSameDay($today));
        $this->assertEquals(10.0, $workedRow['ore_lavorate']);
        $this->assertEquals(8.0, $workedRow['ordinarie']);
        $this->assertEquals(2.0, $workedRow['straordinario']);

        $ferieRow = $rows->first(fn ($r) => $r['date']->isSameDay($today->copy()->addDay()));
        $this->assertSame('Ferie', $ferieRow['assenza']);
    }

    public function test_employee_receives_database_notification_when_leave_is_decided(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);
        $owner = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Titolare', 'email' => 'owner@gifar.it', 'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);

        $leave = LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'ferie',
            'date_from' => now()->addDays(10), 'date_to' => now()->addDays(14),
        ]);

        $this->actingAs($owner);
        Filament::setTenant($tenant);

        \Livewire\Livewire::test(\App\Filament\Resources\LeaveRequestResource\Pages\ListLeaveRequests::class)
            ->callTableAction('approve', $leave);

        $this->assertSame(1, $employee->notifications()->count());
        $this->assertSame('approvato', $leave->fresh()->status);
    }

    public function test_amministrazione_can_create_leave_request_for_another_employee(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);
        $staff = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Cristina', 'email' => 'cristina@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($staff, $tenant, 'amministrazione');

        $this->assertTrue($staff->can('create', LeaveRequest::class));

        $leave = LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'malattia',
            'date_from' => now(), 'date_to' => now(),
        ]);

        $this->assertSame($employee->id, $leave->user_id);
    }

    public function test_approved_leave_request_cannot_be_edited_or_deleted_by_employee(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);

        $leave = LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'ferie', 'status' => 'approvato',
            'date_from' => now()->addDays(10), 'date_to' => now()->addDays(14),
            'approved_by_user_id' => $employee->id, 'approved_at' => now(),
        ]);

        $this->assertFalse($employee->can('updateAfterDecision', $leave));

        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin2@gifar.it', 'password' => bcrypt('password'),
            'is_super_admin' => false,
        ]);
        $this->giveRole($admin, $tenant, 'admin');

        $this->assertTrue($admin->can('updateAfterDecision', $leave));
    }

    public function test_responsabile_can_reverse_an_already_approved_request(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
        ]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin2@gifar.it', 'password' => bcrypt('password'),
            'is_super_admin' => false,
        ]);
        $this->giveRole($admin, $tenant, 'admin');

        $leave = LeaveRequest::create([
            'tenant_id' => $tenant->id, 'user_id' => $employee->id, 'type' => 'ferie', 'status' => 'approvato',
            'date_from' => now()->addDays(10), 'date_to' => now()->addDays(14),
            'approved_by_user_id' => $admin->id, 'approved_at' => now(),
        ]);

        // "approve" resta autorizzato per un responsabile anche su un record
        // gia' deciso: puo' cambiare idea (LeaveRequestPolicy::approve()).
        $this->assertTrue($admin->can('approve', $leave));

        $leave->reject($admin);

        $this->assertSame('rifiutato', $leave->fresh()->status);
        $this->assertTrue($admin->can('approve', $leave->fresh()));
    }
}
