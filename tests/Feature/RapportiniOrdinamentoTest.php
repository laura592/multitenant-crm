<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * L'elenco dei rapportini si ordina anche per numero.
 *
 * I due numeri hanno forme diverse e vanno ordinati in modi diversi, e questo
 * test fissa la differenza: sbagliarla non da' errore, da' un elenco in ordine
 * sbagliato, che e' molto piu' difficile da notare.
 */
class RapportiniOrdinamentoTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /**
     * Il numero del CRM e' RT-AAAA-NNNN, dodici caratteri con anno e
     * progressivo a lunghezza fissa: l'ordine alfabetico coincide con quello
     * di emissione, quindi la colonna nuda basta.
     */
    public function test_il_numero_del_crm_si_ordina_come_e_stato_emesso(): void
    {
        $this->rapportini([
            ['RT-2026-0009', null],
            ['RT-2026-1000', null],
            ['RT-2026-0999', null],
            ['RT-2025-0500', null],
        ]);

        $this->assertSame(
            ['RT-2025-0500', 'RT-2026-0009', 'RT-2026-0999', 'RT-2026-1000'],
            Livewire::test(ListServiceReports::class)
                ->sortTable('number')
                ->instance()->getTableRecords()->pluck('number')->all(),
        );
    }

    /**
     * Il numero di Eureka invece NON e' a lunghezza fissa: alfabeticamente la
     * scheda 1000 verrebbe prima della 999. Le schede che non ne hanno uno
     * vanno in fondo e non ammucchiate in cima, com'e' l'abitudine di NULL.
     */
    public function test_il_numero_del_gestionale_si_ordina_per_valore(): void
    {
        $this->rapportini([
            ['RT-2026-0001', '9'],
            ['RT-2026-0002', '1000'],
            ['RT-2026-0003', '999'],
            ['RT-2026-0004', null],
        ]);

        $this->assertSame(
            ['9', '999', '1000', null],
            Livewire::test(ListServiceReports::class)
                ->sortTable('gestionale_number')
                ->instance()->getTableRecords()->pluck('gestionale_number')->all(),
        );
    }

    /** @param  array<int, array{0: string, 1: ?string}>  $righe */
    private function rapportini(array $righe): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($utente, $tenant, 'admin');
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Hotel Neps']);

        foreach ($righe as [$numero, $gestionale]) {
            ServiceReport::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $cliente->id,
                'technician_id' => $utente->id,
                'number' => $numero,
                'gestionale_number' => $gestionale,
                'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
                'intervention_date' => '2026-05-01',
            ]);
        }

        $this->actingAs($utente);
        Filament::setTenant($tenant);
    }
}
