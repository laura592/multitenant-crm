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
