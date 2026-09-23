<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\ConfrontoMacchine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Il sync propone la fusione fra macchine che sono lo stesso apparecchio.
 *
 * Ogni doppione e' un rapportino che non si abbinera' mai: l'import degli
 * installati aveva creato due volte la stessa orzina perche' Eureka la
 * scrive con e senza spazi, e in anagrafica c'era "A 300 3400000310192"
 * accanto a "3400000310192".
 *
 * Qui il rischio non e' mancare una fusione: e' proporne una sbagliata, che
 * fa sparire una macchina vera. I test guardano soprattutto quello.
 */
class FusioneMacchineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
    }

    private function macchina(string $matricola, ?string $modello = null, ?int $codice = null, ?Customer $cliente = null): MachineUnit
    {
        return MachineUnit::create([
            'tenant_id' => $this->tenant->id,
            'current_customer_id' => ($cliente ?? $this->cliente)->id,
            'serial_number' => $matricola,
            'model_name' => $modello,
            'gestionale_code' => $codice,
        ]);
    }

    /** @return array<int, array{tenere: MachineUnit, assorbire: MachineUnit, motivo: string}> */
    private function proposte(): array
    {
        return ConfrontoMacchine::proposte(MachineUnit::all());
    }

    public function test_l_impianto_segnato_a_mano_e_quello_arrivato_da_eureka_sono_lo_stesso(): void
    {
        // Bar Miki, 23/09/2026: l'impianto alla spina era stato creato qui
        // con un codice nostro, poi il gestionale l'ha registrato come
        // SPINAMIKI. Le due matricole non si somigliano in niente.
        $aMano = $this->macchina('IMP-SPINA-022', 'Impianto Spina (birra+vino)');
        $daEureka = $this->macchina('SPINAMIKI', 'IMPIANTO ALLA SPINA 2 VIE');
        $this->macchina('V24003882', 'DALLA CORTE');

        $proposte = ConfrontoMacchine::proposte(MachineUnit::all(), [ConfrontoMacchine::chiave('SPINAMIKI') => true]);

        $this->assertCount(1, $proposte);
        $this->assertSame(ConfrontoMacchine::IMPIANTO_ORA_SU_EUREKA, $proposte[0]['motivo']);
        $this->assertSame($daEureka->id, $proposte[0]['tenere']->id, 'Si tiene quella che il gestionale conosce.');
        $this->assertSame($aMano->id, $proposte[0]['assorbire']->id);
    }

    public function test_l_impianto_acqua_non_si_fonde_con_quello_alla_spina(): void
    {
        $this->macchina('CASETTA-ACQUA-1', 'Casetta dell\'acqua');
        $this->macchina('SPINAMIKI', 'IMPIANTO ALLA SPINA 2 VIE');

        $this->assertSame([], ConfrontoMacchine::proposte(MachineUnit::all(), [ConfrontoMacchine::chiave('SPINAMIKI') => true]));
    }

    public function test_due_impianti_alla_spina_dallo_stesso_cliente_non_si_indovinano(): void
    {
        $this->macchina('IMP-SPINA-022', 'Impianto Spina (birra+vino)');
        $this->macchina('IMP-SPINA-023', 'Impianto Spina (selz)');
        $this->macchina('SPINAMIKI', 'IMPIANTO ALLA SPINA 2 VIE');

        $this->assertSame([], ConfrontoMacchine::proposte(MachineUnit::all(), [ConfrontoMacchine::chiave('SPINAMIKI') => true]));
    }

    public function test_un_impianto_che_eureka_non_elenca_non_e_una_proposta(): void
    {
        $this->macchina('IMP-SPINA-022', 'Impianto Spina (birra+vino)');
        $this->macchina('SPINAMIKI', 'IMPIANTO ALLA SPINA 2 VIE');

        $this->assertSame([], ConfrontoMacchine::proposte(MachineUnit::all()));
    }

    public function test_la_punteggiatura_non_fa_due_macchine(): void
    {
        $this->macchina('BRL003020002113218', 'ORZINA', 140);
        $this->macchina('BRL 003 020002113218', 'ORZINA');

        $proposte = $this->proposte();

        $this->assertCount(1, $proposte);
        $this->assertSame(ConfrontoMacchine::STESSA_MATRICOLA, $proposte[0]['motivo']);
        // Si tiene quella collegata a Eureka.
        $this->assertSame('BRL003020002113218', $proposte[0]['tenere']->serial_number);
    }

    public function test_gli_zeri_iniziali_non_fanno_due_macchine(): void
    {
        $this->macchina('028019', 'MACINADOSATORE');
        $this->macchina('28019');

        $proposte = $this->proposte();

        $this->assertCount(1, $proposte);
        $this->assertSame(ConfrontoMacchine::ZERI_INIZIALI, $proposte[0]['motivo']);
    }

    public function test_il_modello_scritto_davanti_al_seriale_si_riconosce(): void
    {
        $this->macchina('3400000310192', 'MACCHINA PER CAFFE FRANKE A300', 9001);
        $this->macchina('A 300 3400000310192', 'Macchina');

        $proposte = $this->proposte();

        $this->assertCount(1, $proposte);
        $this->assertSame(ConfrontoMacchine::MATRICOLA_CONTENUTA, $proposte[0]['motivo']);
        $this->assertSame('3400000310192', $proposte[0]['tenere']->serial_number);
        $this->assertSame('A 300 3400000310192', $proposte[0]['assorbire']->serial_number);
    }

    /**
     * Il caso che fa perdere una macchina: due seriali diversi che per caso
     * condividono un prefisso. "1955952" sta dentro "1955952741" ma sono due
     * apparecchi.
     */
    public function test_un_prefisso_per_caso_non_e_un_doppione(): void
    {
        $this->macchina('1955952', 'MACINADOSATORE');
        $this->macchina('1955952741', 'MACINADOSATORE');

        $this->assertSame([], $this->proposte());
    }

    /** Le matricole di soli zeri sono il campo lasciato in bianco. */
    public function test_le_matricole_segnaposto_non_si_fondono(): void
    {
        $this->macchina('000000', 'FORNO');
        $this->macchina('0000000', 'IMPIANTO SPINA');

        $this->assertSame([], $this->proposte());
    }

    /** Due clienti diversi: "contiene" non basta, potrebbero essere due macchine. */
    public function test_non_si_fondono_macchine_di_clienti_diversi(): void
    {
        $altro = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Sport']);
        $this->macchina('PK905', 'ADDOLCITORE');
        $this->macchina('MC 031653 PK905', 'ADDOLCITORE', null, $altro);

        $this->assertSame([], $this->proposte());
    }

    /**
     * Fondere sposta i rapportini e riempie i vuoti, senza sovrascrivere
     * quello che la macchina buona sa gia'.
     */
    public function test_assorbire_sposta_i_rapportini_e_riempie_i_vuoti(): void
    {
        $tecnico = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x'),
        ]);
        Product::create(['sku' => 'M', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina']);

        $buona = $this->macchina('3400000310192', null, 9001);
        $copia = $this->macchina('A 300 3400000310192', 'FRANKE A300');

        $report = ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'technician_id' => $tecnico->id, 'machine_unit_id' => $copia->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2026-08-04',
        ]);

        $buona->assorbe($copia);

        $this->assertSame($buona->id, $report->fresh()->machine_unit_id);
        // Il modello mancava qui e c'era li': si raccoglie.
        $this->assertSame('FRANKE A300', $buona->fresh()->model_name);
        // Il codice Eureka c'era gia': non si tocca.
        $this->assertSame(9001, (int) $buona->fresh()->gestionale_code);
        $this->assertSoftDeleted('machine_units', ['id' => $copia->id]);
    }

    /**
     * Le due macchine nascono dalla stessa bolla: fonderle non deve lasciare
     * due volte la stessa consegna nello storico.
     */
    public function test_assorbire_non_raddoppia_la_consegna(): void
    {
        $buona = $this->macchina('1919045', 'SUPER JOLLY');
        $copia = $this->macchina('1919045-21679');
        $buona->moveTo($this->cliente, 'bolla n. 97', Carbon::parse('2024-01-01'));
        $copia->moveTo($this->cliente, 'bolla n. 97', Carbon::parse('2024-01-01'), codicePaganteEureka: 580);

        $buona->assorbe($copia);

        $posizione = $buona->placements()->sole();
        // Il pagante mancava qui e c'era sulla copia: si raccoglie.
        $this->assertSame(580, (int) $posizione->eureka_billing_customer_code);
        $this->assertSame($buona->id, $copia->fresh()->fusa_in_id);
    }

    /**
     * Eureka continua a chiamarla con la matricola della copia: il sync non
     * deve ripristinarla, se no la fusione si ripropone e ogni conferma
     * aggiunge un'altra copia della consegna (22/09/2026, 1919045).
     */
    public function test_il_sync_non_ripristina_la_macchina_fusa(): void
    {
        Http::preventStrayRequests();
        $this->cliente->update(['gestionale_code' => 1460]);

        $buona = $this->macchina('1919045', 'SUPER JOLLY');
        $copia = $this->macchina('1919045-21679');
        $buona->moveTo($this->cliente, 'bolla n. 97', Carbon::parse('2024-01-01'));
        $copia->moveTo($this->cliente, 'bolla n. 97', Carbon::parse('2024-01-01'));
        $buona->assorbe($copia);

        Http::fake(fn (Request $request) => Http::response(str_contains($request->url(), 'art_installati')
            ? [['id' => 1, 'matricola' => '1919045-21679', 'numero_doc_t23' => 97, 'data_documento' => '2024-01-01T00:00:00.000+01:00', 'id_intestatario_fattura_f15' => 580]]
            : [], 200));

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSoftDeleted('machine_units', ['id' => $copia->id]);
        $this->assertSame(1, $buona->placements()->count());
        // Il pagante che Eureka da' per la copia arriva sulla macchina tenuta.
        $this->assertSame(580, (int) $buona->placements()->sole()->eureka_billing_customer_code);
    }

    /** Se Eureka la scrive lunga, si tiene quella lunga. */
    public function test_si_tiene_la_matricola_che_usa_eureka(): void
    {
        $this->macchina('1919045', 'SUPER JOLLY');
        $this->macchina('1919045-21679');

        $proposte = ConfrontoMacchine::proposte(MachineUnit::all(), ['191904521679' => true]);

        $this->assertCount(1, $proposte);
        $this->assertSame('1919045-21679', $proposte[0]['tenere']->serial_number);
        $this->assertSame('1919045', $proposte[0]['assorbire']->serial_number);
    }

    /** Eureka le elenca tutte e due: per Eureka sono due apparecchi. */
    public function test_due_matricole_su_eureka_non_si_fondono(): void
    {
        $this->macchina('1502475', 'TEOREMA A2');
        $this->macchina('1502475-CM103290', 'TEOREMA A2');
        $this->macchina('031814', 'CEADO');
        $this->macchina('31814', 'CEADO');

        $eureka = ['1502475' => true, '1502475cm103290' => true, '031814' => true, '31814' => true];

        $this->assertSame([], ConfrontoMacchine::proposte(MachineUnit::all(), $eureka));
    }

    public function test_la_tenuta_prende_la_matricola_della_fusa(): void
    {
        $buona = $this->macchina('1919045', 'SUPER JOLLY');
        $copia = $this->macchina('1919045-21679');
        $buona->assorbe($copia);

        $buona->scambiaMatricolaCon($copia->fresh());

        $this->assertSame('1919045-21679', $buona->fresh()->serial_number);
        $this->assertSame('1919045', MachineUnit::withTrashed()->find($copia->id)->serial_number);
        $this->assertSoftDeleted('machine_units', ['id' => $copia->id]);
    }

    public function test_scartare_lascia_le_due_macchine_distinte(): void
    {
        $buona = $this->macchina('3400000310192', null, 9001);
        $copia = $this->macchina('A 300 3400000310192');
        $copia->update(['fusione_suggerita_id' => $buona->id, 'fusione_suggerita_motivo' => ConfrontoMacchine::MATRICOLA_CONTENUTA]);

        $copia->scartaFusione();

        $this->assertNull($copia->fresh()->fusione_suggerita_id);
        $this->assertNotSoftDeleted('machine_units', ['id' => $copia->id]);
    }
}
