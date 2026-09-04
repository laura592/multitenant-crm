<?php

namespace Tests\Feature;

use App\Filament\Pages\DettaglioPagante;
use App\Filament\Pages\PagantiMacchine;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Chi paga per chi: i torrefattori e le macchine che si accollano.
 *
 * Serve a mandare a Martellozzo l'elenco di quello per cui gli si fattura —
 * 29 clienti e 33 macchine — invece di ricostruirlo cliente per cliente, e a
 * vedere gli errori: e' guardando questa lista che si scopre che due sedi
 * vicine hanno i paganti incrociati (richiesta dell'ufficio, 04/09/2026).
 *
 * Si legge dalle MACCHINE, non dai clienti: li' il pagante viene dagli
 * installati di Eureka.
 */
class ChiPagaPerChiTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'U', 'email' => 'u@alex.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $tenant, 'admin');

        $torrefattore = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Martellozzo Lorenzo & C. SAS']);
        $altro = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Dersut Caffe SPA']);

        $bar = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Chiosco Soleado Beach']);
        $pub = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Byron SRL']);

        // Martellozzo: due macchine dal chiosco, una dal pub.
        foreach ([[$bar, 'IMP-ACQUA-005'], [$bar, 'IMP-SPINA-009'], [$pub, 'IMP-SPINA-003']] as [$dove, $matricola]) {
            MachineUnit::create([
                'tenant_id' => $tenant->id, 'current_customer_id' => $dove->id,
                'serial_number' => $matricola, 'billing_customer_id' => $torrefattore->id,
            ]);
        }
        // Dersut: una sola, e non deve finire nel conto di Martellozzo.
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $bar->id,
            'serial_number' => 'L23004257', 'billing_customer_id' => $altro->id,
        ]);
        // Del cliente: senza pagante, fuori da entrambi.
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $bar->id, 'serial_number' => 'FORNO-1',
        ]);

        $this->actingAs($utente);
        Filament::setTenant($tenant);

        return [$tenant, $torrefattore, $altro];
    }

    public function test_elenca_i_paganti_con_clienti_e_macchine(): void
    {
        [, $torrefattore, $altro] = $this->scenario();

        $righe = Livewire::test(PagantiMacchine::class)
            ->assertOk()
            ->instance()->getTable()->getRecords();

        $martellozzo = $righe->firstWhere('id', $torrefattore->id);

        $this->assertSame(3, (int) $martellozzo->macchine_count);
        // Due macchine stanno dallo stesso cliente: i clienti sono due, non tre.
        $this->assertSame(2, (int) $martellozzo->clienti_count);

        $this->assertSame(1, (int) $righe->firstWhere('id', $altro->id)->macchine_count);
    }

    /** Chi non paga per nessuno non compare. */
    public function test_i_clienti_normali_non_compaiono_fra_i_paganti(): void
    {
        [$tenant] = $this->scenario();
        $normale = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Qualunque']);

        $righe = Livewire::test(PagantiMacchine::class)->instance()->getTable()->getRecords();

        $this->assertNull($righe->firstWhere('id', $normale->id));
    }

    public function test_il_dettaglio_mostra_solo_le_macchine_di_quel_pagante(): void
    {
        [, $torrefattore] = $this->scenario();

        $matricole = Livewire::test(DettaglioPagante::class, ['pagante' => $torrefattore->getKey()])
            ->assertOk()
            ->instance()->getTable()->getRecords()->pluck('serial_number')->sort()->values()->all();

        $this->assertSame(['IMP-ACQUA-005', 'IMP-SPINA-003', 'IMP-SPINA-009'], $matricole);
    }

    public function test_la_stampa_restituisce_il_pdf(): void
    {
        [$tenant, $torrefattore] = $this->scenario();

        $risposta = $this->get(route('paganti.stampa', [
            'pagante' => $torrefattore->getKey(), 'tenant' => $tenant->id,
        ]));

        $risposta->assertOk();
        $this->assertStringContainsString('macchine-pagate-', $risposta->headers->get('content-disposition'));
    }

    /** Un pagante di un altro tenant non si stampa. */
    public function test_non_si_stampa_il_pagante_di_un_altro_tenant(): void
    {
        [, $torrefattore] = $this->scenario();
        $altroTenant = Tenant::create(['name' => 'Altro', 'slug' => 'altro']);

        $this->get(route('paganti.stampa', [
            'pagante' => $torrefattore->getKey(), 'tenant' => $altroTenant->id,
        ]))->assertForbidden();
    }
}
