<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Chi paga e' della macchina, non del cliente (23/09/2026): il comando lo
 * scrive sulla macchina invece di lasciarlo ereditare dall'anagrafica.
 * Bar Miki: per il bar paga Dersut, ma l'impianto spina e' suo.
 */
class PaganteEsplicitoMacchineTest extends TestCase
{
    use RefreshDatabase;

    public function test_gli_impianti_li_paga_il_locale_le_altre_restano_al_torrefattore(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $dersut = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Dersut Caffe', 'gestionale_code' => 580]);
        $bar = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Miki', 'gestionale_code' => 233, 'billing_customer_id' => $dersut->id]);
        $senzaPagante = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Solo', 'gestionale_code' => 900]);

        $macchina = fn (string $matricola, ?string $tipo, string $modello, Customer $presso) => tap(
            MachineUnit::create(['tenant_id' => $tenant->id, 'serial_number' => $matricola, 'model_name' => $modello, 'type' => $tipo]),
            fn (MachineUnit $m) => $m->moveTo($presso, placedAt: Carbon::parse('2026-01-10'))
        );

        $spina = $macchina('IMP-SPINA-022', MachineUnit::TYPE_COLONNA_SPINA, 'Impianto Spina (birra+vino)', $bar);
        $casetta = $macchina('CASETTA-ACQUA-1', null, 'Casetta dell\'acqua', $bar);
        $caffe = $macchina('V24003882', null, 'Dalla Corte', $bar);
        $fuori = $macchina('X-1', null, 'Faema E98', $senzaPagante);

        $this->artisan('macchine:pagante-esplicito', ['--dry-run' => true])
            ->expectsOutputToContain('IMP-SPINA-022')
            ->assertSuccessful();
        $this->assertNull($spina->fresh()->billing_customer_id, 'In prova non scrive.');

        $this->artisan('macchine:pagante-esplicito')
            ->expectsConfirmation('Scrivo il pagante su 3 macchine?', 'yes')
            ->assertSuccessful();

        $this->assertSame($bar->id, $spina->fresh()->billing_customer_id, 'L\'impianto lo paga il locale.');
        $this->assertSame($bar->id, $casetta->fresh()->billing_customer_id);
        $this->assertSame($dersut->id, $caffe->fresh()->billing_customer_id, 'La macchina da caffe\' resta al torrefattore.');
        $this->assertNull($fuori->fresh()->billing_customer_id, 'Senza pagante in anagrafica non si tocca niente.');

        // Anche sulla posizione, che e' dove vive lo storico.
        $this->assertSame($bar->id, $spina->placements()->whereNull('removed_at')->sole()->billing_customer_id);

        $this->artisan('macchine:pagante-esplicito')
            ->expectsOutputToContain('Nessuna macchina eredita')
            ->assertSuccessful();
    }
}
