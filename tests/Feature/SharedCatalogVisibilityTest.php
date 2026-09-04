<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Regressione: il catalogo condiviso (tenant_id NULL) deve essere visibile
 * nel pannello a QUALSIASI tenant, non solo verificabile via Eloquent
 * diretto. Filament applica un proprio scoping automatico in aggiunta al
 * nostro global scope custom, con uguaglianza stretta che nasconderebbe
 * tutto cio' che e' condiviso se non disattivato esplicitamente
 * ($isScopedToTenant = false) sulle risorse di catalogo.
 */
class SharedCatalogVisibilityTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_shared_catalog_is_visible_in_the_panel_for_a_partner_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'partner');

        $category = Category::create(['tenant_id' => null, 'name' => 'Macchine Caffè Franke Test']);
        $family = ProductFamily::create(['tenant_id' => null, 'name' => 'A300 Test']);
        Product::create([
            'tenant_id' => null, 'category_id' => $category->id, 'product_family_id' => $family->id,
            'sku' => 'A300-VISIBILITY-TEST', 'type' => Product::TYPE_MACHINE, 'name' => 'A300 Visibility Test',
        ]);

        $this->actingAs($user);

        $this->get("/admin/{$tenant->slug}/categories")->assertOk()->assertSee('Macchine Caffè Franke Test');
        $this->get("/admin/{$tenant->slug}/product-families")->assertOk()->assertSee('A300 Test');
        $this->get("/admin/{$tenant->slug}/products")->assertOk()->assertSee('A300 Visibility Test');
    }
}
