<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductOptionSlot;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Assistenza\ContrattoAssistenza;
use App\Support\Assistenza\ContrattoAssistenzaPdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * I contratti di assistenza Franke nel preventivo (21/09/2026).
 *
 * Full-Service 10% del listino di macchina, sistema latte e optional;
 * Easy-Service 5% di macchina e sistema latte, attivabile dal secondo anno.
 * Il frigorifero fa parte del sistema latte (indicazione dell'ufficio).
 */
class ContrattoAssistenzaTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private Quote $quote;

    private Product $macchina;

    private Product $frigo;

    private Product $optional;

    private ProductOptionSlot $slotFrigo;

    private ProductOptionSlot $slotOptional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        // I modelli dei contratti stanno in Documenti: qui quelli dell'ufficio.
        Storage::fake('public');
        foreach (PriceList::CONTRATTI as $tipo => $categoria) {
            Storage::disk('public')->put("price-lists/{$tipo}.pdf", file_get_contents(base_path("tests/fixtures/contratti/{$tipo}-service.pdf")));
            PriceList::create(['category' => $categoria, 'name' => "Contratto {$tipo}", 'file_path' => "price-lists/{$tipo}.pdf"]);
        }

        $franke = Brand::create(['name' => 'Franke']);
        $famiglia = ProductFamily::create(['name' => 'A600']);

        // Numeri tondi: 10.000 macchina, 1.500 frigo, 800 optional.
        $this->macchina = $this->prodotto('A600-1G', 'A600 FM EC 1G H1', Product::TYPE_MACHINE, 10000, [
            'product_family_id' => $famiglia->id, 'brand_id' => $franke->id,
        ]);
        $this->frigo = $this->prodotto('SU05', 'SU05 EC - Unità di raffreddamento 5l', Product::TYPE_OPTION, 1500);
        $this->optional = $this->prodotto('CW', 'Scaldatazze', Product::TYPE_OPTION, 800);

        $this->slotFrigo = $this->slot('latte', $this->frigo);
        $this->slotOptional = $this->slot('accessori', $this->optional);

        $cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
        $this->quote = Quote::create(['tenant_id' => $this->tenant->id, 'customer_id' => $cliente->id, 'date' => now()]);
    }

    private function prodotto(string $sku, string $nome, string $tipo, float $prezzo, array $extra = []): Product
    {
        $p = Product::create(['sku' => $sku, 'name' => $nome, 'type' => $tipo, ...$extra]);
        $p->prices()->create(['price' => $prezzo]);

        return $p;
    }

    private function slot(string $nome, Product $componente): ProductOptionSlot
    {
        $slot = ProductOptionSlot::create([
            'product_id' => $this->macchina->id, 'slot_name' => $nome, 'label' => ucfirst($nome), 'max_qty' => 1,
        ]);
        $slot->items()->create(['component_product_id' => $componente->id]);

        return $slot;
    }

    private function entraCome(string $ruolo): User
    {
        $utente = User::where('email', "{$ruolo}@alex.it")->first();

        if (! $utente) {
            $utente = User::create([
                'tenant_id' => $this->tenant->id, 'name' => ucfirst($ruolo),
                'email' => "{$ruolo}@alex.it", 'password' => bcrypt('x'),
            ]);
            $this->giveRole($utente, $this->tenant, $ruolo);
        }

        $this->actingAs($utente);
        Filament::setTenant($this->tenant);

        return $utente;
    }

    /** Configura la macchina col frigo e lo scaldatazze, e il contratto scelto. */
    private function configura(string $contratto): QuoteProduct
    {
        $this->entraCome('admin');

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->macchina->product_family_id,
                'machine_product_id' => $this->macchina->id,
                "slot_{$this->slotFrigo->id}" => $this->frigo->id,
                "slot_{$this->slotOptional->id}" => $this->optional->id,
                'contratto_assistenza' => $contratto,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        return $this->quote->quoteProducts()->whereNull('parent_quote_product_id')->firstOrFail();
    }

    public function test_full_e_il_dieci_per_cento_di_tutto(): void
    {
        $c = ContrattoAssistenza::dellaRiga($this->configura(ContrattoAssistenza::FULL));

        $this->assertEquals(12300, $c['base']);
        $this->assertEquals(1230, $c['canone']);
    }

    /** Il frigo e' sistema latte: entra nell'Easy. Lo scaldatazze no. */
    public function test_easy_e_il_cinque_per_cento_di_macchina_e_frigo(): void
    {
        $c = ContrattoAssistenza::dellaRiga($this->configura(ContrattoAssistenza::EASY));

        $this->assertEquals(11500, $c['base']);
        $this->assertEquals(575, $c['canone']);
        $this->assertSame(['macchina', 'sistema latte'], $c['voci']->pluck('perche')->all());
    }

    /** Il canone si paga ogni anno: sommarlo al preventivo lo falserebbe. */
    public function test_il_canone_non_entra_nel_totale(): void
    {
        $this->configura(ContrattoAssistenza::FULL);

        // 12.300 + 22% = 15.006: nessun 1.230 dentro.
        $this->assertEquals(15006.00, (float) $this->quote->fresh()->total);
    }

    /** Il contratto e' sul listino, non sul prezzo scontato (art. 8 e 9). */
    public function test_lo_sconto_non_abbassa_il_canone(): void
    {
        $riga = $this->configura(ContrattoAssistenza::FULL);
        $riga->update(['discount' => 20]);
        $riga->options()->update(['discount' => 20]);

        $this->assertEquals(1230, ContrattoAssistenza::dellaRiga($riga->fresh())['canone']);
    }

    public function test_senza_contratto_la_riga_non_ne_ha(): void
    {
        $this->assertNull(ContrattoAssistenza::dellaRiga($this->configura('')));
    }

    /** I contratti sono Franke: una Bianchi il contratto non lo salva, qualunque cosa arrivi dal form. */
    public function test_su_una_macchina_non_franke_non_si_salva(): void
    {
        $this->macchina->update(['brand_id' => Brand::create(['name' => 'Bianchi'])->id]);

        $riga = $this->configura(ContrattoAssistenza::FULL);

        $this->assertNull($riga->contratto_assistenza);
    }

    public function test_riaprendo_la_configurazione_il_contratto_c_e_ancora(): void
    {
        $riga = $this->configura(ContrattoAssistenza::EASY);

        $stato = (new \ReflectionMethod(\App\Filament\Actions\ConfigureMachineAction::class, 'fillFormForEdit'))
            ->invoke(null, $riga);

        $this->assertSame(ContrattoAssistenza::EASY, $stato['contratto_assistenza']);
    }

    public function test_il_preventivo_pdf_mostra_il_canone_a_parte(): void
    {
        $this->configura(ContrattoAssistenza::EASY);

        $html = view('pdf.quote', [
            'quote' => $this->quote->fresh()->load(['customer', 'quoteProducts.product', 'quoteProducts.options.product']),
            'tenant' => $this->tenant,
        ])->render();

        $this->assertStringContainsString('Easy-Service', $html);
        $this->assertStringContainsString('€ 575,00 + IVA l\'anno', $html);
        $this->assertStringContainsString('Attivabile dal secondo anno', $html);
        $this->assertStringContainsString('non compreso nel totale', $html);
    }

    /**
     * Il contratto e' il PDF dell'ufficio importato cosi' com'e', con davanti
     * la pagina dei dati: Full 7 pagine + 1, Easy 5 + 1.
     */
    public function test_il_contratto_e_il_modello_dell_ufficio_piu_una_pagina(): void
    {
        foreach ([ContrattoAssistenza::FULL => 8, ContrattoAssistenza::EASY => 6] as $tipo => $pagine) {
            $this->quote->quoteProducts()->forceDelete();
            $riga = $this->configura($tipo);

            $pdf = ContrattoAssistenzaPdf::crea($riga);

            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertSame($pagine, (new \setasign\Fpdi\Tcpdf\Fpdi())->setSourceFile(
                \setasign\Fpdi\PdfParser\StreamReader::createByString($pdf)
            ), $tipo);
        }
    }

    public function test_la_pagina_dei_dati_dice_cliente_voci_e_canone(): void
    {
        $riga = $this->configura(ContrattoAssistenza::FULL);

        $html = view('pdf.contratto-assistenza-dati', ContrattoAssistenzaPdf::datiVista($riga, ContrattoAssistenza::dellaRiga($riga)))->render();

        $this->assertStringContainsString('Bar Centrale', $html);
        $this->assertStringContainsString('SU05 EC - Unità di raffreddamento 5l', $html);
        $this->assertStringContainsString('€ 12.300,00', $html);
        $this->assertStringContainsString('€ 1.230,00', $html);
        // Macchina senza W3: il Full chiede il trattamento acqua.
        $this->assertStringContainsString('trattamento acqua', $html);
    }

    public function test_si_scarica_dal_preventivo(): void
    {
        $riga = $this->configura(ContrattoAssistenza::FULL);

        $this->get(route('quotes.contratto', [$this->quote, $riga]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /** Senza il modello in Documenti il contratto non si genera, e lo si dice. */
    public function test_senza_modello_in_documenti_il_contratto_non_si_scarica(): void
    {
        $riga = $this->configura(ContrattoAssistenza::FULL);
        PriceList::where('category', PriceList::CONTRATTO_FULL)->delete();

        $this->get(route('quotes.contratto', [$this->quote, $riga]))
            ->assertNotFound()
            ->assertSee('Manca il modello del contratto Full-Service');

        Livewire::test(\App\Filament\Resources\QuoteResource\RelationManagers\QuoteProductsRelationManager::class, [
            'ownerRecord' => $this->quote, 'pageClass' => EditQuote::class,
        ])->assertTableActionDisabled('contratto_pdf', $riga);
    }

    /** La riga deve essere del preventivo nell'indirizzo. */
    public function test_non_si_scarica_la_riga_di_un_altro_preventivo(): void
    {
        $riga = $this->configura(ContrattoAssistenza::FULL);
        $altro = Quote::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->quote->customer_id, 'date' => now()]);

        $this->get(route('quotes.contratto', [$altro, $riga]))->assertNotFound();
    }

    /** I tecnici i preventivi non li vedono, e quindi nemmeno i contratti. */
    public function test_un_tecnico_non_lo_scarica(): void
    {
        $riga = $this->configura(ContrattoAssistenza::FULL);
        $this->entraCome('dipendente');

        $this->get(route('quotes.contratto', [$this->quote, $riga]))->assertForbidden();
    }

    /** Nel wizard le due scelte arrivano gia' con la cifra, calcolata su quello che si sta configurando. */
    public function test_il_wizard_propone_i_canoni_gia_calcolati(): void
    {
        $this->entraCome('admin');

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->macchina->product_family_id,
                'machine_product_id' => $this->macchina->id,
                "slot_{$this->slotFrigo->id}" => $this->frigo->id,
                "slot_{$this->slotOptional->id}" => $this->optional->id,
            ])
            ->assertSee('Full-Service — € 1.230,00 l&#039;anno', false)
            ->assertSee('Easy-Service — € 575,00 l&#039;anno · attivabile dal secondo anno', false)
            ->assertSee('Richiede un sistema di trattamento acqua');
    }

    /**
     * Visto sul preventivo vero PRV-2026-0062: un contratto letto su una riga
     * che non e' una macchina calcolava un Full-Service sull'installazione.
     */
    public function test_su_una_riga_che_non_e_una_macchina_non_c_e_contratto(): void
    {
        $installazione = $this->prodotto('INST', 'Installazione', Product::TYPE_SERVICE, 1000);
        $riga = $this->quote->quoteProducts()->create([
            'product_id' => $installazione->id, 'quantity' => 1, 'price' => 1000, 'discount' => 0, 'tax' => 22,
        ]);
        $riga->contratto_assistenza = ContrattoAssistenza::FULL;

        $this->assertNull(ContrattoAssistenza::dellaRiga($riga));
    }

    public function test_w3_non_chiede_il_trattamento_acqua(): void
    {
        $w3 = new Product(['name' => 'A300 NM 1G H1 W3']);
        $w4 = new Product(['name' => 'A300 NM 1G H1 W4']);

        $this->assertFalse(ContrattoAssistenza::serveTrattamentoAcqua(ContrattoAssistenza::FULL, $w3));
        $this->assertTrue(ContrattoAssistenza::serveTrattamentoAcqua(ContrattoAssistenza::FULL, $w4));
        $this->assertFalse(ContrattoAssistenza::serveTrattamentoAcqua(ContrattoAssistenza::EASY, $w4));
    }
}
