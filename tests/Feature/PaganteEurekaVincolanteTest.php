<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EurekaFattura;
use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Se sono nel gestionale deve essere vincolante" (22/09/2026): chi paga un
 * rapportino nel gestionale e' chi ha ricevuto la fattura su Eureka, non il
 * pagante di oggi della macchina.
 */
class PaganteEurekaVincolanteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    private Customer $dersut;

    private Customer $zaf;

    private User $tecnico;

    private MachineUnit $macchina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Principe', 'gestionale_code' => 100]);
        $this->dersut = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Dersut Caffe', 'gestionale_code' => 200]);
        $this->zaf = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'ZAF Servizi', 'gestionale_code' => 300]);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
        // Oggi la macchina risulta pagata da Dersut.
        $this->macchina = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'SN', 'model_name' => 'E71', 'billing_customer_id' => $this->dersut->id]);
    }

    private function importato(array $dati = []): ServiceReport
    {
        // forceFill: eureka_fatture non e' fillable (lo scrive solo
        // registraFattureEureka).
        return ServiceReport::withoutEvents(fn () => tap((new ServiceReport)->forceFill([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id, 'technician_id' => $this->tecnico->id,
            'number' => 'RT-2025-0765', 'source' => ServiceReport::SOURCE_EUREKA, 'status' => 'in_gestionale',
            'eureka_service_report_id' => 555, 'machine_unit_id' => $this->macchina->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2025-09-19', ...$dati,
        ]))->save());
    }

    /** La fattura 403/2025 nell'elenco fatture clienti, intestata a $a. */
    private function fattura(Customer $a): array
    {
        EurekaFattura::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaFattura::TIPO_CLIENTE, 'id_eureka' => 99001,
            'customer_id' => $a->id, 'ragione_sociale' => strtoupper($a->company_name),
            'numero_doc' => '403', 'data_doc' => '2025-09-26',
        ]);

        return [['id_fattura' => 13680, 'tipo_doc' => 'FT', 'numero_fattura' => 403, 'data_fattura' => '2025-09-26T00:00:00.000+02:00', 'has_fe' => 1]];
    }

    public function test_quando_arriva_la_fattura_il_pagante_e_chi_l_ha_ricevuta(): void
    {
        $r = $this->importato();
        $this->assertSame($this->dersut->id, $r->invoiceRecipient()->id, 'prima: dalla macchina');

        $r->registraFattureEureka($this->fattura($this->cliente));

        $this->assertSame($this->cliente->id, $r->fresh()->billing_customer_id);
        $this->assertSame($this->cliente->id, $r->fresh()->invoiceRecipient()->id);
    }

    public function test_la_fattura_vince_anche_su_un_pagante_gia_fissato(): void
    {
        $r = $this->importato(['billing_customer_id' => $this->dersut->id]);

        $r->registraFattureEureka($this->fattura($this->zaf));

        $this->assertSame($this->zaf->id, $r->fresh()->billing_customer_id);
    }

    public function test_stesso_numero_in_due_serie_la_fattura_e_ambigua_e_non_si_decide(): void
    {
        $fatture = $this->fattura($this->cliente);
        // Stesso numero, stesso anno, altra serie (causale 112), altro cliente.
        EurekaFattura::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaFattura::TIPO_CLIENTE, 'id_eureka' => 99002,
            'customer_id' => $this->zaf->id, 'ragione_sociale' => 'ZAF SERVIZI', 'numero_doc' => '403', 'data_doc' => '2025-10-02',
        ]);
        $r = $this->importato();

        $r->registraFattureEureka($fatture);

        $this->assertNull($r->fresh()->billing_customer_id);
        $this->artisan('rapportini:pagante-da-eureka')->expectsOutputToContain('Fattura ambigua');
    }

    public function test_l_autofattura_di_un_fornitore_con_lo_stesso_numero_non_conta(): void
    {
        $fatture = $this->fattura($this->cliente);
        // ZAF e' il fornitore delle pulizie: la sua autofattura (causale 112)
        // ha una numerazione sua e lo stesso numero della fattura al cliente.
        EurekaFattura::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaFattura::TIPO_CLIENTE, 'id_eureka' => 99003, 'causale' => '112',
            'customer_id' => $this->zaf->id, 'ragione_sociale' => 'ZAF SERVIZI', 'numero_doc' => '403', 'data_doc' => '2025-10-02',
        ]);
        $r = $this->importato();

        $r->registraFattureEureka($fatture);

        $this->assertSame($this->cliente->id, $r->fresh()->billing_customer_id);
    }

    public function test_senza_il_cliente_nel_crm_non_si_congela_un_pagante_indovinato(): void
    {
        $r = $this->importato(['eureka_destinazione_code' => 999]);

        $r->freezeInvoiceRecipient();

        $this->assertNull($r->fresh()->billing_customer_id);
    }

    public function test_il_comando_mostra_e_con_esegui_scrive_l_intestatario_della_fattura(): void
    {
        $fatturato = $this->importato(['eureka_fatture' => $this->fattura($this->cliente)]);
        $nonFatturato = $this->importato(['number' => 'RT-2025-0766', 'eureka_service_report_id' => 556]);

        $this->artisan('rapportini:pagante-da-eureka')
            ->expectsOutputToContain('Senza fattura (o fattura non ancora nel CRM): 1')
            ->expectsOutputToContain('Il pagante CAMBIA: 1 rapportini.')
            ->assertSuccessful();
        $this->assertNull($fatturato->fresh()->billing_customer_id, 'senza --esegui non scrive');

        $this->artisan('rapportini:pagante-da-eureka --esegui')
            ->expectsConfirmation("Scrivere come pagante l'intestatario della fattura su 1 rapportini?", 'yes')
            ->assertSuccessful();

        $this->assertSame($this->cliente->id, $fatturato->fresh()->billing_customer_id);
        $this->assertNull($nonFatturato->fresh()->billing_customer_id, 'senza fattura non si indovina');
    }

    public function test_con_tutti_corregge_un_pagante_fissato_diverso_dalla_fattura(): void
    {
        $r = $this->importato(['billing_customer_id' => $this->dersut->id, 'eureka_fatture' => $this->fattura($this->zaf)]);

        $this->artisan('rapportini:pagante-da-eureka')->expectsOutputToContain('Rapportini nel gestionale da controllare: 0');

        $this->artisan('rapportini:pagante-da-eureka --tutti --esegui')
            ->expectsConfirmation("Scrivere come pagante l'intestatario della fattura su 1 rapportini?", 'yes')
            ->assertSuccessful();

        $this->assertSame($this->zaf->id, $r->fresh()->billing_customer_id);
    }
}
