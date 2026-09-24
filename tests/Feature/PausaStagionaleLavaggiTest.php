<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Campeggi e chioschi chiudono a ottobre e riaprono a primavera: il piano
 * lavaggi continuava a scadere per tutto l'inverno, e a gennaio il
 * promemoria elencava locali chiusi (24/09/2026).
 */
class PausaStagionaleLavaggiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $camping;

    private MaintenanceSchedule $piano;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true, 'is_active' => true]);
        $this->camping = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Camping Garden Paradiso']);
        $impianto = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'IMP-SPINA-099', 'model_name' => 'Impianto Spina']);

        $this->piano = MaintenanceSchedule::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->camping->id,
            'machine_unit_id' => $impianto->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'status' => MaintenanceSchedule::STATUS_ATTIVO,
            'beverage_type' => 'birra',
            'lines_count' => 4,
            'frequency' => 'mensile',
        ]);
    }

    private function lavaggio(string $descrizione, string $data): Lavaggio
    {
        return Lavaggio::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->camping->id,
            'maintenance_schedule_id' => $this->piano->id,
            'data' => $data,
            'descrizione' => $descrizione,
            'lines_washed' => 4,
        ]);
    }

    public function test_la_chiusura_di_stagione_mette_in_pausa_il_piano(): void
    {
        $this->lavaggio('4 Vie + Chiusura', '2026-10-05');

        $piano = $this->piano->fresh();

        $this->assertTrue($piano->in_pausa);
        $this->assertTrue($piano->inPausa());
        $this->assertSame('Chiusura stagionale del 05/10/2026', $piano->pausa_motivo);
        $this->assertSame(MaintenanceSchedule::STATUS_ATTIVO, $piano->status, 'In pausa non vuol dire chiuso: la storia resta.');
    }

    public function test_l_apertura_lo_rimette_in_moto(): void
    {
        $this->lavaggio('4 Vie + Chiusura', '2026-10-05');
        $this->lavaggio('4 Vie + Apertura', '2027-04-02');

        $piano = $this->piano->fresh();

        $this->assertFalse($piano->in_pausa);
        $this->assertNull($piano->pausa_motivo);
    }

    public function test_un_lavaggio_normale_non_tocca_la_pausa(): void
    {
        $this->lavaggio('4 Vie', '2026-07-05');

        $this->assertFalse($this->piano->fresh()->in_pausa);
    }

    public function test_in_pausa_non_arriva_il_promemoria(): void
    {
        $this->piano->update(['next_due_date' => now()->addDays(3)]);

        $trovati = fn () => MaintenanceSchedule::query()
            ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->inCorso()
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', now()->addDays(7))
            ->count();

        $this->assertSame(1, $trovati(), 'Prima della pausa il piano scade e va ricordato.');

        $this->piano->mettiInPausa(null, 'Chiusura stagionale');
        $this->assertSame(0, $trovati(), 'In pausa non deve comparire.');

        // Con una data, il giorno della riapertura torna da solo.
        $this->piano->mettiInPausa(Carbon::yesterday(), 'Chiusura stagionale');
        $this->assertSame(1, $trovati(), 'Passata la data, il piano riprende senza che nessuno lo tocchi.');
        $this->assertFalse($this->piano->fresh()->inPausa());
    }

    public function test_il_promemoria_salta_i_piani_in_pausa(): void
    {
        User::create(['tenant_id' => $this->tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
        $this->piano->update(['next_due_date' => now()->addDays(2)]);
        $this->piano->mettiInPausa(null, 'Chiusura stagionale');

        $this->artisan('lavaggi:send-reminders')->assertSuccessful();

        Mail::assertNothingOutgoing();
    }
}
