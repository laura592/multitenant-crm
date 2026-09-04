<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Pdf\SchedaAnagraficaData;
use App\Support\Pdf\SchedaAnagraficaPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La scheda anagrafica precompilata che il cliente riceve, controlla e
 * rimanda firmata (vedi App\Support\Pdf\SchedaAnagraficaPdf).
 */
class SchedaAnagraficaTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
    }

    private function cliente(array $attributi = []): Customer
    {
        return Customer::create($attributi + [
            'tenant_id' => $this->tenant->id,
            'company_name' => 'Bar Centrale SRL',
        ]);
    }

    public function test_l_anagrafica_del_cliente_finisce_nei_campi_della_sezione_a(): void
    {
        $cliente = $this->cliente([
            'company_name' => 'BAR CENTRALE SRL',
            'street' => 'Via Roma, 12',
            'postal_code' => '30016',
            'city' => 'JESOLO',
            'province' => 'VE',
            'vat_number' => '04412140271',
            'tax_code' => '04412140271',
            'sdi' => 'EUVZNZV',
            'pec' => 'barcentrale@pec.it',
            'emails' => ['info@barcentrale.it'],
            'phones' => ['0421 123456'],
            'gestionale_code' => '3068',
        ]);

        $valori = SchedaAnagraficaData::for($cliente);

        $this->assertSame('BAR CENTRALE SRL', $valori['fatt_ragione_sociale']);
        $this->assertSame('Via Roma, 12', $valori['fatt_via']);
        $this->assertSame('30016', $valori['fatt_cap']);
        $this->assertSame('Jesolo', $valori['fatt_citta']);
        $this->assertSame('VE', $valori['fatt_prov']);
        $this->assertSame('04412140271', $valori['fatt_piva']);
        $this->assertSame('EUVZNZV', $valori['fatt_sdi']);
        $this->assertSame('barcentrale@pec.it', $valori['fatt_pec']);
        $this->assertSame('info@barcentrale.it', $valori['fatt_email']);
        $this->assertSame('3068', $valori['int_codice_cliente']);
    }

    public function test_un_pagante_diverso_riempie_la_sezione_b(): void
    {
        $gestore = $this->cliente(['company_name' => 'Gestione Chioschi SRL', 'vat_number' => '11111111111']);
        $chiosco = $this->cliente(['company_name' => 'Chiosco Piazza Duomo', 'billing_customer_id' => $gestore->id]);

        $valori = SchedaAnagraficaData::for($chiosco);

        $this->assertSame('terzo', $valori['pagante_tipo']);
        $this->assertSame('Gestione Chioschi SRL', $valori['pag_ragione_sociale']);
        $this->assertSame('11111111111', $valori['pag_piva']);
    }

    public function test_senza_pagante_diverso_la_scelta_e_gia_sul_soggetto_di_sezione_a(): void
    {
        $valori = SchedaAnagraficaData::for($this->cliente());

        $this->assertSame('stesso', $valori['pagante_tipo']);
        $this->assertArrayNotHasKey('pag_ragione_sociale', $valori);
    }

    public function test_le_anagrafiche_pagate_diventano_le_sedi_operative(): void
    {
        $gestore = $this->cliente(['company_name' => 'Gestione Chioschi SRL']);
        $this->cliente(['company_name' => 'Chiosco A', 'city' => 'JESOLO', 'billing_customer_id' => $gestore->id]);
        $this->cliente(['company_name' => 'Chiosco B', 'city' => 'CAORLE', 'billing_customer_id' => $gestore->id]);

        $valori = SchedaAnagraficaData::for($gestore);

        $this->assertSame('Chiosco A', $valori['sede1_insegna']);
        $this->assertSame('Jesolo', $valori['sede1_citta']);
        $this->assertSame('Chiosco B', $valori['sede2_insegna']);
        // Le paga il soggetto di sezione A, cioe' il gestore stesso.
        $this->assertSame('Yes', $valori['sede1_fatt_come_a']);
    }

    public function test_un_cliente_senza_sedi_collegate_e_sede_di_se_stesso(): void
    {
        $valori = SchedaAnagraficaData::for($this->cliente(['company_name' => 'Bar Da Solo']));

        $this->assertSame('Bar Da Solo', $valori['sede1_insegna']);
        $this->assertArrayNotHasKey('sede2_insegna', $valori);
    }

    public function test_le_matricole_installate_arrivano_in_tabella_col_numero_di_sede(): void
    {
        $gestore = $this->cliente(['company_name' => 'Gestione Chioschi SRL']);
        $chiosco = $this->cliente(['company_name' => 'Chiosco A', 'billing_customer_id' => $gestore->id]);

        MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'current_customer_id' => $chiosco->id,
            'serial_number' => 'IMP-SPINA-011',
            'model_name' => 'Impianto Spina 4 vie',
        ]);

        $valori = SchedaAnagraficaData::for($gestore);

        $this->assertSame('1', $valori['mac1_sede']);
        $this->assertSame('IMP-SPINA-011', $valori['mac1_matricola']);
        $this->assertSame('Impianto Spina 4 vie', $valori['mac1_modello']);
        // Proprieta' e pagante non li sa il CRM: restano da compilare.
        $this->assertArrayNotHasKey('mac1_proprieta', $valori);
    }

    /**
     * Il punto piu' importante: la scheda torna indietro firmata, quindi non
     * possiamo consegnarla con consensi gia' spuntati, una data o condizioni
     * di pagamento decise da noi.
     */
    public function test_consensi_firma_e_condizioni_di_pagamento_restano_in_bianco(): void
    {
        $cliente = $this->cliente([
            'consent_privacy_at' => now(),
            'consent_marketing_at' => now(),
        ]);

        $valori = SchedaAnagraficaData::for($cliente);

        foreach ([
            'consenso_presa_visione', 'consenso_marketing',
            'firma_data', 'firma_nome', 'firma_ruolo',
            'pag_riba_60', 'pag_bonifico', 'banca_iban', 'banca_nome',
        ] as $campo) {
            $this->assertArrayNotHasKey($campo, $valori, "{$campo} non deve essere precompilato");
        }
    }

    public function test_i_conteggi_dicono_quante_sedi_ci_sono_davvero(): void
    {
        $gestore = $this->cliente(['company_name' => 'Gestione Chioschi SRL']);

        foreach (range(1, SchedaAnagraficaData::MAX_SEDI + 2) as $i) {
            $this->cliente(['company_name' => "Chiosco {$i}", 'billing_customer_id' => $gestore->id]);
        }

        $this->assertSame(SchedaAnagraficaData::MAX_SEDI + 2, SchedaAnagraficaData::conteggi($gestore)['sedi']);
    }

    public function test_il_pdf_e_un_modulo_compilabile_coi_valori_dentro(): void
    {
        $cliente = $this->cliente(['company_name' => 'BAR CENTRALE SRL', 'city' => 'JESOLO']);

        $pdf = (new SchedaAnagraficaPdf(
            SchedaAnagraficaData::for($cliente),
            $this->tenant,
            SchedaAnagraficaData::conteggi($cliente),
        ))->render();

        $this->assertStringStartsWith('%PDF', $pdf);
        // I nomi dei campi e il valore precompilato finiscono nel PDF: se il
        // modulo si appiattisse (niente AcroForm) sparirebbero entrambi.
        $this->assertStringContainsString('fatt_ragione_sociale', $pdf);
        $this->assertStringContainsString(mb_convert_encoding('BAR CENTRALE SRL', 'UTF-16BE', 'UTF-8'), $pdf);
    }

    /**
     * TCPDF scrive sempre "/NeedAppearances false" e non offre un modo per
     * cambiarlo: lo sostituiamo sui byte in uscita, perche' su un modulo
     * riempito da programma il dato autorevole e' il valore del campo, non
     * l'aspetto pre-disegnato. Se un domani TCPDF cambiasse quella stringa la
     * sostituzione smetterebbe di agire in silenzio: questo test se ne
     * accorge.
     */
    public function test_il_modulo_chiede_al_viewer_di_ricostruire_l_aspetto_dai_valori(): void
    {
        $pdf = (new SchedaAnagraficaPdf(['fatt_ragione_sociale' => 'Bar Centrale'], $this->tenant))->render();

        $this->assertStringContainsString('/NeedAppearances true', $pdf);
        $this->assertStringNotContainsString('/NeedAppearances false', $pdf);
    }

    public function test_la_rotta_restituisce_il_pdf_a_chi_puo_vedere_il_cliente(): void
    {
        $utente = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($utente, $this->tenant, 'admin');

        $cliente = $this->cliente();

        $risposta = $this->actingAs($utente)->get(route('customers.scheda-anagrafica', $cliente));

        $risposta->assertOk();
        $risposta->assertHeader('content-type', 'application/pdf');
        // Scaricato e non aperto nel browser: vedi il commento nel controller.
        $this->assertStringStartsWith('attachment;', $risposta->headers->get('content-disposition'));
    }

    public function test_la_rotta_nega_il_pdf_di_un_cliente_di_un_altro_tenant(): void
    {
        $altro = Tenant::create(['name' => 'Altro', 'slug' => 'altro']);
        $utente = User::create([
            'tenant_id' => $altro->id, 'name' => 'Estraneo', 'email' => 'estraneo@altro.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($utente, $altro, 'admin');

        $this->actingAs($utente)
            ->get(route('customers.scheda-anagrafica', $this->cliente()))
            ->assertForbidden();
    }
    /**
     * Chi paga si scrive macchina per macchina, non una volta sola in cima.
     *
     * Su "Patatrac Caffe' di Martellozzo Elio" il pagante dell'anagrafica
     * diceva Martellozzo, ma delle cinque macchine due le paga Martellozzo,
     * due Dersut e il forno il cliente: una riga sola non poteva dirlo, e la
     * colonna restava vuota perche' il dato non c'era. Ora arriva dagli
     * installati di Eureka (segnalato dall'ufficio, 04/09/2026).
     */
    public function test_la_colonna_chi_paga_segue_la_singola_macchina(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $torrefattore = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'MARTELLOZZO LORENZO & C. SAS']);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'PATATRAC CAFFE',
            'billing_customer_id' => $torrefattore->id,
        ]);

        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $cliente->id,
            'serial_number' => 'AAA-1', 'model_name' => 'Impianto Spina',
            'billing_customer_id' => $torrefattore->id,
        ]);
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $cliente->id,
            'serial_number' => 'BBB-2', 'model_name' => 'Forno',
        ]);

        $valori = SchedaAnagraficaData::for($cliente);

        $righe = [];
        for ($n = 1; $n <= SchedaAnagraficaData::MAX_MACCHINE; $n++) {
            if (($valori["mac{$n}_matricola"] ?? '') === '') {
                continue;
            }
            $righe[$valori["mac{$n}_matricola"]] = $valori["mac{$n}_proprieta"] ?? '';
        }

        $this->assertSame('MARTELLOZZO LORENZO & C. SAS', $righe['AAA-1']);
        // Il forno lo paga il cliente: la colonna resta vuota, e il vuoto e'
        // l'informazione — ripetere il nome su ogni riga toglierebbe risalto
        // alle poche che fanno eccezione.
        $this->assertSame('', $righe['BBB-2']);
    }

}
