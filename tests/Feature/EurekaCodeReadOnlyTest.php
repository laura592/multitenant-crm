<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class EurekaCodeReadOnlyTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_product_gestionale_code_cannot_be_edited_from_the_form(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'ICON 2GR',
            'sku' => 'GIFAR-ICON-2GR',
            'type' => Product::TYPE_MACHINE,
            'gestionale_code' => 12345,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Il campo e' disabled()->dehydrated(false): anche forzando un valore
        // diverso via fillForm(), il save non deve scriverlo (si collega solo
        // tramite l'azione "Cerca su Eureka", non a mano dal form).
        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['gestionale_code' => 99999])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(12345, $product->fresh()->gestionale_code);
    }
}
