<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Filament\Resources\QuoteResource\RelationManagers\QuoteProductsRelationManager;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Su una riga di preventivo, quantita', prezzo, sconto e IVA sono NOT NULL
 * a database. Svuotare il campo mandava NULL, l'insert falliva e l'utente
 * vedeva solo "Qualcosa e' andato storto" senza sapere cosa avesse
 * sbagliato.
 *
 * Un campo sconto vuoto vuol dire "nessuno sconto", non "errore".
 */
class QuoteProductEmptyFieldsTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_svuotare_lo_sconto_di_una_riga_lo_salva_a_zero(): void
    {
        [$tenant, $quote, $riga] = $this->preparaPreventivoConRiga(sconto: 15);

        Livewire::test(QuoteProductsRelationManager::class, [
            'ownerRecord' => $quote,
            'pageClass' => EditQuote::class,
        ])
            ->callTableAction('edit', $riga, data: [
                'quantity' => 1,
                'price' => 100,
                'discount' => '',   // l'utente cancella lo sconto
                'tax' => 22,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('0', (string) (int) $riga->fresh()->discount,
            'Uno sconto svuotato deve diventare zero, non far fallire il salvataggio.');
    }

    public function test_svuotare_lo_sconto_ricalcola_i_totali_del_preventivo(): void
    {
        [$tenant, $quote, $riga] = $this->preparaPreventivoConRiga(sconto: 50);

        $quote->updateTotal();
        $this->assertEqualsWithDelta(50.0, (float) $quote->fresh()->subtotal, 0.01,
            'Con sconto 50% su 100 euro l\'imponibile di partenza deve essere 50.');

        Livewire::test(QuoteProductsRelationManager::class, [
            'ownerRecord' => $quote,
            'pageClass' => EditQuote::class,
        ])->callTableAction('edit', $riga, data: [
            'quantity' => 1,
            'price' => 100,
            'discount' => '',
            'tax' => 22,
        ]);

        $this->assertEqualsWithDelta(100.0, (float) $quote->fresh()->subtotal, 0.01,
            'Tolto lo sconto, l\'imponibile deve risalire da solo senza premere "Ricalcola totali".');
    }

    /** @return array{0: Tenant, 1: Quote, 2: QuoteProduct} */
    private function preparaPreventivoConRiga(int $sconto): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tester',
            'email' => 'tester@alex.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'amministratore');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $cliente = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar di Prova',
        ]);

        $prodotto = Product::create([
            'sku' => 'TEST-1',
            'type' => Product::TYPE_MACHINE,
            'name' => 'Macchina di prova',
        ]);

        $quote = Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $cliente->id,
            'number' => 'PRV-TEST-0001',
            'date' => now(),
            'status' => 'bozza',
        ]);

        $riga = QuoteProduct::create([
            'quote_id' => $quote->id,
            'product_id' => $prodotto->id,
            'quantity' => 1,
            'price' => 100,
            'discount' => $sconto,
            'tax' => 22,
            'total' => 100 - $sconto,
        ]);

        return [$tenant, $quote, $riga];
    }
}
