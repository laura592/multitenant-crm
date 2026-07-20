<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductOptionSlot;
use App\Models\ProductOptionSlotItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecategorizeProductOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reassigns_known_products_from_other_to_the_right_slot(): void
    {
        $machine = Product::create(['sku' => 'M1', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina Test']);
        $grinderOption = Product::create(['sku' => 'G1', 'type' => Product::TYPE_OPTION, 'name' => '2° Macinacaffè']);
        $steamOption = Product::create(['sku' => 'S1', 'type' => Product::TYPE_OPTION, 'name' => 'Lancia vapore S1']);
        $unknownOption = Product::create(['sku' => 'U1', 'type' => Product::TYPE_OPTION, 'name' => 'Opzione mai vista prima']);

        $other = ProductOptionSlot::create(['product_id' => $machine->id, 'slot_name' => 'other', 'label' => 'Other']);

        foreach ([$grinderOption, $steamOption, $unknownOption] as $option) {
            $other->items()->create(['component_product_id' => $option->id]);
        }

        $this->artisan('products:recategorize-options')->assertSuccessful();

        $grinderSlot = ProductOptionSlot::where('product_id', $machine->id)->where('slot_name', 'grinder')->first();
        $steamSlot = ProductOptionSlot::where('product_id', $machine->id)->where('slot_name', 'steam')->first();

        $this->assertNotNull($grinderSlot, 'Deve essere stato creato lo slot "grinder" per la macchina');
        $this->assertTrue($grinderSlot->items()->where('component_product_id', $grinderOption->id)->exists());

        $this->assertNotNull($steamSlot, 'Deve essere stato creato lo slot "steam" per la macchina');
        $this->assertTrue($steamSlot->items()->where('component_product_id', $steamOption->id)->exists());

        // sconosciuta: resta in "other", mai persa silenziosamente
        $this->assertTrue($other->items()->where('component_product_id', $unknownOption->id)->exists());
    }

    public function test_is_idempotent(): void
    {
        $machine = Product::create(['sku' => 'M1', 'type' => Product::TYPE_MACHINE, 'name' => 'Macchina Test']);
        $grinderOption = Product::create(['sku' => 'G1', 'type' => Product::TYPE_OPTION, 'name' => '2° Macinacaffè']);

        $other = ProductOptionSlot::create(['product_id' => $machine->id, 'slot_name' => 'other', 'label' => 'Other']);
        $other->items()->create(['component_product_id' => $grinderOption->id]);

        $this->artisan('products:recategorize-options')->assertSuccessful();
        $this->artisan('products:recategorize-options')->assertSuccessful();

        $this->assertSame(1, ProductOptionSlotItem::count());
    }
}
