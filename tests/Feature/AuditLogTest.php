<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Epic 6 (docs/roadmap-tickets.md) - audit log generico su modelli sensibili
 * (spatie/laravel-activitylog), esclusi Quote/QuoteProduct (dominio di
 * un'altra sessione di lavoro in corso). Copre i criteri di accettazione dei
 * ticket 6.2/6.3: una modifica a un modello tracciato genera una riga di
 * audit corretta (evento, causer, tenant), un ruolo non-admin non vede la
 * Resource, e un admin di un tenant non vede le righe di un altro tenant.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_updating_a_tracked_model_creates_an_audit_log_entry_with_causer_and_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($admin, $tenant, 'admin');
        $this->actingAs($admin);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Rossi']);
        $customer->update(['company_name' => 'Bar Rossi SRL']);

        $entry = AuditLog::query()->forSubject($customer)->forEvent('updated')->first();

        $this->assertNotNull($entry, 'La modifica al cliente doveva generare una riga di audit.');
        $this->assertSame($tenant->id, $entry->tenant_id);
        $this->assertSame($admin->id, $entry->causer_id);
        $this->assertSame('Bar Rossi SRL', $entry->attribute_changes['attributes']['company_name'] ?? null);
        // logOnlyDirty(): solo il campo davvero cambiato, non l'intero record.
        $this->assertArrayNotHasKey('tenant_id', $entry->attribute_changes['attributes'] ?? []);
    }

    public function test_creating_and_deleting_a_tracked_model_are_both_logged(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($admin, $tenant, 'admin');
        $this->actingAs($admin);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Bianchi']);
        $customer->delete();

        $this->assertNotNull(AuditLog::query()->forSubject($customer)->forEvent('created')->first());
        $this->assertNotNull(AuditLog::query()->forSubject($customer)->forEvent('deleted')->first());
    }

    public function test_saving_a_tracked_model_without_real_changes_does_not_add_noise(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($admin, $tenant, 'admin');
        $this->actingAs($admin);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Verdi']);
        $countAfterCreate = AuditLog::query()->forSubject($customer)->count();

        // save() senza modifiche dirty: nessuna nuova riga (dontLogEmptyChanges).
        $customer->save();

        $this->assertSame($countAfterCreate, AuditLog::query()->forSubject($customer)->count());
    }

    public function test_non_admin_role_cannot_access_the_audit_log_resource(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $dipendente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Dipendente', 'email' => 'dip@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($dipendente, $tenant, 'dipendente');

        $this->actingAs($dipendente)
            ->get("/admin/{$tenant->slug}/audit-logs")
            ->assertForbidden();
    }

    public function test_admin_of_one_tenant_does_not_see_audit_rows_of_another_tenant(): void
    {
        $tenantA = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tenantB = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner']);

        $adminA = User::create([
            'tenant_id' => $tenantA->id, 'name' => 'Admin Gifar', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($adminA, $tenantA, 'admin');

        $adminB = User::create([
            'tenant_id' => $tenantB->id, 'name' => 'Admin Altro', 'email' => 'admin@altro.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($adminB, $tenantB, 'admin');

        $this->actingAs($adminA);
        Customer::create(['tenant_id' => $tenantA->id, 'company_name' => 'Cliente A'])
            ->update(['company_name' => 'Cliente A Aggiornato']);

        $this->actingAs($adminB);
        Customer::create(['tenant_id' => $tenantB->id, 'company_name' => 'Cliente B'])
            ->update(['company_name' => 'Cliente B Aggiornato']);

        // adminA vede il proprio audit (causer "Admin Gifar" in tabella) ma
        // non deve mai vedere il nome dell'utente causer del tenant B: se
        // comparisse significherebbe che lo scoping per tenant e' rotto.
        $this->actingAs($adminA)
            ->get("/admin/{$tenantA->slug}/audit-logs")
            ->assertOk()
            ->assertSee('Admin Gifar')
            ->assertDontSee('Admin Altro');
    }

    public function test_super_admin_sees_audit_rows_across_tenants(): void
    {
        $masterTenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex-test', 'is_master' => true]);
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner-2']);

        $staff = User::create([
            'tenant_id' => $masterTenant->id, 'name' => 'Staff Master', 'email' => 'staff@alex.it',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);
        $this->giveRole($staff, $masterTenant, 'admin');

        $this->actingAs($staff);
        Customer::create(['tenant_id' => $otherTenant->id, 'company_name' => 'Cliente Altro Tenant'])
            ->update(['company_name' => 'Cliente Altro Tenant Aggiornato']);

        $this->actingAs($staff)
            ->get("/admin/{$masterTenant->slug}/audit-logs")
            ->assertOk()
            ->assertSee('Staff Master');
    }
}
