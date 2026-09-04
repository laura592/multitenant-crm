<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class ProductCreationTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    /**
     * Il ruolo "partner" (es. Gifar) vede il catalogo condiviso Franke in
     * sola lettura, non lo gestisce: chi crea un prodotto/accessorio proprio
     * del tenant e' un "admin" di tenant (docs/architecture.md §5.3).
     */
    public function test_tenant_admin_creating_a_product_gets_it_assigned_to_their_own_tenant(): void
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
                'sku' => 'GIFAR-CUSTOM-1',
                'name' => 'Accessorio proprio Gifar',
                'prices' => [['price' => 100]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('sku', 'GIFAR-CUSTOM-1')->firstOrFail();
        $this->assertSame($tenant->id, $product->tenant_id);
    }

    public function test_super_admin_can_create_a_shared_product_visible_to_all_tenants(): void
    {
        $master = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $staff = User::create([
            'tenant_id' => null, 'is_super_admin' => true, 'name' => 'Staff Alex', 'email' => 'staff@alex.it', 'password' => bcrypt('password'),
        ]);

        $this->actingAs($staff);
        Filament::setTenant($master);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'type' => Product::TYPE_MACHINE,
                'sku' => 'SHARED-MACHINE-1',
                'name' => 'Macchina catalogo condiviso',
                'tenant_id' => null, // esplicito: condiviso con tutti i partner
                'prices' => [['price' => 5000]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('sku', 'SHARED-MACHINE-1')->firstOrFail();
        $this->assertNull($product->tenant_id, 'Un prodotto condiviso deve restare senza tenant, non essere assegnato al master');
    }
}
