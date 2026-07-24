<?php

namespace Tests\Feature;

use App\Filament\Resources\DeadlineResource\Pages\ListDeadlines;
use App\Models\Deadline;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Assicurazione/bollo/revisione dei veicoli usavano un semplice
 * updateOrCreate() che sovrascriveva la riga esistente ad ogni rinnovo,
 * perdendo lo storico costi/pagamenti (segnalato dall'utente). Deadline::renew()
 * lo sostituisce: chiude la riga corrente (importo/data pagamento, stato
 * "rinnovata") e ne crea una nuova, cosi' le occorrenze passate restano
 * leggibili come storico invece di essere perse.
 */
class DeadlineRenewalTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private Tenant $tenant;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $this->vehicle = Vehicle::create(['tenant_id' => $this->tenant->id, 'plate' => 'AB123CD']);
    }

    public function test_renew_closes_the_current_row_as_history_and_creates_a_new_active_occurrence(): void
    {
        $deadline = $this->vehicle->deadlines()->create([
            'tenant_id' => $this->tenant->id,
            'type' => Deadline::TYPE_ASSICURAZIONE,
            'due_date' => now()->addDays(5),
            'reminder_days_before' => 15,
        ]);

        $newDeadline = $deadline->renew([
            'amount' => 450.50,
            'paid_at' => now(),
            'due_date' => now()->addYear(),
        ]);

        $deadline->refresh();

        $this->assertSame(Deadline::STATUS_RINNOVATA, $deadline->status);
        $this->assertEquals(450.50, $deadline->amount);
        $this->assertNotNull($deadline->paid_at);

        $this->assertSame(Deadline::STATUS_ATTIVA, $newDeadline->status);
        $this->assertNull($newDeadline->amount);
        $this->assertTrue($newDeadline->due_date->isSameDay(now()->addYear()));
        $this->assertSame(Deadline::TYPE_ASSICURAZIONE, $newDeadline->type);
        $this->assertSame($this->vehicle->id, $newDeadline->deadlinable_id);
        // Il preavviso configurato si eredita, non si resetta al default.
        $this->assertSame(15, $newDeadline->reminder_days_before);

        $this->vehicle->refresh();
        $active = $this->vehicle->activeDeadline(Deadline::TYPE_ASSICURAZIONE);
        $this->assertSame($newDeadline->id, $active->id);
    }

    public function test_renewed_rows_are_never_lost_and_form_a_chronological_history(): void
    {
        $deadline = $this->vehicle->deadlines()->create([
            'tenant_id' => $this->tenant->id,
            'type' => Deadline::TYPE_BOLLO,
            'due_date' => now()->addDays(5),
        ]);

        $deadline = $deadline->renew(['amount' => 120, 'paid_at' => now(), 'due_date' => now()->addYear()]);
        $deadline = $deadline->renew(['amount' => 130, 'paid_at' => now()->addYear(), 'due_date' => now()->addYears(2)]);

        $this->assertCount(3, $this->vehicle->deadlines()->where('type', Deadline::TYPE_BOLLO)->get());
        $this->assertSame(
            [120.00, 130.00, null],
            $this->vehicle->deadlines()
                ->where('type', Deadline::TYPE_BOLLO)
                ->orderBy('due_date')
                ->get()
                ->map(fn (Deadline $d) => $d->amount === null ? null : (float) $d->amount)
                ->all()
        );
    }

    public function test_rinnova_table_action_on_deadline_resource_creates_history_without_errors(): void
    {
        $admin = User::create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'email' => 'a@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($admin, $this->tenant, 'admin');
        $this->actingAs($admin);
        Filament::setTenant($this->tenant);

        $deadline = $this->vehicle->deadlines()->create([
            'tenant_id' => $this->tenant->id,
            'type' => Deadline::TYPE_REVISIONE,
            'due_date' => now()->addDays(5),
        ]);

        Livewire::test(ListDeadlines::class)
            ->callTableAction('rinnova', $deadline, data: [
                'amount' => 80,
                'paid_at' => now()->toDateString(),
                'due_date' => now()->addYears(2)->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $deadline->refresh();
        $this->assertSame(Deadline::STATUS_RINNOVATA, $deadline->status);
        $this->assertEquals(80, $deadline->amount);

        $this->assertSame(
            2,
            Deadline::where('type', Deadline::TYPE_REVISIONE)->where('deadlinable_id', $this->vehicle->id)->count()
        );
    }
}
