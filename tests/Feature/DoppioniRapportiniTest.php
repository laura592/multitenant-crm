<?php

namespace Tests\Feature;

use App\Filament\Widgets\Gestionale\GestionaleDoppioniRapportiniWidget;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\ConfrontoRapportini;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Il sync propone l'abbinamento fra un rapportino compilato qui e la scheda
 * lavoro importata da Eureka che documenta lo stesso intervento, e la
 * conferma tiene il nostro travasandogli il collegamento a Eureka.
 */
class DoppioniRapportiniTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
        Mail::fake();
    }

    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $tecnico = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x'),
        ]);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Hotel Neps', 'gestionale_code' => 907,
        ]);
        $prodotto = Product::create(['sku' => 'MACCHINA', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina']);

        return [$tenant, $tecnico, $cliente, $prodotto];
    }

    private function macchina(Tenant $t, Customer $c, Product $p, string $matricola): MachineUnit
    {
        return MachineUnit::create([
            'tenant_id' => $t->id, 'current_customer_id' => $c->id,
            'product_id' => $p->id, 'serial_number' => $matricola,
        ]);
    }

    private function rapportino(Tenant $t, Customer $c, User $u, ?MachineUnit $m, string $source, string $giorno, ?int $eurekaId = null): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $t->id, 'customer_id' => $c->id, 'technician_id' => $u->id,
            'machine_unit_id' => $m?->id, 'source' => $source,
            'intervention_type' => 'riparazione',
            'intervention_date' => $giorno, 'eureka_service_report_id' => $eurekaId,
            'gestionale_number' => $eurekaId ? 604 : null,
        ]);
    }

    /**
     * La vista del confronto va RESA, non solo istanziata.
     *
     * E' l'unico pezzo di questa funzione che vive in un modale: nessun test
     * lo apriva, e un errore di sintassi Blade e' passato liscio fino in
     * produzione. La direttiva incriminata era "Eureka@if (...)": Blade non
     * riconosce una @ attaccata a un carattere di parola, quindi l'@if
     * restava testo mentre il suo @endif veniva compilato, e la vista non
     * compilava piu' affatto.
     *
     * Le due colonne vanno riempite di proposito con dati diversi: e' la
     * differenza fra i campi ad attivare le closure di evidenziazione.
     */
    public function test_il_confronto_affiancato_si_disegna(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, 'MAT-1');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-05-04');
        $nostro->update(['problem_description' => 'Non eroga', 'work_performed' => 'Sostituita guarnizione']);

        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-05-04', 8811);
        $importato->update(['problem_description' => 'Guasto erogazione']);

        $html = view('filament.widgets.confronto-rapportini', [
            'nostro' => $nostro->load(['technician', 'machineUnit', 'materialsUsed.material']),
            'importato' => $importato->load(['technician', 'machineUnit', 'materialsUsed.material']),
        ])->render();

        $this->assertStringContainsString('Non eroga', $html);
        $this->assertStringContainsString('Guasto erogazione', $html);
        // Il numero di scheda compare solo quando c'e': era il ramo dell'@if
        // che non compilava.
        $this->assertStringContainsString('scheda n. 604', $html);
        $this->assertStringContainsString('nessun materiale', $html);

        // Senza numero di scheda la frase si chiude e basta, senza virgola
        // penzolante.
        $senzaNumero = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-05-04');
        $htmlSenza = view('filament.widgets.confronto-rapportini', [
            'nostro' => $nostro,
            'importato' => $senzaNumero->load(['technician', 'machineUnit', 'materialsUsed.material']),
        ])->render();

        $this->assertStringNotContainsString('scheda n.', $htmlSenza);
        $this->assertStringContainsString('importato da Eureka', $htmlSenza);
    }

    public function test_propone_e_conferma_lo_stesso_intervento(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');
        $filtro = Material::create([
            'code' => 'FILTRO', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Filtro',
        ]);

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17517);

        $nostro->update(['notes' => 'Cliente avvisato del ricambio da ordinare.']);
        $importato->update(['notes' => 'Da fatturare a fine mese.']);

        foreach ([$nostro, $importato] as $r) {
            ServiceReportMaterial::create(['service_report_id' => $r->id, 'material_id' => $filtro->id, 'quantity' => 1]);
        }

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $nostro->refresh();
        $this->assertSame($importato->id, $nostro->duplicato_suggerito_id);
        $this->assertSame(ConfrontoRapportini::CERTO, $nostro->duplicato_suggerito_motivo);

        // Il confronto affiancato deve mostrare il contenuto di entrambi:
        // senza, chi conferma decide alla cieca.
        $this->actingAs($tecnico);
        Filament::setTenant($tenant);
        Livewire::test(GestionaleDoppioniRapportiniWidget::class)
            ->assertOk()
            ->assertSee($nostro->number)
            ->assertSee($importato->number)
            ->assertSee('Confronta');

        // La conferma tiene il nostro e gli passa il collegamento a Eureka.
        $nostro->confermaDuplicato();
        $nostro->refresh();

        $this->assertSame(17517, $nostro->eureka_service_report_id);

        // Le note non si scelgono: si sommano. Quella del CRM resta in testa,
        // quella di Eureka viene aggiunta marcata — scartarla perderebbe
        // informazione in modo irreversibile.
        $this->assertStringContainsString('Cliente avvisato del ricambio', (string) $nostro->notes);
        $this->assertStringContainsString('Da Eureka: Da fatturare a fine mese.', (string) $nostro->notes);
        $this->assertNull($nostro->duplicato_suggerito_id);
        $this->assertSoftDeleted('service_reports', ['id' => $importato->id]);
    }

    /**
     * Due macchine dello stesso cliente nella stessa visita: sono interventi
     * distinti e non devono essere proposti come doppioni.
     */
    public function test_non_propone_macchine_diverse_dello_stesso_cliente(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();

        $nostro = $this->rapportino($tenant, $cliente, $tecnico,
            $this->macchina($tenant, $cliente, $prodotto, '1858049'), ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $this->rapportino($tenant, $cliente, $tecnico,
            $this->macchina($tenant, $cliente, $prodotto, '1813615'), ServiceReport::SOURCE_EUREKA, '2026-08-06', 17700);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertNull($nostro->refresh()->duplicato_suggerito_id);
    }

    /**
     * Con due schede Eureka candidate il sync non sceglie: proporre la prima
     * che capita farebbe confermare a occhi chiusi un abbinamento che il
     * sistema stesso non sa distinguere.
     */
    public function test_non_propone_nulla_quando_i_candidati_sono_due(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17700);
        $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17701);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertNull($nostro->refresh()->duplicato_suggerito_id);
    }
}
