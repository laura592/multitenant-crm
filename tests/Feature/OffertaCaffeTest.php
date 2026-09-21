<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\ProdottoCaffe;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Pdf\OffertaCaffePdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * L'offerta caffe' (21/09/2026): un documento a parte dal preventivo, coi
 * prezzi del listino caffe' ritoccabili per il singolo cliente e senza
 * quantita', perche' quanti chili prendera' non si sa.
 */
class OffertaCaffeTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create([
            'tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale', 'city' => 'Jesolo',
        ]);
    }

    private function entraCome(string $ruolo): void
    {
        $utente = User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($ruolo),
            'email' => "{$ruolo}@alex.it", 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $this->tenant, $ruolo);

        $this->actingAs($utente);
        Filament::setTenant($this->tenant);
    }

    /** Il listino dell'ufficio entra con la migrazione, cosi' arriva col deploy. */
    public function test_il_listino_iniziale_c_e(): void
    {
        $this->assertSame(9, ProdottoCaffe::count());
        $this->assertEquals(21.00, ProdottoCaffe::where('nome', 'Caffè Lyrae Bucintoro')->value('prezzo'));
        $this->assertSame(
            ['caffe' => 6, 'liofilizzati' => 3],
            ProdottoCaffe::query()->get()->countBy('gruppo')->sortKeys()->all(),
        );
    }

    /** Un prodotto spento resta in archivio ma non entra nelle nuove offerte. */
    public function test_un_prodotto_spento_non_si_offre(): void
    {
        ProdottoCaffe::where('nome', 'Cioccolato')->update(['attivo' => false]);

        $nomi = array_column(OffertaCaffePdf::righeDaListino(), 'nome');

        $this->assertNotContains('Cioccolato', $nomi);
        $this->assertCount(8, $nomi);
    }

    /**
     * I prezzi arrivano nel form gia' in formato italiano. Senza, la maschera
     * dei soldi legge il punto come migliaia: 17.80 diventerebbe 1.780.
     */
    public function test_nel_form_i_prezzi_arrivano_giusti(): void
    {
        $this->entraCome('admin');

        $righe = Livewire::test(ViewCustomer::class, ['record' => $this->cliente->getRouteKey()])
            ->mountAction('offerta_caffe')
            ->get('mountedActionsData.0.righe');

        $cioccolato = collect($righe)->firstWhere('nome', 'Cioccolato');

        $this->assertSame('17,80', $cioccolato['prezzo']);
    }

    public function test_crea_il_pdf_e_lo_apre_in_una_scheda_nuova(): void
    {
        $this->entraCome('admin');

        $componente = Livewire::test(ViewCustomer::class, ['record' => $this->cliente->getRouteKey()])
            ->callAction('offerta_caffe')
            ->assertHasNoActionErrors();

        $effetti = json_encode($componente->effects, JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('window.open', $effetti);
        $this->assertStringContainsString('/stampe/', $effetti);
    }

    /**
     * Il prezzo ritoccato per il cliente finisce sul foglio, e il listino non
     * cambia: e' un prezzo per questa offerta, non un nuovo prezzo di listino.
     */
    public function test_il_prezzo_ritoccato_va_sul_foglio_e_non_nel_listino(): void
    {
        $righe = OffertaCaffePdf::righeDaListino();
        $righe[0]['prezzo'] = 13.50; // Marco Polo, 15 a listino

        $html = $this->foglio($righe, 'Prezzi IVA esclusa.');

        $this->assertStringContainsString('€ 13,50', $html);
        $this->assertStringNotContainsString('€ 15,00', $html);
        $this->assertEquals(15.00, ProdottoCaffe::where('nome', 'Caffè Lyrae Marco Polo')->value('prezzo'));
    }

    /** Due gruppi, nell'ordine del listino: prima il caffe', poi i liofilizzati. */
    public function test_il_foglio_divide_caffe_e_liofilizzati(): void
    {
        $html = $this->foglio(OffertaCaffePdf::righeDaListino());

        $this->assertStringContainsString('Caffè Lyrae Bucintoro', $html);
        $this->assertStringContainsString('Orzo in polvere', $html);
        $this->assertLessThan(
            strpos($html, 'Prodotti liofilizzati'),
            strpos($html, '>Caffè<'),
            'Il caffè viene prima dei liofilizzati.',
        );
    }

    /** Il prezzo si legge giusto comunque sia scritto. */
    public function test_i_prezzi_si_leggono_in_ogni_formato(): void
    {
        $this->assertSame(17.8, OffertaCaffePdf::prezzo('17,80'));
        $this->assertSame(17.8, OffertaCaffePdf::prezzo('17.80'));
        $this->assertSame(17.8, OffertaCaffePdf::prezzo(17.8));
        $this->assertSame(1234.5, OffertaCaffePdf::prezzo('1.234,50'));
    }

    /** Niente quantita' ne' totale: e' un listino per il cliente, non una fornitura. */
    public function test_il_foglio_non_ha_quantita_ne_totale(): void
    {
        $html = $this->foglio(OffertaCaffePdf::righeDaListino());

        $this->assertStringNotContainsString('Quantità', $html);
        $this->assertStringNotContainsString('Totale', $html);
    }

    /** Il pulsante lo vede chi legge il listino, i tecnici no. */
    public function test_i_tecnici_non_vedono_il_pulsante(): void
    {
        $this->entraCome('dipendente');

        Livewire::test(ViewCustomer::class, ['record' => $this->cliente->getRouteKey()])
            ->assertActionHidden('offerta_caffe');
    }

    public function test_l_amministrazione_stampa_ma_non_cambia_il_listino(): void
    {
        $this->entraCome('amministrazione');

        Livewire::test(ViewCustomer::class, ['record' => $this->cliente->getRouteKey()])
            ->assertActionVisible('offerta_caffe');

        $this->assertFalse(auth()->user()->can('update', ProdottoCaffe::first()));
    }

    private function foglio(array $righe, ?string $note = null): string
    {
        $this->entraCome('admin');

        // Il contenuto si guarda sulla vista: dompdf comprime lo stream e
        // cercarci dentro un prezzo non direbbe niente.
        return view('pdf.offerta-caffe', OffertaCaffePdf::datiVista(
            $this->cliente, $righe, now()->addDays(30), $note,
        ))->render();
    }
}
