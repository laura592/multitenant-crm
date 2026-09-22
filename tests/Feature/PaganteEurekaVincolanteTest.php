<?php

namespace Tests\Feature;

use App\Filament\Widgets\Gestionale\GestionaleSchedeDaCorreggereWidget;
use App\Models\Customer;
use App\Models\EurekaFattura;
use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\ControlloPaganteFattura;
use App\Support\Gestionale\PaganteEureka;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Regola decisa con Laura il 22/09/2026: per un rapportino gia' nel
 * gestionale il pagante e' SEMPRE quello della scheda Eureka (destinazione,
 * o intestatario se vuota), copiato e mai deciso dal CRM. La fattura serve
 * solo a segnalare le schede da correggere su Eureka.
 */
class PaganteEurekaVincolanteTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private Customer $chiosco;

    private Customer $martellozzo;

    private Customer $dersut;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->chiosco = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Chiosco Mareluna', 'gestionale_code' => 2905]);
        $this->martellozzo = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Martellozzo Lorenzo & C. SAS', 'gestionale_code' => 50]);
        $this->dersut = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Dersut Caffe', 'gestionale_code' => 200]);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
    }

    private function nelGestionale(array $dati = []): ServiceReport
    {
        // Oggi la macchina risulta pagata da Dersut.
        $macchina = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'SN-'.uniqid(), 'model_name' => 'E71', 'billing_customer_id' => $this->dersut->id]);

        return ServiceReport::withoutEvents(fn () => tap((new ServiceReport)->forceFill([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->chiosco->id, 'technician_id' => $this->tecnico->id,
            'number' => 'RT-2023-0327', 'gestionale_number' => 'SL-346/2023', 'source' => ServiceReport::SOURCE_EUREKA,
            'status' => 'in_gestionale', 'eureka_service_report_id' => 3001, 'machine_unit_id' => $macchina->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2023-04-21', ...$dati,
        ]))->save());
    }

    private function schedaEureka(?array $destinazione): void
    {
        Http::fake(fn () => Http::response(['id_eureka' => 3001, 'id_intestatario' => 2905, 'destinazione' => $destinazione], 200));
    }

    /** FT 176/2023 intestata a $a nell'elenco fatture clienti. */
    private function fatturaA(Customer $a): array
    {
        EurekaFattura::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaFattura::TIPO_CLIENTE, 'id_eureka' => 3298, 'causale' => '101',
            'customer_id' => $a->id, 'ragione_sociale' => strtoupper($a->company_name), 'numero_doc' => '176', 'data_doc' => '2023-04-30',
        ]);

        return [['id_fattura' => 3298, 'tipo_doc' => 'FT', 'numero_fattura' => 176, 'data_fattura' => '2023-04-30T00:00:00.000+02:00']];
    }

    public function test_destinazione_scritta_solo_col_nome_e_il_pagante(): void
    {
        // SL-346/2023: destinazione con il nome ma id_eureka 0.
        $scheda = PaganteEureka::daScheda(
            ['id_intestatario' => 2905, 'destinazione' => ['id_eureka' => 0, 'rag_sociale' => "MARTELLOZZO LORENZO & C. SAS"]],
            [], $this->tenant->id, $this->chiosco->id,
        );

        $this->assertSame($this->martellozzo->id, $scheda['customer_id']);
        $this->assertTrue($scheda['trovato']);
    }

    public function test_destinazione_vuota_paga_l_intestatario_e_non_la_macchina(): void
    {
        $r = $this->nelGestionale();
        $this->schedaEureka(null);

        PaganteEureka::rileggiSchede(collect([$r]));

        $this->assertSame($this->chiosco->id, $r->fresh()->billing_customer_id);
    }

    public function test_un_pagante_sulla_scheda_che_nel_crm_non_esiste_non_si_inventa(): void
    {
        $r = $this->nelGestionale();
        $this->schedaEureka(['id_eureka' => 999, 'rag_sociale' => 'SCONOSCIUTO SRL']);

        $esito = PaganteEureka::rileggiSchede(collect([$r]));

        $this->assertCount(1, $esito['non_trovati']);
        $this->assertNull($r->fresh()->billing_customer_id);
    }

    public function test_la_fattura_non_cambia_il_pagante_segnala_la_scheda_da_correggere(): void
    {
        $r = $this->nelGestionale(['billing_customer_id' => $this->chiosco->id]);
        $r->registraFattureEureka($this->fatturaA($this->martellozzo));

        $this->assertSame($this->chiosco->id, $r->fresh()->billing_customer_id, 'il CRM non corregge dalla fattura');

        $this->assertSame(1, ControlloPaganteFattura::segnala($this->tenant));
        $this->assertSame($this->martellozzo->id, $r->fresh()->pagante_fattura_customer_id);
    }

    public function test_corretta_la_scheda_su_eureka_il_ricontrollo_allinea_e_la_segnalazione_sparisce(): void
    {
        $r = $this->nelGestionale(['billing_customer_id' => $this->chiosco->id]);
        $r->registraFattureEureka($this->fatturaA($this->martellozzo));
        ControlloPaganteFattura::segnala($this->tenant);

        // Su Eureka mettono Martellozzo come destinazione della scheda.
        $this->schedaEureka(['id_eureka' => 50, 'rag_sociale' => 'MARTELLOZZO LORENZO & C. SAS']);
        $esito = ControlloPaganteFattura::ricontrolla($this->tenant);

        $this->assertSame(['letti' => 1, 'sistemati' => 1, 'ancora' => 0], $esito);
        $r->refresh();
        $this->assertSame($this->martellozzo->id, $r->billing_customer_id);
        $this->assertNull($r->pagante_fattura_customer_id);
    }

    public function test_va_bene_cosi_non_si_segnala_piu(): void
    {
        $r = $this->nelGestionale(['billing_customer_id' => $this->chiosco->id]);
        $r->registraFattureEureka($this->fatturaA($this->martellozzo));
        ControlloPaganteFattura::segnala($this->tenant);

        ControlloPaganteFattura::vaBene($r->fresh());

        $this->assertSame(0, ControlloPaganteFattura::segnala($this->tenant));
        $this->assertSame($this->chiosco->id, $r->fresh()->billing_customer_id);
    }

    public function test_l_autofattura_di_un_fornitore_non_fa_scattare_il_controllo(): void
    {
        $r = $this->nelGestionale(['billing_customer_id' => $this->chiosco->id]);
        $fatture = $this->fatturaA($this->chiosco);
        // ZAF, fornitore delle pulizie: autofattura con lo stesso numero.
        $zaf = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'ZAF Servizi']);
        EurekaFattura::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaFattura::TIPO_CLIENTE, 'id_eureka' => 1, 'causale' => '112',
            'customer_id' => $zaf->id, 'ragione_sociale' => 'ZAF SERVIZI', 'numero_doc' => '176', 'data_doc' => '2023-05-02',
        ]);
        $r->registraFattureEureka($fatture);

        $this->assertSame(0, ControlloPaganteFattura::segnala($this->tenant));
    }

    public function test_il_comando_copia_il_pagante_delle_schede_solo_con_esegui(): void
    {
        $r = $this->nelGestionale();
        $this->schedaEureka(['id_eureka' => 0, 'rag_sociale' => 'MARTELLOZZO LORENZO & C. SAS']);

        $this->artisan('rapportini:pagante-da-eureka')
            ->expectsOutputToContain('Il pagante CAMBIA: 1 rapportini.')
            ->assertSuccessful();
        $this->assertNull($r->fresh()->billing_customer_id, 'senza --esegui non scrive');

        $this->artisan('rapportini:pagante-da-eureka --esegui')
            ->expectsConfirmation('Copiare nel CRM il pagante delle schede su 1 rapportini?', 'yes')
            ->assertSuccessful();

        $this->assertSame($this->martellozzo->id, $r->fresh()->billing_customer_id);
        $this->assertSame('MARTELLOZZO LORENZO & C. SAS', $r->fresh()->eureka_destinazione_label);
    }

    public function test_il_riquadro_elenca_la_scheda_senza_applica(): void
    {
        $r = $this->nelGestionale(['billing_customer_id' => $this->chiosco->id]);
        $r->registraFattureEureka($this->fatturaA($this->martellozzo));
        ControlloPaganteFattura::segnala($this->tenant);

        $this->giveRole($this->tecnico, $this->tenant, 'admin');
        $this->actingAs($this->tecnico);
        Filament::setTenant($this->tenant);

        Livewire::test(GestionaleSchedeDaCorreggereWidget::class)
            ->assertSee('SL-346/2023')
            ->assertSee('Martellozzo Lorenzo')
            ->assertTableActionExists('ricontrolla')
            ->assertTableActionExists('va_bene')
            ->assertTableActionDoesNotExist('applica');
    }

    public function test_l_export_contiene_solo_differenze_ed_errori(): void
    {
        $daCorreggere = $this->nelGestionale(['billing_customer_id' => $this->chiosco->id]);
        $daCorreggere->registraFattureEureka($this->fatturaA($this->martellozzo));
        $this->nelGestionale(['number' => 'RT-2023-0400', 'gestionale_number' => 'SL-400/2023', 'eureka_service_report_id' => 3002, 'eureka_fattura_motivo' => 'da_verificare']);
        $this->nelGestionale(['number' => 'RT-2023-0401', 'gestionale_number' => 'SL-401/2023', 'eureka_service_report_id' => 3003, 'eureka_fattura_motivo' => 'recente']);
        ControlloPaganteFattura::segnala($this->tenant);

        $righe = collect(iterator_to_array(ControlloPaganteFattura::righeEsportazione($this->tenant), false))->keyBy('Rapportino');

        $this->assertStringStartsWith('da correggere su Eureka', $righe['RT-2023-0327']['Problema']);
        $this->assertSame('Martellozzo Lorenzo & C. SAS', $righe['RT-2023-0327']['Fattura intestata a']);
        $this->assertSame('SL-346/2023', $righe['RT-2023-0327']['N. gestionale']);
        $this->assertSame('senza fattura: da verificare', $righe['RT-2023-0400']['Problema']);
        $this->assertFalse($righe->has('RT-2023-0401'), 'recente: la fattura deve ancora uscire, non e\' un errore');

        $this->giveRole($this->tecnico, $this->tenant, 'admin');
        $this->actingAs($this->tecnico);
        Filament::setTenant($this->tenant);

        Livewire::test(GestionaleSchedeDaCorreggereWidget::class)
            ->callTableAction('esporta_tutti')
            ->assertFileDownloaded('rapportini-differenze-errori-'.now()->format('Y-m-d').'.csv');
    }
}
