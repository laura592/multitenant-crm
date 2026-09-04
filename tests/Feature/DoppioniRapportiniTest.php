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
        $this->assertStringContainsString('da Eureka', $htmlSenza);
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

        // Si deve poter decidere dal confronto stesso: chiuderlo per cercare
        // la riga e premere un altro bottone e' un giro a vuoto proprio
        // quando si e' appena letto il dettaglio.
        Livewire::test(GestionaleDoppioniRapportiniWidget::class)
            ->mountTableAction('dettagli', $nostro)
            ->assertTableActionExists('dettagli')
            ->callMountedTableAction();

        $nostro->refresh();
        $this->assertSame(17517, $nostro->eureka_service_report_id, 'il confronto deve poter confermare');
        $this->assertSoftDeleted('service_reports', ['id' => $importato->id]);
    }

    /** Anche lo scarto si deve poter fare dal confronto, senza richiuderlo. */
    public function test_dal_confronto_si_possono_tenere_separati(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');
        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17517);
        $nostro->update([
            'duplicato_suggerito_id' => $importato->id,
            'duplicato_suggerito_motivo' => ConfrontoRapportini::PROBABILE,
        ]);

        $this->actingAs($tecnico);
        Filament::setTenant($tenant);

        // L'azione nel footer del modale non si monta come una riga: si
        // recupera dal confronto e si esegue, che e' esattamente quello che
        // fa il click.
        $componente = Livewire::test(GestionaleDoppioniRapportiniWidget::class);
        $confronto = $componente->instance()->getTable()->getAction('dettagli');
        $scarta = collect($confronto->getExtraModalFooterActions())
            ->firstWhere(fn ($azione) => $azione->getName() === 'scarta_dal_confronto');

        $this->assertNotNull($scarta, 'dal confronto si deve poter dire «Sono diversi»');
        $scarta->record($nostro)->call();

        $nostro->refresh();
        $this->assertNull($nostro->duplicato_suggerito_id);
        $this->assertNull($nostro->eureka_service_report_id, 'scartare non deve collegare nulla');
        $this->assertNotSoftDeleted('service_reports', ['id' => $importato->id]);
    }

    /** La conferma dal modello, indipendente dall'interfaccia. */
    public function test_la_conferma_travasa_il_collegamento_a_eureka(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');
        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $nostro->update(['notes' => 'Cliente avvisato del ricambio']);
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17517);
        $importato->update(['notes' => 'Da fatturare a fine mese.']);
        $nostro->update([
            'duplicato_suggerito_id' => $importato->id,
            'duplicato_suggerito_motivo' => ConfrontoRapportini::CERTO,
        ]);

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
    /**
     * Il caso RT-2026-0579: il tecnico non seleziona la macchina e scrive la
     * matricola nel testo, la scheda di Eureka la porta in chiaro. Confermare
     * senza raccoglierla butterebbe via l'unico aggancio all'apparecchio.
     */
    public function test_la_conferma_adotta_la_macchina_se_qui_manca(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '3400000411147');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, null, ServiceReport::SOURCE_MANUALE, '2026-08-05');
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-05', 17518);
        $nostro->update([
            'duplicato_suggerito_id' => $importato->id,
            'duplicato_suggerito_motivo' => ConfrontoRapportini::DA_VERIFICARE,
        ]);

        $this->assertNull($nostro->machine_unit_id);

        $nostro->confermaDuplicato();
        $nostro->refresh();

        $this->assertSame($macchina->id, $nostro->machine_unit_id, 'la macchina della scheda va raccolta');
    }

    /** Se la macchina qui c'e' gia', non si sovrascrive: e' una scelta umana. */
    public function test_la_conferma_non_sovrascrive_una_macchina_gia_presente(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $nostra = $this->macchina($tenant, $cliente, $prodotto, '1858049');
        $loro = $this->macchina($tenant, $cliente, $prodotto, '3400000411147');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $nostra, ServiceReport::SOURCE_MANUALE, '2026-08-05');
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $loro, ServiceReport::SOURCE_EUREKA, '2026-08-05', 17519);
        $nostro->update([
            'duplicato_suggerito_id' => $importato->id,
            'duplicato_suggerito_motivo' => ConfrontoRapportini::DA_VERIFICARE,
        ]);

        $nostro->confermaDuplicato();
        $nostro->refresh();

        $this->assertSame($nostra->id, $nostro->machine_unit_id, 'la macchina del tecnico non si tocca');
    }

    /**
     * Unire constata che il documento e' gia' in Eureka: lo stato deve dirlo,
     * altrimenti chi riapre il rapportino fra un mese lo corregge qui invece
     * che sul gestionale.
     */
    public function test_unire_porta_lo_stato_in_gestionale(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $nostro->update(['status' => 'completato']);
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17520);
        $nostro->update([
            'duplicato_suggerito_id' => $importato->id,
            'duplicato_suggerito_motivo' => ConfrontoRapportini::CERTO,
        ]);

        $nostro->confermaDuplicato();
        $nostro->refresh();

        $this->assertSame('in_gestionale', $nostro->status);
        $this->assertContains($nostro->status, ServiceReport::CLOSED_STATUSES);
    }

    /** Scartare non cambia lo stato: non si e' constatato nulla. */
    public function test_scartare_non_tocca_lo_stato(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $nostro->update(['status' => 'completato']);
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17521);
        $nostro->update(['duplicato_suggerito_id' => $importato->id]);

        $nostro->scartaDuplicato();
        $nostro->refresh();

        $this->assertSame('completato', $nostro->status);
    }

    /**
     * Gli articoli buoni sono quelli del gestionale (indicazione
     * dell'ufficio, 02/09/2026): e' li' che si fattura. Confermando
     * RT-2026-0584 (1 riga) su una scheda da 2 un materiale spariva del
     * tutto — segnalato dal vivo lo stesso giorno.
     */
    public function test_unire_prende_gli_articoli_del_gestionale(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $ore = Material::create([
            'code' => 'ORE', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Manodopera',
        ]);
        $ricambio = Material::create([
            'code' => '431029055', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Ricambio',
        ]);

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        ServiceReportMaterial::create([
            'service_report_id' => $nostro->id, 'material_id' => $ore->id, 'quantity' => 1,
        ]);

        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17513);
        ServiceReportMaterial::create([
            'service_report_id' => $importato->id, 'material_id' => $ore->id, 'quantity' => 3,
        ]);
        ServiceReportMaterial::create([
            'service_report_id' => $importato->id, 'material_id' => $ricambio->id, 'quantity' => 1,
        ]);

        $nostro->update([
            'duplicato_suggerito_id' => $importato->id,
            'duplicato_suggerito_motivo' => ConfrontoRapportini::CERTO,
        ]);

        $nostro->confermaDuplicato();
        $nostro->refresh();

        $righe = $nostro->materialsUsed()->get()->keyBy('material_id');

        $this->assertCount(2, $righe, 'il ricambio della scheda deve arrivare qui');
        $this->assertArrayHasKey($ricambio->id, $righe->all());
        // Gli articoli buoni sono quelli del gestionale: e' li' che si
        // fattura, quindi vince la quantita' di Eureka.
        $this->assertSame('3.00', (string) $righe[$ore->id]->quantity);
    }

    /**
     * La versione del tecnico non sparisce davvero: resta soft-deleted, cosi'
     * si puo' ancora vedere cosa e' cambiato.
     */
    public function test_le_righe_sostituite_restano_recuperabili(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $suo = Material::create([
            'code' => 'LAVMART', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Lavaggio',
        ]);
        $loro = Material::create([
            'code' => 'LAV2MART', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Lavaggio',
        ]);

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        ServiceReportMaterial::create(['service_report_id' => $nostro->id, 'material_id' => $suo->id, 'quantity' => 1]);

        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17516);
        ServiceReportMaterial::create(['service_report_id' => $importato->id, 'material_id' => $loro->id, 'quantity' => 1]);

        $nostro->update(['duplicato_suggerito_id' => $importato->id]);
        $nostro->confermaDuplicato();

        $vive = $nostro->materialsUsed()->pluck('material_id');
        $this->assertEquals([$loro->id], $vive->all(), 'resta il codice che Eureka riconosce');

        $this->assertSoftDeleted('service_report_materials', [
            'service_report_id' => $nostro->id, 'material_id' => $suo->id,
        ]);
    }

    /** Una scheda senza righe non deve svuotare il rapportino del tecnico. */
    public function test_una_scheda_senza_righe_non_cancella_i_materiali(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');
        $materiale = Material::create([
            'code' => 'ORE', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Manodopera',
        ]);

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        ServiceReportMaterial::create(['service_report_id' => $nostro->id, 'material_id' => $materiale->id, 'quantity' => 2]);

        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17522);
        $nostro->update(['duplicato_suggerito_id' => $importato->id]);

        $nostro->confermaDuplicato();

        $this->assertCount(1, $nostro->materialsUsed()->get());
    }

    /**
     * Due rapportini possono proporre la stessa scheda: confermarne uno la
     * manda in soft delete e l'altra proposta resta orfana. Il confronto non
     * deve schiantarsi, e la proposta va chiusa.
     */
    public function test_una_proposta_orfana_non_rompe_il_confronto(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $primo = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $secondo = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $scheda = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17514);

        $primo->update(['duplicato_suggerito_id' => $scheda->id, 'duplicato_suggerito_motivo' => ConfrontoRapportini::CERTO]);
        $secondo->update(['duplicato_suggerito_id' => $scheda->id, 'duplicato_suggerito_motivo' => ConfrontoRapportini::CERTO]);

        $primo->confermaDuplicato();

        // La conferma chiude subito la proposta rimasta orfana: aspettare il
        // sync successivo lascia una finestra in cui confermare la seconda
        // aggancia un documento morto (visto su RT-2026-0614, che proponeva
        // la scheda del lavaggio invece di quella del filtro).
        $secondo->refresh();
        $this->assertNull($secondo->duplicato_suggerito_id, 'la proposta orfana va chiusa subito');
        $this->assertSame(0, ServiceReport::scartaProposteOrfane(), 'non deve restarne nessuna');
    }

    /**
     * Rete di sicurezza per una proposta orfana arrivata da un'altra strada
     * (un rapportino importato cancellato a mano, un sync interrotto a meta').
     * Il confronto deve disegnarsi lo stesso: senza withTrashed() esplodeva
     * con "Call to a member function load() on null".
     */
    public function test_il_confronto_regge_una_scheda_gia_cancellata(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $scheda = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17523);

        $nostro->update(['duplicato_suggerito_id' => $scheda->id]);
        $scheda->delete();

        $nostro->refresh();
        $this->assertNotNull($nostro->duplicatoSuggerito, 'la scheda cancellata deve restare leggibile');
        $this->assertSame($scheda->id, $nostro->duplicatoSuggerito->id);
    }

    /**
     * Chi paga davvero deve seguire l'unione: RT-2026-0586 dichiarava "paga
     * il cliente stesso" mentre Eureka sapeva che pagava GOPPION CAFFE' SPA.
     * E' un dato di fatturazione, non un dettaglio.
     */
    public function test_unire_porta_anche_il_pagante_di_eureka(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '1858049');

        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-06');
        $importato = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-06', 17499);
        $importato->update([
            'eureka_destinazione_code' => 782,
            'eureka_destinazione_label' => "GOPPION CAFFE' SPA",
            'eureka_stato_documento' => 10,
            'eureka_stato_label' => 'Chiuso',
        ]);

        $nostro->update(['duplicato_suggerito_id' => $importato->id]);
        $nostro->confermaDuplicato();
        $nostro->refresh();

        $this->assertSame(782, (int) $nostro->eureka_destinazione_code);
        $this->assertSame("GOPPION CAFFE' SPA", $nostro->eureka_destinazione_label);
        $this->assertSame(10, (int) $nostro->eureka_stato_documento);
    }

    /**
     * Eureka spezza un intervento in due schede, una per macchina
     * (RT-2026-0618: disinstallazione su SL-695, installazione su SL-696).
     * Il rapportino nostro somiglia a entrambe, ma una stacca l'altra sugli
     * articoli in comune: restare zitti lasciava sei rapportini bloccati.
     */
    public function test_fra_due_candidati_propone_quello_che_stacca(): void
    {
        [$tenant, $tecnico, $cliente, $prodotto] = $this->scenario();
        $macchina = $this->macchina($tenant, $cliente, $prodotto, '3400000287541');

        $disinstallazione = Material::create([
            'code' => 'DISIN/RITIRO', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Disinstallazione',
        ]);
        $installazione = Material::create([
            'code' => 'INST', 'source' => Material::SOURCE_EUREKA,
            'tenant_id' => $tenant->id, 'category' => 'Eureka', 'type' => 'Installazione',
        ]);

        // Il nostro: una visita sola, l'installazione fra i materiali.
        $nostro = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_MANUALE, '2026-08-10');
        ServiceReportMaterial::create(['service_report_id' => $nostro->id, 'material_id' => $installazione->id, 'quantity' => 1]);

        // Le due schede di Eureka, stessa macchina e stesso giorno.
        $schedaInstallazione = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-10', 17706);
        ServiceReportMaterial::create(['service_report_id' => $schedaInstallazione->id, 'material_id' => $installazione->id, 'quantity' => 1]);

        $schedaDisinstallazione = $this->rapportino($tenant, $cliente, $tecnico, $macchina, ServiceReport::SOURCE_EUREKA, '2026-08-10', 17704);
        ServiceReportMaterial::create(['service_report_id' => $schedaDisinstallazione->id, 'material_id' => $disinstallazione->id, 'quantity' => 1]);

        $this->proponi($tenant);

        $nostro->refresh();
        $this->assertSame(
            $schedaInstallazione->id,
            $nostro->duplicato_suggerito_id,
            'deve vincere la scheda che condivide l\'articolo',
        );
    }

    private function proponi(Tenant $tenant): array
    {
        $metodo = new \ReflectionMethod(\App\Support\Gestionale\GestionaleSyncRunner::class, 'proponiDoppioniRapportini');
        $metodo->setAccessible(true);

        return $metodo->invoke(new \App\Support\Gestionale\GestionaleSyncRunner($tenant));
    }

}
