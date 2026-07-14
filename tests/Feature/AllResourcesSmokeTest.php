<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carica ogni pagina indice delle Resource Filament con un utente reale
 * autenticato, per intercettare errori che compaiono solo a runtime HTTP
 * (es. il "tenant() relationship" mancante, mai rilevato da lint/tinker).
 */
class AllResourcesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_resource_index_page_loads_for_a_partner_user(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Gifar',
            'email' => 'test@gifar.it',
            'password' => bcrypt('password'),
        ]);

        $paths = [
            'categories', 'product-families', 'product-option-groups', 'products',
            'customers', 'payment-methods', 'quotes', 'service-reports',
            'vehicles', 'maintenance-schedules', 'deadlines',
        ];

        foreach ($paths as $path) {
            $this->actingAs($user)
                ->get("/admin/{$tenant->slug}/{$path}")
                ->assertOk();
        }
    }

    public function test_master_admin_can_access_tenants_page(): void
    {
        $master = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $staff = User::create([
            'tenant_id' => null,
            'is_super_admin' => true,
            'name' => 'Staff Alex',
            'email' => 'staff@alex.it',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($staff)
            ->get("/admin/{$master->slug}/tenants")
            ->assertOk();
    }
}
