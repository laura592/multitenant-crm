<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /**
     * Riproduce il 500 di produzione: daily_contract_hours/weekly_contract_hours/
     * annual_leave_days sono NOT NULL con un default lato DB (vedi migration
     * add_tenant_fields_to_users_table), ma Filament manda NULL esplicito per
     * un TextInput lasciato vuoto — il default DB scatta solo se la colonna
     * e' del tutto assente dall'INSERT, non se arriva NULL. UserResource deve
     * dare a quei campi un ->default() lato form cosi' non arrivano mai vuoti.
     */
    public function test_creating_a_user_without_touching_contract_hours_does_not_500(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($admin, $tenant, 'admin');

        $role = Role::firstOrCreate(['name' => 'dipendente', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);

        $this->actingAs($admin);
        Filament::setTenant($tenant);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nuovo Dipendente',
                'email' => 'nuovo@gifar.it',
                'password' => 'password123',
                'role_id' => $role->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'nuovo@gifar.it')->first();
        $this->assertNotNull($created);
        $this->assertSame('8.00', $created->daily_contract_hours);
        $this->assertSame('40.00', $created->weekly_contract_hours);
        $this->assertSame(26, $created->annual_leave_days);
    }

    /**
     * Bug di produzione (utente creato il 2026-08-18 dallo staff master, poi
     * 404 su /admin/alex a ogni login): il campo Hidden tenant_id era
     * dehydratato solo se chi creava NON era is_super_admin, quindi un utente
     * creato dallo staff master finiva in DB con tenant_id NULL. Con
     * canAccessTenant() false, IdentifyTenant risponde 404 e l'utente non
     * entra in nessun tenant — e il campo, essendo Hidden, non e' nemmeno
     * correggibile dal form di modifica.
     */
    public function test_user_created_by_master_staff_belongs_to_the_current_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $master = User::create([
            'tenant_id' => null, 'name' => 'Super Admin', 'email' => 'master@alexcaffe.com',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'amministrazione', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);

        $this->actingAs($master);
        Filament::setTenant($tenant);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Impiegata Amministrazione',
                'email' => 'amministrazione@gifar.it',
                'password' => 'password123',
                'role_id' => $role->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'amministrazione@gifar.it')->first();
        $this->assertNotNull($created);
        $this->assertSame($tenant->id, $created->tenant_id);
        $this->assertFalse((bool) $created->is_super_admin);
        $this->assertTrue($created->canAccessTenant($tenant));
    }

    /**
     * Il rovescio della medaglia: un nuovo staff master deve restare senza
     * tenant (User::getTenants() gli mostra tutti i tenant proprio perche'
     * non ne ha uno suo), altrimenti sparisce dallo switcher globale.
     */
    public function test_a_new_master_staff_user_is_created_without_a_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $master = User::create([
            'tenant_id' => null, 'name' => 'Super Admin', 'email' => 'master@alexcaffe.com',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web', 'tenant_id' => $tenant->id]);

        $this->actingAs($master);
        Filament::setTenant($tenant);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nuovo Staff Alex',
                'email' => 'staff@alexcaffe.com',
                'password' => 'password123',
                'role_id' => $role->id,
                'is_super_admin' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'staff@alexcaffe.com')->first();
        $this->assertNotNull($created);
        $this->assertNull($created->tenant_id);
        $this->assertTrue((bool) $created->is_super_admin);
    }
}
