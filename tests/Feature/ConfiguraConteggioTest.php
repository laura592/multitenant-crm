<?php

namespace Tests\Feature;

use App\Filament\Actions\ConteggioConfigurator;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPrice;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\FrankeAccountingHousingsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La guida alla scelta del sistema di conteggio: gli articoli a catalogo sono
 * 83 e la differenza fra "con VIP-1", "senza interfaccia" e "predisposto per
 * il lettore" non si legge dal nome di un selettore prodotti generico.
 *
 * Il punto fermo: si finisce sempre su un prodotto REALE del listino, col suo
 * codice d'ordine — non su una somma di supplementi, che sarebbe giusta come
 * totale ma inordinabile da Franke.
 */
class ConfiguraConteggioTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private Quote $quote;

    private Product $macchina;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin',
            'email' => 'admin-conteggio@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $this->tenant, 'admin');

        Category::create(['name' => 'Accessori Franke']);

        // Le voci che c'erano gia' a catalogo col nome vecchio, piu' le
        // opzioni che il listino vende a parte. Il seeder gira dopo, come in
        // produzione: e' lui a uniformare i nomi.
        $this->prodotto('560.0543.637', 'Gettoniera G13 (AC200)', 2532);
        $this->prodotto('AC200-VIP1', 'Alloggiamento conteggio standard AC200 con VIP-1', 1840);
        $this->prodotto('560.0568.239', 'Coges [COGES ENGINE] con VIP-1 (AC200)', 1978);
        $this->prodotto('560.0597.990', 'Coges [COGES ENGINE] con VIP-1 (AC125)', 1143);
        $this->prodotto('OPT-CHIAVE-VENDITA', 'Interruttore a chiave per vendita libera e di prova', 245);
        $this->prodotto('OPT-GETTONI-100', 'Gettone per gettoniera/cambiamonete (100 pz.)', 125);
        $this->prodotto('560.0620.875', 'PM kit dosatore polvere SB1200', 73.30);

        $this->seed(FrankeAccountingHousingsSeeder::class);

        $famiglia = ProductFamily::create(['name' => 'A600']);
        $this->macchina = Product::create([
            'sku' => 'A600-1G-H1', 'type' => Product::TYPE_MACHINE, 'name' => 'A600 1G H1',
            'product_family_id' => $famiglia->id, 'source' => Product::SOURCE_FRANKE,
        ]);
        ProductPrice::create(['product_id' => $this->macchina->id, 'price' => 8255, 'valid_from' => '2026-01-01']);

        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
        $this->quote = Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id,
            'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);

        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function prodotto(string $sku, string $name, float $price): Product
    {
        $product = Product::create([
            'sku' => $sku, 'type' => Product::TYPE_ACCESSORY, 'name' => $name, 'source' => Product::SOURCE_FRANKE,
        ]);
        ProductPrice::create(['product_id' => $product->id, 'price' => $price, 'valid_from' => '2026-01-01']);

        return $product;
    }

    private function candidati(string $alloggiamento, bool $conLettore): array
    {
        return ConteggioConfigurator::candidati($alloggiamento, $conLettore)->pluck('sku')->all();
    }

    public function test_without_a_reader_it_proposes_only_the_housings_of_that_size(): void
    {
        $proposti = $this->candidati('AC200', conLettore: false);

        $this->assertContains('560.0543.637', $proposti, 'AC200 con gettoniera G13 e VIP-1');
        $this->assertContains('560.0550.061', $proposti, 'la stessa senza interfaccia');
        $this->assertContains('AC200-VIP1', $proposti, 'anche i nomi vecchi, dopo la rinomina del seeder');
        $this->assertNotContains('560.0568.239', $proposti, 'con lettore: e\' un\'altra risposta');
        $this->assertNotContains('560.0620.875', $proposti, 'i PM kit condividono il prefisso 560 ma non c\'entrano');
    }

    public function test_with_a_reader_it_proposes_the_per_reader_rows_of_that_housing(): void
    {
        $proposti = $this->candidati('AC200', conLettore: true);

        $this->assertSame(['560.0568.239'], $proposti);

        $suAC125 = $this->candidati('AC125', conLettore: true);
        $this->assertSame(['560.0597.990'], $suAC125, 'Il foro e\' sagomato sull\'alloggiamento, non solo sul lettore.');
    }

    public function test_the_su03_accounting_brings_its_cooling_unit_along(): void
    {
        $su03ec = $this->prodotto('SU03-EC', 'SU03 EC - Unità di raffreddamento 3l (a sinistra)', 1170);
        $conteggio = Product::query()->where('sku', '560.0678.327')->firstOrFail();

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->callAction('configureMachine', data: [
                'product_family_id' => $this->macchina->product_family_id,
                'machine_product_id' => $this->macchina->id,
                'alloggiamento' => 'SU03 CL',
                'con_lettore' => 'no',
                'conteggio_product_id' => $conteggio->id,
                'aggiungi_su03' => true,
            ])
            ->assertHasNoActionErrors();

        $righe = $this->quote->fresh()->quoteProducts;

        // 680 e' un supplemento, non il prezzo del sistema: da solo in
        // preventivo mancherebbero i 1.170 della SU03 EC. Piu' la macchina,
        // che ora arriva dallo stesso wizard.
        $this->assertCount(3, $righe);
        $this->assertTrue($righe->contains('product_id', $su03ec->id));
        $this->assertSame('12328.10', $this->quote->fresh()->total, '(8255 + 680 + 1170) + 22%');

        $this->assertStringContainsString(
            'Supplemento sull\'unita\' di raffreddamento SU03 EC',
            $conteggio->fresh()->description,
            'La nota del listino va anche sul prodotto, non solo nel wizard.',
        );
    }

    public function test_the_configuration_discount_reaches_the_accounting_lines_too(): void
    {
        $sistema = Product::query()->where('sku', '560.0543.637')->firstOrFail();

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->callAction('configureMachine', data: [
                'product_family_id' => $this->macchina->product_family_id,
                'machine_product_id' => $this->macchina->id,
                'configuration_discount' => 10,
                'alloggiamento' => 'AC200',
                'con_lettore' => 'no',
                'conteggio_product_id' => $sistema->id,
            ])
            ->assertHasNoActionErrors();

        $righe = $this->quote->fresh()->quoteProducts;

        // Lo sconto configurazione vale su tutto quello che il wizard ha
        // messo dentro, altrimenti il riepilogo mostrerebbe un totale che il
        // preventivo poi non ha.
        $this->assertSame(10, $righe->firstWhere('product_id', $sistema->id)->discount);
        $this->assertSame(10, $righe->firstWhere('product_id', $this->macchina->id)->discount);
    }

    public function test_it_adds_one_real_listino_line_plus_the_options(): void
    {
        $sistema = Product::query()->where('sku', '560.0543.637')->firstOrFail();

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->callAction('configureMachine', data: [
                'product_family_id' => $this->macchina->product_family_id,
                'machine_product_id' => $this->macchina->id,
                'alloggiamento' => 'AC200',
                'con_lettore' => 'no',
                'conteggio_product_id' => $sistema->id,
                'interruttore_chiave' => true,
                'gettoni' => 2,
            ])
            ->assertHasNoActionErrors();

        $righe = $this->quote->fresh()->quoteProducts;

        $this->assertCount(4, $righe, 'macchina + sistema + interruttore + gettoni');

        $riga = $righe->firstWhere('product_id', $sistema->id);
        $this->assertSame('2532.00', $riga->price, 'Prezzo di listino, non una somma di supplementi.');
        $this->assertNull($riga->parent_quote_product_id);

        $chiave = $righe->first(fn ($r) => $r->product->sku === 'OPT-CHIAVE-VENDITA');
        $this->assertSame($riga->id, $chiave->parent_quote_product_id, 'Le opzioni restano appese al sistema.');
        $this->assertSame('245.00', $chiave->price);

        $gettoni = $righe->first(fn ($r) => $r->product->sku === 'OPT-GETTONI-100');
        $this->assertSame('2.00', $gettoni->quantity);

        // 8255 + 2532 + 245 + 250 = 11282 imponibile, + 22% IVA
        $this->assertSame('13764.04', $this->quote->fresh()->total);
    }
}
