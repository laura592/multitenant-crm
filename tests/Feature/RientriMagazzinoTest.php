<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Il ritiro si fa con un DDT di ritiro, che dall'API non si legge: Eureka
 * continua a dare la macchina dal cliente di prima (23/09/2026, Hotel
 * Bellevue: otto macchine ritirate il 21/09 e ancora li'). La traccia
 * utilizzabile e' la riga DISIN/RITIRO nel rapportino.
 */
class RientriMagazzinoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $bellevue;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
        Mail::fake();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->bellevue = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Bellevue', 'gestionale_code' => 1234]);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
    }

    private function macchina(string $matricola, string $dal): MachineUnit
    {
        $m = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => $matricola, 'model_name' => 'FAEMA E98']);
        $m->moveTo($this->bellevue, placedAt: Carbon::parse($dal));

        return $m->fresh();
    }

    private function rapportinoConRitiro(MachineUnit $m, string $giorno, string $codice = 'DISIN/RITIRO'): ServiceReport
    {
        $rapportino = ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->bellevue->id,
            'technician_id' => $this->tecnico->id,
            'machine_unit_id' => $m->id,
            'source' => ServiceReport::SOURCE_MANUALE,
            'intervention_type' => 'riparazione',
            'intervention_date' => $giorno,
        ]);

        $materiale = Material::firstOrCreate(['code' => $codice], ['tenant_id' => $this->tenant->id, 'category' => 'varie', 'type' => 'altro']);

        ServiceReportMaterial::create([
            'service_report_id' => $rapportino->id,
            'material_id' => $materiale->id,
            'quantity' => 1,
        ]);

        return $rapportino;
    }

    public function test_la_macchina_ritirata_viene_proposta_per_il_magazzino(): void
    {
        $m = $this->macchina('18520', '2026-05-02');
        $rapportino = $this->rapportinoConRitiro($m, '2026-09-21');

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $m->refresh();
        $this->assertNull($m->spostamento_suggerito_customer_id, 'Il rientro non ha un cliente di destinazione.');
        $this->assertSame('2026-09-21', $m->spostamento_suggerito_il->toDateString());
        $this->assertStringContainsString('ritirata il 21/09/2026', $m->spostamento_suggerito_motivo);
        $this->assertStringContainsString($rapportino->number, $m->spostamento_suggerito_motivo);

        // Confermato: va in magazzino, e il periodo dal Bellevue si chiude
        // il giorno del ritiro, non oggi.
        $this->assertTrue($m->accettaSpostamento());
        $m->refresh();
        $this->assertNull($m->current_customer_id);
        $this->assertSame(MachineUnit::STATUS_IN_MAGAZZINO, $m->status);
        $this->assertNull($m->spostamento_suggerito_motivo);

        $periodo = $m->placements()->sole();
        $this->assertSame($this->bellevue->id, $periodo->customer_id);
        $this->assertSame('2026-09-21', $periodo->removed_at->toDateString());
    }

    public function test_il_ritiro_prima_della_consegna_attuale_non_si_ripropone(): void
    {
        // Ritirata a gennaio, riconsegnata a maggio: il posizionamento
        // aperto e' piu' recente del ritiro, non c'e' niente da proporre.
        $m = $this->macchina('031221', '2026-05-02');
        $this->rapportinoConRitiro($m, '2026-01-15');

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertNull($m->fresh()->spostamento_suggerito_motivo);
    }

    public function test_scartato_una_volta_non_torna(): void
    {
        $m = $this->macchina('18520', '2026-05-02');
        $this->rapportinoConRitiro($m, '2026-09-21');

        $this->artisan('gestionale:sync')->assertExitCode(0);
        $m->refresh()->scartaSpostamento();

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $m->refresh();
        $this->assertNull($m->spostamento_suggerito_motivo);
        $this->assertSame($this->bellevue->id, $m->current_customer_id, 'Resta dov\'era: la proposta era sbagliata.');
    }

    public function test_se_il_tecnico_ci_e_tornato_dopo_la_macchina_e_ancora_li(): void
    {
        // Ritirata a maggio, ma a giugno c'e' un altro intervento su quella
        // macchina, li': o e' tornata, o era il ritiro di un pezzo.
        $m = $this->macchina('1966737', '2026-01-10');
        $this->rapportinoConRitiro($m, '2026-05-20');

        ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->bellevue->id,
            'technician_id' => $this->tecnico->id,
            'machine_unit_id' => $m->id,
            'source' => ServiceReport::SOURCE_MANUALE,
            'intervention_type' => 'riparazione',
            'intervention_date' => '2026-06-04',
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertNull($m->fresh()->spostamento_suggerito_motivo);
    }

    public function test_un_articolo_qualsiasi_non_e_un_ritiro(): void
    {
        $m = $this->macchina('18520', '2026-05-02');
        $this->rapportinoConRitiro($m, '2026-09-21', 'GUARNIZIONE');

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertNull($m->fresh()->spostamento_suggerito_motivo);
    }
}
