<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le righe nate nel CRM restavano senza prezzo: le scorciatoie del rapportino
 * creano la riga con material_id e quantita' e basta, e la copia con i prezzi
 * mostrava "—" su voci che a listino un prezzo ce l'hanno (RT-2026-0770,
 * CHIORD 46,20 e ORE 42,00, entrambe vuote).
 */
class PrezzoRigheRapportinoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ServiceReport, 1: Material} */
    private function scenario(float $listino = 46.20): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $tecnico = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x'),
        ]);
        $report = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'technician_id' => $tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => now(),
            'work_performed' => 'Intervento',
        ]);
        $materiale = Material::create([
            'tenant_id' => $tenant->id, 'code' => 'CHIORD', 'category' => 'Eureka',
            'type' => 'CHIAMATA ORDINARIA', 'source' => Material::SOURCE_EUREKA, 'list_price' => $listino,
        ]);

        return [$report, $materiale];
    }

    public function test_una_riga_senza_prezzo_prende_il_listino(): void
    {
        [$report, $materiale] = $this->scenario();

        $riga = ServiceReportMaterial::create([
            'service_report_id' => $report->id, 'material_id' => $materiale->id, 'quantity' => 2,
        ]);

        $this->assertSame('46.20', (string) $riga->unit_cost_snapshot);
        $this->assertSame('92.40', (string) $riga->line_total_snapshot);
    }

    /** Un prezzo arrivato da Eureka e' il dato buono e non si tocca. */
    public function test_un_prezzo_gia_presente_non_viene_sovrascritto(): void
    {
        [$report, $materiale] = $this->scenario();

        $riga = ServiceReportMaterial::create([
            'service_report_id' => $report->id, 'material_id' => $materiale->id,
            'quantity' => 1, 'unit_cost_snapshot' => 30.00, 'line_total_snapshot' => 25.00,
        ]);

        $this->assertSame('30.00', (string) $riga->unit_cost_snapshot);
        // 25,00 e non 30,00: su Eureka l'importo porta gli sconti di riga.
        $this->assertSame('25.00', (string) $riga->line_total_snapshot);
    }

    public function test_cambiando_la_quantita_l_importo_segue(): void
    {
        [$report, $materiale] = $this->scenario();

        $riga = ServiceReportMaterial::create([
            'service_report_id' => $report->id, 'material_id' => $materiale->id, 'quantity' => 2,
        ]);
        $riga->update(['quantity' => 3]);

        $this->assertSame('138.60', (string) $riga->fresh()->line_total_snapshot);
    }

    /**
     * La manodopera si fattura a ore, e le ore sono decimali: un'ora e mezza
     * a 42,00 fa 63,00, non 84,00 arrotondando a due ore.
     */
    public function test_le_ore_decimali_danno_l_importo_giusto(): void
    {
        [$report, $materiale] = $this->scenario(listino: 42.00);

        $riga = ServiceReportMaterial::create([
            'service_report_id' => $report->id, 'material_id' => $materiale->id, 'quantity' => 1.5,
        ]);

        $this->assertSame('42.00', (string) $riga->unit_cost_snapshot);
        $this->assertSame('63.00', (string) $riga->line_total_snapshot);
    }
}
