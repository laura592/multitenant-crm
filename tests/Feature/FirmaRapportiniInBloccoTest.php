<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\FirmaRapportini;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\Concerns\CompilaRapportini;
use Tests\TestCase;

/**
 * Il tecnico fa piu' rapportini dallo stesso cliente senza farli firmare
 * uno per uno, poi il cliente firma una volta sola per tutti.
 */
class FirmaRapportiniInBloccoTest extends TestCase
{
    use AssignsPermissionRoles, CompilaRapportini, RefreshDatabase;

    /** PNG 1x1 valido. */
    private const FIRMA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    private User $tecnico;

    private Customer $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // La firma sta sul disco privato, non piu' su "public": da /storage
        // si apriva senza login (vedi App\Support\Rapportini\FirmaCliente).
        Storage::fake('local');

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->tecnico, $this->tenant, 'admin');
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Mare', 'city' => 'Jesolo']);

        $this->actingAs($this->tecnico);
        Filament::setTenant($this->tenant);
    }

    private function rapportino(array $dati = []): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id, 'technician_id' => $this->tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => today(),
            'work_performed' => 'Cambio guarnizioni', ...$dati,
        ]);
    }

    public function test_una_firma_vale_per_tutti_i_rapportini_scelti(): void
    {
        $primo = $this->rapportino();
        $secondo = $this->rapportino(['work_performed' => 'Pulizia gruppi']);
        $altroGiorno = $this->rapportino(['intervention_date' => today()->subDays(3)]);

        Livewire::withQueryParams(['cliente' => $this->cliente->id])
            ->test(FirmaRapportini::class)
            ->assertSee('Cambio guarnizioni')
            ->assertSee('Pulizia gruppi')
            // Senza scelta esplicita partono spuntati quelli di oggi.
            ->assertSet('data.rapportini', fn ($scelti) => collect($scelti)->sort()->values()->all() === collect([$primo->id, $secondo->id])->sort()->values()->all())
            ->set('data.customer_signature_name', 'MARIO ROSSI')
            ->set('data.customer_signature_path', self::FIRMA)
            ->call('firma')
            ->assertHasNoFormErrors();

        foreach ([$primo, $secondo] as $r) {
            $r->refresh();
            $this->assertSame('MARIO ROSSI', $r->customer_signature_name);
            $this->assertNotNull($r->customer_signature_path);
            $this->assertNotNull($r->signed_at);
        }
        $this->assertSame($primo->customer_signature_path, $secondo->customer_signature_path, 'e\' la stessa firma');
        Storage::disk('local')->assertExists($primo->customer_signature_path);

        $this->assertNull($altroGiorno->fresh()->customer_signature_path, 'non era tra quelli scelti');
    }

    public function test_senza_firma_non_si_firma_niente(): void
    {
        $r = $this->rapportino();

        Livewire::withQueryParams(['cliente' => $this->cliente->id])
            ->test(FirmaRapportini::class)
            ->set('data.customer_signature_name', 'MARIO ROSSI')
            ->call('firma')
            ->assertHasFormErrors(['customer_signature_path' => 'required']);

        $this->assertNull($r->fresh()->signed_at);
    }

    public function test_un_rapportino_gia_su_eureka_non_si_firma_da_qui(): void
    {
        $this->rapportino(['number' => 'RT-EUREKA', 'source' => ServiceReport::SOURCE_EUREKA]);
        $nostro = $this->rapportino();

        Livewire::withQueryParams(['cliente' => $this->cliente->id])
            ->test(FirmaRapportini::class)
            ->assertDontSee('RT-EUREKA')
            ->assertSee($nostro->number);
    }

    /**
     * Il cliente non c'e' a fine lavoro: il rapportino a passi si salva
     * senza firma, e "Fai firmare" poi propone tutti quelli della visita.
     */
    public function test_senza_firma_si_salva_e_fai_firmare_propone_tutta_la_visita(): void
    {
        $prima = \App\Models\MachineUnit::create(['tenant_id' => $this->tenant->id, 'current_customer_id' => $this->cliente->id, 'serial_number' => 'SN-1', 'model_name' => 'E71']);
        $seconda = \App\Models\MachineUnit::create(['tenant_id' => $this->tenant->id, 'current_customer_id' => $this->cliente->id, 'serial_number' => 'SN-2', 'model_name' => 'E71']);

        $pagina = $this->nuovoRapportino([
            'customer_id' => $this->cliente->id,
            'technician_id' => $this->tecnico->id,
            'intervention_date' => today()->toDateString(),
            'machine_unit_id' => $prima->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'work_performed' => 'Prima macchina',
        ]);
        $this->compilaRapportino($pagina, [
            'machine_unit_id' => $seconda->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'work_performed' => 'Seconda macchina',
        ]);
        $pagina->call('salva')->assertHasNoFormErrors();

        $rapportini = ServiceReport::orderBy('number')->get();
        $this->assertCount(2, $rapportini);
        $this->assertTrue($rapportini->every(fn (ServiceReport $r) => $r->customer_signature_path === null));
        $this->assertSame($rapportini[0]->visita_id, $rapportini[1]->visita_id);

        Livewire::withQueryParams(['cliente' => $this->cliente->id])
            ->test(FirmaRapportini::class)
            ->assertSet('data.rapportini', fn ($scelti) => collect($scelti)->sort()->values()->all() === $rapportini->pluck('id')->sort()->values()->all());
    }

    public function test_la_firma_dal_modulo_segna_la_data_della_firma(): void
    {
        $r = $this->rapportino();
        $this->assertNull($r->signed_at);

        $r->update(['customer_signature_path' => 'signatures/x.png']);
        $this->assertNotNull($r->fresh()->signed_at, 'prima il PDF non mostrava mai "Firmato il"');

        $r->update(['customer_signature_path' => null]);
        $this->assertNull($r->fresh()->signed_at);
    }
}
