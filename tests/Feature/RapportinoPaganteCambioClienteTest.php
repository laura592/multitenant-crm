<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il pagante congelato sul rapportino protegge la storia da un cambio di
 * pagante del cliente. Non deve invece sopravvivere a un cambio del CLIENTE:
 * li' non si riscrive la storia, si corregge a chi appartiene il documento
 * (caso reale, 04/09/2026: rapportino intestato per sbaglio a "Per SRL"
 * invece che a "Perenzin Latteria SRL", corretto il cliente e rimasto con il
 * pagante del cliente sbagliato).
 */
class RapportinoPaganteCambioClienteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->tecnico = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Igor',
            'email' => 'igor@alex.it', 'password' => bcrypt('x'),
        ]);
    }

    private function cliente(string $nome, ?Customer $pagante = null): Customer
    {
        return Customer::create([
            'tenant_id' => $this->tenant->id,
            'company_name' => $nome,
            'billing_customer_id' => $pagante?->id,
        ]);
    }

    private function rapportino(Customer $cliente, string $status = 'completato'): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $cliente->id,
            'technician_id' => $this->tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'intervention_date' => now(),
            'work_performed' => 'Sostituita guarnizione',
            'status' => $status,
        ]);
    }

    public function test_correggere_il_cliente_ricalcola_il_pagante(): void
    {
        $sbagliato = $this->cliente('Per SRL');
        $giusto = $this->cliente('Perenzin Latteria SRL');

        $report = $this->rapportino($sbagliato);
        // Chiuso alla creazione: il pagante si e' gia' congelato sul cliente
        // sbagliato, ed e' esattamente lo stato da cui si parte.
        $this->assertSame($sbagliato->id, $report->fresh()->billing_customer_id);

        $report->update(['customer_id' => $giusto->id]);

        $this->assertSame($giusto->id, $report->fresh()->billing_customer_id);
        $this->assertSame('Perenzin Latteria SRL', $report->fresh()->invoiceRecipient()->company_name);
    }

    /**
     * Il cliente nuovo ha un pagante suo (il torrefattore): e' quello che
     * deve finire sul rapportino, non il cliente stesso.
     */
    public function test_il_pagante_ricalcolato_e_quello_del_cliente_nuovo(): void
    {
        $torrefattore = $this->cliente('Dersut SPA');
        $sbagliato = $this->cliente('Per SRL');
        $giusto = $this->cliente('Perenzin Latteria SRL', $torrefattore);

        $report = $this->rapportino($sbagliato);
        $report->update(['customer_id' => $giusto->id]);

        $this->assertSame($torrefattore->id, $report->fresh()->billing_customer_id);
    }

    /**
     * La macchina batte il cliente, come in invoiceRecipient(): una matricola
     * in comodato pagata da un gestore terzo resta a carico suo.
     */
    public function test_la_macchina_col_pagante_suo_vince_anche_dopo_la_correzione(): void
    {
        $gestore = $this->cliente('Martellozzo SRL');
        $sbagliato = $this->cliente('Per SRL');
        $giusto = $this->cliente('Perenzin Latteria SRL');

        $modello = Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Impianto acqua', 'sku' => 'IMP-ACQUA',
            'type' => 'macchina',
        ]);
        $macchina = MachineUnit::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $modello->id,
            'serial_number' => 'IMP-ACQUA-001',
            'current_customer_id' => $giusto->id,
            'billing_customer_id' => $gestore->id,
        ]);

        $report = $this->rapportino($sbagliato);
        $report->update(['customer_id' => $giusto->id, 'machine_unit_id' => $macchina->id]);

        $this->assertSame($gestore->id, $report->fresh()->billing_customer_id);
    }

    /**
     * Scegliere il pagante nello stesso salvataggio in cui si cambia cliente
     * e' una decisione presa col cliente nuovo davanti: non va buttata.
     * E' il caso "il guasto e' colpa del cliente, si fattura a lui".
     */
    public function test_una_scelta_esplicita_nello_stesso_salvataggio_resta(): void
    {
        $torrefattore = $this->cliente('Dersut SPA');
        $sbagliato = $this->cliente('Per SRL');
        $giusto = $this->cliente('Perenzin Latteria SRL', $torrefattore);

        $report = $this->rapportino($sbagliato);
        $report->update([
            'customer_id' => $giusto->id,
            'billing_customer_id' => $giusto->id,
        ]);

        $this->assertSame($giusto->id, $report->fresh()->billing_customer_id);
    }

    /**
     * Il congelamento vero — quello che protegge la storia — non si tocca:
     * cambiare il pagante del CLIENTE non riscrive i rapportini gia' chiusi.
     */
    public function test_cambiare_il_pagante_del_cliente_non_tocca_i_rapportini_chiusi(): void
    {
        $vecchio = $this->cliente('Dersut SPA');
        $nuovo = $this->cliente('Goppion SPA');
        $cliente = $this->cliente('Bar del Porto', $vecchio);

        $report = $this->rapportino($cliente);
        $this->assertSame($vecchio->id, $report->fresh()->billing_customer_id);

        $cliente->update(['billing_customer_id' => $nuovo->id]);

        $this->assertSame($vecchio->id, $report->fresh()->billing_customer_id);
    }
}
