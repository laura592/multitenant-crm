<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignProductBrandsTest extends TestCase
{
    use RefreshDatabase;

    private function seedBrands(): void
    {
        foreach (['Franke', 'Dalla Corte', 'Jura', 'Universale/Accessori'] as $name) {
            Brand::firstOrCreate(['name' => $name]);
        }
    }

    public function test_assigns_brand_by_source_and_sku(): void
    {
        $this->seedBrands();

        $franke = Product::create(['sku' => 'A600-NM-1G-H1', 'type' => Product::TYPE_MACHINE, 'name' => 'A600', 'source' => Product::SOURCE_FRANKE]);
        $dallaCorte = Product::create(['sku' => 'DC-ONE', 'type' => Product::TYPE_MACHINE, 'name' => 'DC One', 'source' => Product::SOURCE_THIRD_PARTY]);
        $jura = Product::create(['sku' => 'LEGACY-232', 'type' => Product::TYPE_OPTION, 'name' => 'Cool Control', 'source' => Product::SOURCE_THIRD_PARTY]);
        $universale = Product::create(['sku' => 'MAT', 'type' => Product::TYPE_MACHINE, 'name' => 'Sistema Brita', 'source' => Product::SOURCE_THIRD_PARTY]);
        $unknown = Product::create(['sku' => 'XYZ-1', 'type' => Product::TYPE_OPTION, 'name' => 'Mai visto prima', 'source' => Product::SOURCE_THIRD_PARTY]);

        $this->artisan('products:assign-brands')->assertSuccessful();

        $this->assertSame('Franke', $franke->fresh()->brand->name);
        $this->assertSame('Dalla Corte', $dallaCorte->fresh()->brand->name);
        $this->assertSame('Jura', $jura->fresh()->brand->name);
        $this->assertSame('Universale/Accessori', $universale->fresh()->brand->name);
        $this->assertNull($unknown->fresh()->brand_id);
    }

    public function test_is_idempotent(): void
    {
        $this->seedBrands();

        $franke = Product::create(['sku' => 'A600-NM-1G-H1', 'type' => Product::TYPE_MACHINE, 'name' => 'A600', 'source' => Product::SOURCE_FRANKE]);

        $this->artisan('products:assign-brands')->assertSuccessful();
        $this->artisan('products:assign-brands')->assertSuccessful();

        $this->assertSame('Franke', $franke->fresh()->brand->name);
    }

    public function test_fails_when_brands_not_seeded(): void
    {
        $this->artisan('products:assign-brands')->assertFailed();
    }
}
