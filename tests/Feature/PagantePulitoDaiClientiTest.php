<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il pagante non sta sull'anagrafica del cliente: sta sulla macchina.
 *
 * L'anagrafica di Eureka ha nove campi e nessuno dice chi paga. Il dato
 * esiste solo sulla singola scheda (destinazione) e sulla singola macchina
 * (id_intestatario_fattura_f15): solo il secondo e' anagrafica. Promuoverlo a
 * regola del cliente aveva fatto dire al CRM che per "Bar Nostro" pagava
 * Illy, sulla base di UN intervento del 12/02/2023 — 51 clienti su 199 si
 * reggevano cosi' (03/09/2026).
 *
 * E' anche piu' giusto nel merito: il torrefattore paga per la macchina che
 * ha dato in comodato, non per tutto quello che si fa da quel cliente.
 */
class PagantePulitoDaiClientiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tiene_il_pagante_che_le_macchine_confermano(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $torrefattore = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Illy Caffe SPA']);

        // Confermato: la macchina di questo cliente dice lo stesso pagante.
        $confermato = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Confermato',
            'billing_customer_id' => $torrefattore->id,
        ]);
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $confermato->id,
            'serial_number' => 'AAA-1', 'billing_customer_id' => $torrefattore->id,
        ]);

        // Non confermato: la macchina c'e' ma non dice chi paga. E' il caso
        // di "Bar Nostro", dove l'Illy veniva da una scheda del 2023.
        $debole = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Nostro',
            'billing_customer_id' => $torrefattore->id,
        ]);
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $debole->id, 'serial_number' => 'BBB-2',
        ]);

        $this->artisan('clienti:pulisci-pagante', ['--tenant' => 'alex', '--force' => true])
            ->assertSuccessful();

        $this->assertSame($torrefattore->id, $confermato->fresh()->billing_customer_id, 'confermato dalle macchine: si tiene');
        $this->assertNull($debole->fresh()->billing_customer_id, 'non confermato: si toglie');
    }

    /** Con --tutti cade anche quello che le macchine confermano. */
    public function test_con_tutti_toglie_anche_i_confermati(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $torrefattore = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Illy Caffe SPA']);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Confermato',
            'billing_customer_id' => $torrefattore->id,
        ]);
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $cliente->id,
            'serial_number' => 'AAA-1', 'billing_customer_id' => $torrefattore->id,
        ]);

        $this->artisan('clienti:pulisci-pagante', ['--tenant' => 'alex', '--tutti' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertNull($cliente->fresh()->billing_customer_id);
    }

    /** In prova a vuoto non tocca niente. */
    public function test_in_prova_a_vuoto_non_scrive(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $pagante = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Illy Caffe SPA']);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Nostro',
            'billing_customer_id' => $pagante->id,
        ]);

        $this->artisan('clienti:pulisci-pagante', ['--tenant' => 'alex', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame($pagante->id, $cliente->fresh()->billing_customer_id);
    }
}
