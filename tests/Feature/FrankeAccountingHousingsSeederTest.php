<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use Database\Seeders\FrankeAccountingHousingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gli alloggiamenti conteggio mancanti dal listino Franke 2026: senza di
 * questi, quotare una gettoniera su un alloggiamento senza VIP-1 costringeva
 * a usare la riga con VIP-1, 355 euro piu' cara.
 */
class FrankeAccountingHousingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_the_missing_housings_with_listino_codes_and_prices(): void
    {
        Category::create(['name' => 'Accessori Franke']);

        $this->seed(FrankeAccountingHousingsSeeder::class);

        $conGettoniera = Product::query()->where('sku', '560.0550.061')->firstOrFail();
        $this->assertSame(Product::TYPE_ACCESSORY, $conGettoniera->type);
        $this->assertSame(Product::SOURCE_FRANKE, $conGettoniera->source);
        $this->assertNull($conGettoniera->tenant_id, 'Catalogo condiviso, come gli altri articoli Franke.');
        $this->assertSame('Accessori Franke', $conGettoniera->category->name);
        $this->assertSame('2177.00', $conGettoniera->getCurrentPrice()?->price);

        // La differenza fra le due famiglie e' l'interfaccia: +355.
        $conVip = Product::query()->where('sku', '560.0543.637')->first();
        $this->assertNull($conVip, 'Questo esisteva gia\' a catalogo: il seeder non deve ricrearlo.');

        $this->assertSame(8, Product::query()->count(), 'Solo le otto righe mancanti del listino.');
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        Category::create(['name' => 'Accessori Franke']);

        $this->seed(FrankeAccountingHousingsSeeder::class);
        $this->seed(FrankeAccountingHousingsSeeder::class);

        $this->assertSame(8, Product::query()->count());
        $this->assertSame(8, ProductPrice::query()->count());
    }

    public function test_it_renames_the_two_housings_that_looked_like_bare_coin_devices(): void
    {
        Category::create(['name' => 'Accessori Franke']);
        $gettoniera = Product::create([
            'sku' => '560.0543.637',
            'type' => Product::TYPE_ACCESSORY,
            'name' => 'Gettoniera G13 (AC200)',
            'source' => Product::SOURCE_FRANKE,
        ]);

        $this->seed(FrankeAccountingHousingsSeeder::class);

        $this->assertSame(
            'Alloggiamento conteggio AC200 con gettoniera G13 (VIP-1)',
            $gettoniera->fresh()->name,
        );
    }
}
