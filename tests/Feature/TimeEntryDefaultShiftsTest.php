<?php

namespace Tests\Feature;

use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class TimeEntryDefaultShiftsTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_standard_shifts_are_built_from_configured_default_times(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Laura', 'email' => 'laura@gifar.it', 'password' => bcrypt('password'),
            'default_morning_in' => '08:00', 'default_morning_out' => '12:00',
            'default_afternoon_in' => '13:00', 'default_afternoon_out' => '17:00',
        ]);

        $this->assertTrue($employee->hasStandardSchedule());

        $shifts = $employee->standardShifts(today());

        $this->assertCount(2, $shifts);
        $this->assertSame('08:00', $shifts[0]['clock_in']->format('H:i'));
        $this->assertSame('12:00', $shifts[0]['clock_out']->format('H:i'));
        $this->assertSame('13:00', $shifts[1]['clock_in']->format('H:i'));
        $this->assertSame('17:00', $shifts[1]['clock_out']->format('H:i'));
    }

    public function test_standard_shifts_skip_a_period_missing_either_time(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario', 'email' => 'mario@gifar.it', 'password' => bcrypt('password'),
            'default_morning_in' => '08:00', 'default_morning_out' => '12:00',
        ]);

        $this->assertTrue($employee->hasStandardSchedule());
        $this->assertCount(1, $employee->standardShifts(today()));

        $employee->update(['default_morning_in' => null, 'default_morning_out' => null]);
        $employee->refresh();

        $this->assertFalse($employee->hasStandardSchedule());
        $this->assertCount(0, $employee->standardShifts(today()));
    }

    public function test_quick_today_button_creates_both_shifts_once(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Laura', 'email' => 'laura@gifar.it', 'password' => bcrypt('password'),
            'default_morning_in' => '08:00', 'default_morning_out' => '12:00',
            'default_afternoon_in' => '13:00', 'default_afternoon_out' => '17:00',
        ]);
        $this->giveRole($employee, $tenant, 'dipendente');

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        Livewire::test(ListTimeEntries::class)->callTableAction('quickToday');

        $this->assertSame(2, TimeEntry::where('user_id', $employee->id)->count());

        // Un secondo click non deve creare doppioni.
        Livewire::test(ListTimeEntries::class)->callTableAction('quickToday');

        $this->assertSame(2, TimeEntry::where('user_id', $employee->id)->count());
    }

    public function test_quick_backfill_creates_shifts_for_missing_weekdays_and_skips_weekend_and_already_logged_days(): void
    {
        // Giovedi' 2026-07-30 -> lunedi' 2026-08-03: copre un weekend intero.
        Carbon::setTestNow(Carbon::parse('2026-08-03'));

        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $employee = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Laura', 'email' => 'laura@gifar.it', 'password' => bcrypt('password'),
            'default_morning_in' => '08:00', 'default_morning_out' => '12:00',
            'default_afternoon_in' => '13:00', 'default_afternoon_out' => '17:00',
        ]);
        $this->giveRole($employee, $tenant, 'dipendente');

        $from = Carbon::parse('2026-07-30'); // giovedi'
        $alreadyLoggedDay = Carbon::parse('2026-07-31'); // venerdi', gia' timbrato a mano
        $saturday = Carbon::parse('2026-08-01');
        $sunday = Carbon::parse('2026-08-02');
        $until = Carbon::parse('2026-08-03'); // lunedi'

        $this->actingAs($employee);
        Filament::setTenant($tenant);

        // Il venerdi' ha gia' una timbratura (inserita a mano): va saltato.
        TimeEntry::create([
            'tenant_id' => $tenant->id,
            'user_id' => $employee->id,
            'clock_in' => $alreadyLoggedDay->copy()->setTime(9, 0),
            'clock_out' => $alreadyLoggedDay->copy()->setTime(13, 0),
            'source' => 'manuale',
            'status' => 'chiusa',
        ]);

        Livewire::test(ListTimeEntries::class)
            ->callTableAction('quickBackfill', data: [
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
            ]);

        $this->assertSame(2, TimeEntry::where('user_id', $employee->id)->whereDate('clock_in', $from)->count());
        $this->assertSame(1, TimeEntry::where('user_id', $employee->id)->whereDate('clock_in', $alreadyLoggedDay)->count());
        $this->assertSame(0, TimeEntry::where('user_id', $employee->id)->whereDate('clock_in', $saturday)->count());
        $this->assertSame(0, TimeEntry::where('user_id', $employee->id)->whereDate('clock_in', $sunday)->count());
        $this->assertSame(2, TimeEntry::where('user_id', $employee->id)->whereDate('clock_in', $until)->count());
        $this->assertSame(5, TimeEntry::where('user_id', $employee->id)->count());

        // Un secondo lancio sullo stesso intervallo non deve creare doppioni.
        Livewire::test(ListTimeEntries::class)
            ->callTableAction('quickBackfill', data: [
                'from' => $from->toDateString(),
                'until' => $until->toDateString(),
            ]);

        $this->assertSame(5, TimeEntry::where('user_id', $employee->id)->count());

        Carbon::setTestNow();
    }
}
