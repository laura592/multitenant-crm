<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource;
use App\Models\Material;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il codice manutenzione si sceglie da un elenco, non si ricorda a memoria.
 *
 * Era un campo libero con tre esempi nel segnaposto, mentre a catalogo i
 * codici ci sono tutti (richiesta dell'ufficio, 04/09/2026). Resta scrivibile
 * a mano: il catalogo di Eureka cambia, e un menu chiuso costringerebbe ad
 * aspettare l'import per registrare una macchina.
 */
class CodiciManutenzioneTest extends TestCase
{
    use RefreshDatabase;

    private function codici(): array
    {
        return MachineUnitResource::codiciManutenzione();
    }

    public function test_suggerisce_i_codici_base_e_non_le_varianti(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        foreach ([
            'F2' => 'MANUTENZIONE ORDINARIA F2',
            'C3' => 'MANUTENZIONE ORDINARIA CIMBALI 3 GRUPPI',
            'F2GOPPION' => 'MANUTENZIONE ORDINARIA F2',
            'F2HTS' => 'MANUTENZIONE ORDINARIA F2',
            'F2FEST' => 'Manutenzione ordinaria festiva 2 gruppi',
            'CHIORD' => 'INTERVENTO ORDINARIO',
        ] as $code => $tipo) {
            Material::create([
                'code' => $code, 'source' => Material::SOURCE_EUREKA, 'tenant_id' => $tenant->id,
                'category' => 'Eureka', 'type' => $tipo,
            ]);
        }

        $codici = $this->codici();

        $this->assertContains('F2', $codici);
        $this->assertContains('C3', $codici);

        // Le varianti per pagante e quelle festive le sceglie da sola
        // TariffeIntervento::manutenzione() guardando chi paga: offrirle qui
        // farebbe bloccare a mano una variante che poi verrebbe ricalcolata.
        foreach (['F2GOPPION', 'F2HTS', 'F2FEST'] as $variante) {
            $this->assertNotContains($variante, $codici, $variante);
        }

        // E non e' l'elenco di tutto il catalogo: solo la manutenzione.
        $this->assertNotContains('CHIORD', $codici);
    }
}
