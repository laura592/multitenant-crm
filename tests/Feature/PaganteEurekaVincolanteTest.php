<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Se sono nel gestionale deve essere vincolante" (22/09/2026): il pagante
 * di un rapportino importato e' quello della scheda Eureka, e cambiare oggi
 * il pagante della macchina non lo tocca.
 */
class PaganteEurekaVincolanteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    private Customer $gestore;

    private Customer $nuovoGestore;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Sole', 'gestionale_code' => 100]);
        $this->gestore = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Dersut Caffe', 'gestionale_code' => 200]);
        $this->nuovoGestore = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Altro Torrefattore', 'gestionale_code' => 300]);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
    }

    private function importato(array $dati = []): ServiceReport
    {
        return ServiceReport::withoutEvents(fn () => ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id, 'technician_id' => $this->tecnico->id,
            'number' => 'RT-2024-0001', 'source' => ServiceReport::SOURCE_EUREKA, 'status' => 'in_gestionale', 'eureka_service_report_id' => 555,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2024-05-10', ...$dati,
        ]));
    }

    private function eurekaDice(?int $destinazione): void
    {
        Http::fake(fn () => Http::response([
            'id_eureka' => 555,
            'id_intestatario' => 100,
            'destinazione' => $destinazione ? ['id_eureka' => $destinazione, 'rag_sociale' => 'DERSUT CAFFE'] : null,
        ], 200));
    }

    public function test_il_pagante_della_macchina_cambiato_oggi_non_tocca_una_scheda_nel_gestionale(): void
    {
        $macchina = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'SN', 'model_name' => 'E71', 'billing_customer_id' => $this->nuovoGestore->id]);
        $r = $this->importato(['machine_unit_id' => $macchina->id, 'eureka_destinazione_code' => 200]);

        $this->assertSame($this->gestore->id, $r->invoiceRecipient()->id, 'vale la destinazione di Eureka');

        $r->freezeInvoiceRecipient();
        $this->assertSame($this->gestore->id, $r->fresh()->billing_customer_id);
    }

    public function test_senza_il_cliente_nel_crm_non_si_congela_un_pagante_indovinato(): void
    {
        $macchina = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'SN', 'model_name' => 'E71', 'billing_customer_id' => $this->nuovoGestore->id]);
        $r = $this->importato(['machine_unit_id' => $macchina->id, 'eureka_destinazione_code' => 999]);

        $r->freezeInvoiceRecipient();

        $this->assertNull($r->fresh()->billing_customer_id);
    }

    public function test_il_comando_mostra_e_con_esegui_scrive_il_pagante_di_eureka(): void
    {
        $r = $this->importato();
        $this->eurekaDice(200);

        $this->artisan('rapportini:pagante-da-eureka')->assertSuccessful();
        $this->assertNull($r->fresh()->billing_customer_id, 'senza --esegui non scrive');

        $this->artisan('rapportini:pagante-da-eureka --esegui')
            ->expectsConfirmation('Scrivere il pagante di Eureka su 1 rapportini?', 'yes')
            ->assertSuccessful();

        $r->refresh();
        $this->assertSame($this->gestore->id, $r->billing_customer_id);
        $this->assertSame(200, (int) $r->eureka_destinazione_code);
    }

    public function test_senza_destinazione_paga_l_intestatario(): void
    {
        $r = $this->importato();
        $this->eurekaDice(null);

        $this->artisan('rapportini:pagante-da-eureka --esegui')
            ->expectsConfirmation('Scrivere il pagante di Eureka su 1 rapportini?', 'yes')
            ->assertSuccessful();

        $this->assertSame($this->cliente->id, $r->fresh()->billing_customer_id);
    }

    public function test_il_comando_con_tutti_corregge_un_pagante_congelato_diverso_da_eureka(): void
    {
        $r = $this->importato(['billing_customer_id' => $this->nuovoGestore->id]);
        $this->eurekaDice(200);

        $this->artisan('rapportini:pagante-da-eureka')->expectsOutputToContain('Rapportini da controllare su Eureka: 0');

        $this->artisan('rapportini:pagante-da-eureka --tutti --esegui')
            ->expectsConfirmation('Scrivere il pagante di Eureka su 1 rapportini?', 'yes')
            ->assertSuccessful();

        $this->assertSame($this->gestore->id, $r->fresh()->billing_customer_id);
    }
}
