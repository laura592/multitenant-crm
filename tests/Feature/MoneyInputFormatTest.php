<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class MoneyInputFormatTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_italian_formatted_price_is_saved_as_correct_decimal_on_create(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'type' => Product::TYPE_ACCESSORY,
                'sku' => 'GIFAR-MASKED-1',
                'name' => 'Accessorio prezzo mascherato',
                'prices' => [['price' => '1.234,56']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('sku', 'GIFAR-MASKED-1')->firstOrFail();
        $this->assertEquals(1234.56, (float) $product->prices()->first()->price);
    }

    public function test_italian_formatted_price_is_saved_as_correct_decimal_on_edit(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $this->actingAs($user);
        Filament::setTenant($tenant);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'type' => Product::TYPE_ACCESSORY,
            'sku' => 'GIFAR-EDIT-1',
            'name' => 'Accessorio da modificare',
        ]);
        $priceRecord = $product->prices()->create(['price' => 575.00]);

        $livewire = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $repeaterKey = array_key_first($livewire->instance()->data['prices']);

        $livewire
            ->fillForm([
                'prices' => [
                    $repeaterKey => ['price' => '2.500,10'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $priceRecord->refresh();
        $this->assertEquals(2500.10, (float) $priceRecord->price);
    }
}
