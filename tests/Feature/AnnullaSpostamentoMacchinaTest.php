<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Annulla ultimo spostamento" (MachineUnit::undoLastMove()): uno "Sposta"
 * fatto per sbaglio rimette la macchina dov'era, senza lasciare nello
 * storico un passaggio mai avvenuto.
 */
class AnnullaSpostamentoMacchinaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
    }

    private function cliente(string $nome, ?string $paese = null): Customer
    {
        return Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => $nome, 'city' => $paese]);
    }

    private function macchina(): MachineUnit
    {
        return MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'SN-'.uniqid(), 'model_name' => 'Faema E71']);
    }

    public function test_torna_dal_cliente_di_prima(): void
    {
        $macchina = $this->macchina();
        $bar = $this->cliente('Bar Roma');
        $hotel = $this->cliente('Hotel Mare');

        $macchina->moveTo($bar, placedAt: now()->subDays(3));
        $this->travel(1)->minutes();
        $macchina->moveTo($hotel);

        $this->assertTrue($macchina->canUndoLastMove());
        $macchina->undoLastMove();
        $macchina->refresh();

        $this->assertSame($bar->id, $macchina->current_customer_id);
        $this->assertSame(1, $macchina->placements()->count(), 'la riga sbagliata sparisce dallo storico');
        $this->assertNull($macchina->placements()->sole()->removed_at);
    }

    public function test_se_veniva_dal_magazzino_torna_in_magazzino(): void
    {
        $macchina = $this->macchina();
        $bar = $this->cliente('Bar Roma');
        $hotel = $this->cliente('Hotel Mare');

        $macchina->moveTo($bar);
        $this->travel(1)->hours();
        $macchina->moveTo(null);
        $this->travel(1)->hours();
        $macchina->moveTo($hotel);

        $macchina->undoLastMove();
        $macchina->refresh();

        $this->assertNull($macchina->current_customer_id, 'non riapre il cliente di prima del magazzino');
        $this->assertSame(MachineUnit::STATUS_IN_MAGAZZINO, $macchina->status);
        $this->assertNotNull($macchina->placements()->sole()->removed_at);
    }

    public function test_un_rientro_in_magazzino_sbagliato_si_annulla(): void
    {
        $macchina = $this->macchina();
        $bar = $this->cliente('Bar Roma');

        $macchina->moveTo($bar);
        $this->travel(1)->minutes();
        $macchina->moveTo(null);

        $macchina->undoLastMove();

        $this->assertSame($bar->id, $macchina->fresh()->current_customer_id);
    }

    public function test_uno_spostamento_vecchio_non_si_annulla(): void
    {
        $macchina = $this->macchina();
        $macchina->moveTo($this->cliente('Bar Roma'), placedAt: now()->subMonths(3));
        $this->travelTo(now()->addMonths(2));

        $this->assertFalse($macchina->canUndoLastMove());
    }

    public function test_il_cliente_si_sceglie_col_paese_fra_parentesi(): void
    {
        $this->assertSame('Bar Roma (Treviso)', DisplayName::customerOption($this->cliente('BAR ROMA', 'Treviso')));
    }
}
