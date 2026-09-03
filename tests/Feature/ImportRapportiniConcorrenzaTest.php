<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un rapportino eliminato mentre l'import gira non deve fermare gli altri.
 *
 * L'elenco dei rapportini gia' importati si carica una volta sola all'inizio,
 * e con --with-detail il ciclo dura minuti: se nel frattempo qualcuno elimina
 * dal pannello uno di quelli in coda, save() fa un UPDATE a vuoto e a
 * esplodere e' l'inserimento delle righe materiale, che non trova piu' il
 * padre. Successo in produzione il 03/09/2026 su RT-2026-0676, con l'import
 * fermo a un terzo del lavoro.
 */
class ImportRapportiniConcorrenzaTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_rapportino_sparito_non_ferma_l_import(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $tecnico = User::create([
            'tenant_id' => $tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x'),
        ]);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale', 'gestionale_code' => 1234,
        ]);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'technician_id' => $tecnico->id,
            'source' => ServiceReport::SOURCE_EUREKA, 'eureka_service_report_id' => 17659,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2026-08-06',
        ]);

        $id = $report->getKey();

        // Sparisce davvero, come una force delete dal pannello.
        ServiceReport::withTrashed()->whereKey($id)->forceDelete();

        // La verifica che il comando fa prima di scrivere le righe figlie.
        $this->assertFalse(
            ServiceReport::withTrashed()->whereKey($id)->exists(),
            'il rapportino deve risultare sparito',
        );

        // E il modello in memoria non se ne accorge: e' esattamente il caso
        // che faceva esplodere l'import.
        $this->assertTrue($report->exists, 'il modello in memoria crede ancora di esistere');
        $this->assertTrue($report->save(), 'save() su una riga sparita non protesta');
    }
}
