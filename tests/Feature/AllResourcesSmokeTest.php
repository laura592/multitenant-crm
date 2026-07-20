<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Carica ogni pagina indice delle Resource Filament con un utente reale
 * autenticato per ciascuno dei 4 ruoli applicativi, per intercettare errori
 * runtime E verificare che le restrizioni di navigazione siano quelle volute
 * (es. "Gifar è partner, non gli serve vedere tutto" - vede solo catalogo in
 * sola lettura, clienti e preventivi propri; non vede scadenzario, presenze,
 * metodi di pagamento o gestione tenant/ruoli).
 */
class AllResourcesSmokeTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private const CATALOG_PATHS = ['categories', 'product-families', 'brands', 'products'];

    private const SALES_PATHS = ['customers', 'quotes'];

    private const BACK_OFFICE_PATHS = [
        'payment-methods', 'service-reports', 'vehicles', 'maintenance-schedules',
        'deadlines', 'time-entries', 'leave-requests', 'riepilogo-ore',
    ];

    public function test_admin_role_can_access_every_tenant_resource_except_tenant_management(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        foreach ([...self::CATALOG_PATHS, ...self::SALES_PATHS, 'information-requests', ...self::BACK_OFFICE_PATHS] as $path) {
            $this->actingAs($user)->get("/admin/{$tenant->slug}/{$path}")->assertOk();
        }

        $this->actingAs($user)->get("/admin/{$tenant->slug}/tenants")->assertForbidden();
    }

    public function test_dipendente_role_can_operate_but_not_manage_payment_methods(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Dipendente', 'email' => 'dipendente@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        foreach ([...self::CATALOG_PATHS, ...self::SALES_PATHS, 'information-requests', 'service-reports', 'vehicles', 'maintenance-schedules', 'deadlines', 'time-entries', 'leave-requests', 'riepilogo-ore'] as $path) {
            $this->actingAs($user)->get("/admin/{$tenant->slug}/{$path}")->assertOk();
        }

        $this->actingAs($user)->get("/admin/{$tenant->slug}/payment-methods")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/tenants")->assertForbidden();
    }

    public function test_partner_role_sees_only_catalog_customers_and_quotes(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'partner');

        foreach ([...self::CATALOG_PATHS, ...self::SALES_PATHS] as $path) {
            $this->actingAs($user)->get("/admin/{$tenant->slug}/{$path}")->assertOk();
        }

        foreach (['information-requests', ...self::BACK_OFFICE_PATHS, 'tenants'] as $path) {
            $this->actingAs($user)->get("/admin/{$tenant->slug}/{$path}")->assertForbidden();
        }
    }

    public function test_collaboratore_role_can_only_manage_information_requests(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Collaboratore', 'email' => 'collab@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'collaboratore');

        $this->actingAs($user)->get("/admin/{$tenant->slug}/information-requests")->assertOk();

        foreach ([...self::CATALOG_PATHS, ...self::SALES_PATHS, ...self::BACK_OFFICE_PATHS, 'tenants'] as $path) {
            $this->actingAs($user)->get("/admin/{$tenant->slug}/{$path}")->assertForbidden();
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
