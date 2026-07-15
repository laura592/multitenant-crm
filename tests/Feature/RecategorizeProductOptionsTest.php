<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCompatibility;
use App\Models\ProductOptionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecategorizeProductOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassigns_known_products_from_other_to_the_right_group(): void
    {
        $other = ProductOptionGroup::create(['name' => 'other', 'label' => 'Other', 'selection_type' => 'multiple']);
        $grinder = ProductOptionGroup::create(['name' => 'grinder', 'label' => 'Grinder', 'selection_type' => 'multiple']);
        $steam = ProductOptionGroup::create(['name' => 'steam', 'label' => 'Steam', 'selection_type' => 'multiple']);

        $machine = Product::create(['sku' => 'M1', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina Test']);
        $grinderOption = Product::create(['sku' => 'G1', 'type' => Product::TYPE_OPTION, 'name' => '2° Macinacaffè']);
        $steamOption = Product::create(['sku' => 'S1', 'type' => Product::TYPE_OPTION, 'name' => 'Lancia vapore S1']);
        $unknownOption = Product::create(['sku' => 'U1', 'type' => Product::TYPE_OPTION, 'name' => 'Opzione mai vista prima']);

        foreach ([$grinderOption, $steamOption, $unknownOption] as $option) {
            ProductCompatibility::create([
                'base_product_id' => $machine->id,
                'option_product_id' => $option->id,
                'option_group_id' => $other->id,
                'constraint_type' => 'compatible',
            ]);
        }

        $this->artisan('products:recategorize-options')->assertSuccessful();

        $this->assertSame($grinder->id, ProductCompatibility::where('option_product_id', $grinderOption->id)->first()->option_group_id);
        $this->assertSame($steam->id, ProductCompatibility::where('option_product_id', $steamOption->id)->first()->option_group_id);
        // sconosciuta: resta in "other", mai persa silenziosamente
        $this->assertSame($other->id, ProductCompatibility::where('option_product_id', $unknownOption->id)->first()->option_group_id);
    }

    public function test_is_idempotent(): void
    {
        $other = ProductOptionGroup::create(['name' => 'other', 'label' => 'Other', 'selection_type' => 'multiple']);
        ProductOptionGroup::create(['name' => 'grinder', 'label' => 'Grinder', 'selection_type' => 'multiple']);

        $machine = Product::create(['sku' => 'M1', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina Test']);
        $grinderOption = Product::create(['sku' => 'G1', 'type' => Product::TYPE_OPTION, 'name' => '2° Macinacaffè']);

        ProductCompatibility::create([
            'base_product_id' => $machine->id,
            'option_product_id' => $grinderOption->id,
            'option_group_id' => $other->id,
            'constraint_type' => 'compatible',
        ]);

        $this->artisan('products:recategorize-options')->assertSuccessful();
        $this->artisan('products:recategorize-options')->assertSuccessful();

        $this->assertSame(1, ProductCompatibility::count());
    }
}
