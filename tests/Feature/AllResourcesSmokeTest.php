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
    use AssignsPermissionRoles, RefreshDatabase;

    // price-lists e' qui e non fra le back-office perche' un partner ci
    // accede davvero: gli servono i prezzi per fare un preventivo. Verificato
    // 2026-08-31 — se un giorno si decidesse che non deve vederli, il test
    // qui sotto diventa rosso ed e' esattamente quello che deve fare.
    private const CATALOG_PATHS = ['categories', 'product-families', 'brands', 'products', 'price-lists'];

    private const SALES_PATHS = ['customers', 'quotes', 'quote-groups'];

    private const BACK_OFFICE_PATHS = [
        'payment-methods', 'service-reports', 'vehicles', 'maintenance-schedules',
        'deadlines', 'time-entries', 'leave-requests', 'riepilogo-ore',
        // Aggiunte 2026-08-31: erano le uniche risorse che nessun test
        // apriva mai. machine-units in particolare e' quella attorno a cui
        // gira la storia di una matricola, e ci abbiamo appena cambiato
        // dentro la risoluzione del pagante.
        'machine-units', 'materials', 'material-orders', 'suppliers',
    ];

    /**
     * Le pagine contabili non stanno fra le back-office perche' non
     * dipendono piu' da un ruolo: sono riservate allo staff master e il
     * cancello e' nel canAccess() di ciascuna (indicazione dell'utente,
     * 02/09/2026). Un admin di tenant, che vede tutto il resto, qui prende
     * 403 — ed e' la riga che deve diventare rossa se qualcuno le rimette
     * nella matrice dei ruoli.
     */
    private const CONTABILITA_PATHS = ['scaduto', 'analisi-contabili', 'cash-flow'];

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

        foreach (self::CONTABILITA_PATHS as $path) {
            $this->actingAs($user)->get("/admin/{$tenant->slug}/{$path}")->assertForbidden();
        }
    }

    public function test_dipendente_role_can_operate_but_not_manage_payment_methods(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Dipendente', 'email' => 'dipendente@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        foreach ([...self::CATALOG_PATHS, 'customers', 'information-requests', 'service-reports', 'maintenance-schedules', 'time-entries', 'leave-requests', 'riepilogo-ore'] as $path) {
            $this->actingAs($user)->get("/admin/{$tenant->slug}/{$path}")->assertOk();
        }

        // Scadenzario e parco veicoli sono roba da amministrazione, non da tecnici sul campo.
        $this->actingAs($user)->get("/admin/{$tenant->slug}/vehicles")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/deadlines")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/payment-methods")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/scaduto")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/analisi-contabili")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/tenants")->assertForbidden();
        // I preventivi non sono di competenza del dipendente (solo partner/admin).
        $this->actingAs($user)->get("/admin/{$tenant->slug}/quotes")->assertForbidden();
        $this->actingAs($user)->get("/admin/{$tenant->slug}/quote-groups")->assertForbidden();
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

        foreach (['information-requests', ...self::BACK_OFFICE_PATHS, ...self::CONTABILITA_PATHS, 'tenants'] as $path) {
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

        foreach (self::CONTABILITA_PATHS as $path) {
            $this->actingAs($staff)->get("/admin/{$master->slug}/{$path}")->assertOk();
        }
    }
}
