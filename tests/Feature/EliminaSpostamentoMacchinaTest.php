<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource\Pages\ViewMachineUnit;
use App\Filament\Resources\MachineUnitResource\RelationManagers\PlacementsRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Macchine\EliminaPosizionamento;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Dal dettaglio della macchina si elimina uno spostamento qualsiasi dello
 * storico, non solo l'ultimo, e lo storico si ricuce da solo.
 */
class EliminaSpostamentoMacchinaTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private MachineUnit $macchina;

    private Customer $a;

    private Customer $b;

    private Customer $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $this->macchina = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'SN-1', 'model_name' => 'Faema E71']);
        [$this->a, $this->b, $this->c] = collect(['Bar A', 'Bar B', 'Bar C'])
            ->map(fn ($nome) => Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => $nome]))
            ->all();
    }

    /** A dal 01/01, B dal 01/03, C dal 01/06 (ancora li'). */
    private function storico(): void
    {
        $this->macchina->moveTo($this->a, placedAt: now()->setDate(2026, 1, 1));
        $this->macchina->moveTo($this->b, placedAt: now()->setDate(2026, 3, 1));
        $this->macchina->moveTo($this->c, placedAt: now()->setDate(2026, 6, 1));
    }

    private function riga(Customer $cliente)
    {
        return $this->macchina->placements()->where('customer_id', $cliente->id)->sole();
    }

    public function test_eliminando_una_riga_in_mezzo_la_precedente_copre_il_periodo(): void
    {
        $this->storico();

        EliminaPosizionamento::esegui($this->riga($this->b));

        $this->assertSame('2026-06-01', $this->riga($this->a)->removed_at->toDateString(), 'A resta fino all\'arrivo da C');
        $this->assertSame($this->c->id, $this->macchina->fresh()->current_customer_id);
        $this->assertSame(2, $this->macchina->placements()->count());
    }

    public function test_eliminando_la_posizione_attuale_la_macchina_torna_alla_precedente(): void
    {
        $this->storico();

        EliminaPosizionamento::esegui($this->riga($this->c));

        $this->assertNull($this->riga($this->b)->removed_at);
        $this->assertSame($this->b->id, $this->macchina->fresh()->current_customer_id);
    }

    public function test_se_prima_c_era_il_magazzino_resta_il_magazzino(): void
    {
        $this->macchina->moveTo($this->a, placedAt: now()->setDate(2026, 1, 1));
        $this->macchina->moveTo(null, placedAt: now()->setDate(2026, 2, 1));
        $this->macchina->moveTo($this->c, placedAt: now()->setDate(2026, 6, 1));

        EliminaPosizionamento::esegui($this->riga($this->c));

        $this->assertSame('2026-02-01', $this->riga($this->a)->removed_at->toDateString(), 'A non si riapre');
        $this->assertNull($this->macchina->fresh()->current_customer_id);
        $this->assertSame(MachineUnit::STATUS_IN_MAGAZZINO, $this->macchina->fresh()->status);
    }

    public function test_si_elimina_dal_dettaglio_della_macchina(): void
    {
        $this->storico();
        $utente = User::create(['tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => 'u@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($utente, $this->tenant, 'admin');
        $this->actingAs($utente);
        Filament::setTenant($this->tenant);

        Livewire::test(PlacementsRelationManager::class, ['ownerRecord' => $this->macchina, 'pageClass' => ViewMachineUnit::class])
            ->assertTableActionVisible('elimina', $this->riga($this->a))
            ->callTableAction('elimina', $this->riga($this->b));

        $this->assertSame(2, $this->macchina->placements()->count());
    }
}
