<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    /**
     * Falla trovata il 23/09/2026, riprodotta dal vivo prima di chiuderla.
     *
     * Il ruolo "admin" esiste in ogni tenant e porta con se' i permessi sugli
     * utenti (RolePermissions), e UserResource::getEloquentQuery() mostra di
     * proposito anche chi ha tenant_id NULL - lo staff master Alex - dentro
     * l'elenco Utenti di ogni partner. UserPolicy pero' guardava solo il
     * permesso e mai il record: l'admin di un partner apriva la scheda di uno
     * staff master, gli riscriveva la password dal form (il campo c'e') ed
     * entrava come super admin su tutti i tenant.
     */
    public function test_un_admin_partner_non_puo_toccare_lo_staff_master(): void
    {
        $gifar = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $master = User::create([
            'tenant_id' => null, 'name' => 'Staff Alex', 'email' => 'staff@alexcaffe.com',
            'password' => bcrypt('segretissima'), 'is_super_admin' => true,
        ]);

        $adminPartner = User::create([
            'tenant_id' => $gifar->id, 'name' => 'Admin Gifar', 'email' => 'admin@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($adminPartner, $gifar, 'admin');

        $this->actingAs($adminPartner);
        Filament::setTenant($gifar);

        $this->assertFalse($adminPartner->can('view', $master));
        $this->assertFalse($adminPartner->can('update', $master));
        $this->assertFalse($adminPartner->can('delete', $master));

        // Non basta che la policy dica di no: la pagina di modifica deve
        // rifiutare di aprirsi, non limitarsi a nascondere il bottone.
        Livewire::test(EditUser::class, ['record' => $master->getKey()])
            ->assertForbidden();

        $master->refresh();
        $this->assertTrue(Hash::check('segretissima', $master->password));
        $this->assertTrue((bool) $master->is_super_admin);
    }

    /**
     * L'altra meta' della regola: dentro il suo tenant l'admin continua a
     * gestire i suoi utenti come prima.
     */
    public function test_un_admin_partner_gestisce_gli_utenti_del_suo_tenant(): void
    {
        $gifar = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $adminPartner = User::create([
            'tenant_id' => $gifar->id, 'name' => 'Admin Gifar', 'email' => 'admin@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($adminPartner, $gifar, 'admin');

        $collega = User::create([
            'tenant_id' => $gifar->id, 'name' => 'Collega', 'email' => 'collega@gifar.it',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($adminPartner);
        Filament::setTenant($gifar);

        $this->assertTrue($adminPartner->can('view', $collega));
        $this->assertTrue($adminPartner->can('update', $collega));
        $this->assertTrue($adminPartner->can('delete', $collega));

        $role = Role::firstOrCreate(['name' => 'dipendente', 'guard_name' => 'web', 'tenant_id' => $gifar->id]);

        Livewire::test(EditUser::class, ['record' => $collega->getKey()])
            ->fillForm(['name' => 'Collega Rinominato', 'role_id' => $role->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Collega Rinominato', $collega->refresh()->name);
    }

    /**
     * Lo staff master passa da Gate::before, non dalla policy: deve poter
     * continuare a gestire chiunque, in qualunque tenant stia lavorando.
     */
    public function test_lo_staff_master_gestisce_gli_utenti_di_ogni_tenant(): void
    {
        $gifar = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $master = User::create([
            'tenant_id' => null, 'name' => 'Staff Alex', 'email' => 'staff@alexcaffe.com',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);

        $altroMaster = User::create([
            'tenant_id' => null, 'name' => 'Collega Alex', 'email' => 'collega@alexcaffe.com',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);

        $utentePartner = User::create([
            'tenant_id' => $gifar->id, 'name' => 'Utente Gifar', 'email' => 'utente@gifar.it',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($master);
        Filament::setTenant($gifar);

        $this->assertTrue($master->can('update', $utentePartner));
        $this->assertTrue($master->can('update', $altroMaster));
        $this->assertTrue($master->can('delete', $altroMaster));
        $this->assertTrue(UserResource::canEdit($utentePartner));
    }

    /**
     * Il seguito della falla sopra: chiusa la pagina di modifica restavano
     * aperte le azioni della tabella, che girano su una Collection e non
     * passano da nessuna policy. Con la sola UserPolicy sistemata, l'admin di
     * un partner poteva ancora spuntare la riga dello staff master ed
     * eliminarlo in blocco, o spegnerlo con "Disattiva" (is_active a false
     * significa niente pannello, vedi User::canAccessPanel).
     */
    public function test_le_azioni_della_tabella_non_arrivano_allo_staff_master(): void
    {
        $gifar = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $master = User::create([
            'tenant_id' => null, 'name' => 'Staff Alex', 'email' => 'staff@alexcaffe.com',
            'password' => bcrypt('password'), 'is_super_admin' => true, 'is_active' => true,
        ]);

        $adminPartner = User::create([
            'tenant_id' => $gifar->id, 'name' => 'Admin Gifar', 'email' => 'admin@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($adminPartner, $gifar, 'admin');

        $this->actingAs($adminPartner);
        Filament::setTenant($gifar);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('toggleActive', $master)
            ->assertTableActionHidden('delete', $master);

        Livewire::test(ListUsers::class)->callTableBulkAction('deactivate', [$master]);
        $this->assertTrue((bool) $master->refresh()->is_active);

        Livewire::test(ListUsers::class)->callTableBulkAction('delete', [$master]);
        $this->assertTrue(User::whereKey($master->getKey())->exists());
    }

    /**
     * E dentro il suo tenant le stesse azioni devono continuare a funzionare.
     */
    public function test_le_azioni_in_blocco_valgono_dentro_il_proprio_tenant(): void
    {
        $gifar = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $adminPartner = User::create([
            'tenant_id' => $gifar->id, 'name' => 'Admin Gifar', 'email' => 'admin@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($adminPartner, $gifar, 'admin');

        $collega = User::create([
            'tenant_id' => $gifar->id, 'name' => 'Collega', 'email' => 'collega@gifar.it',
            'password' => bcrypt('password'), 'is_active' => true,
        ]);

        $this->actingAs($adminPartner);
        Filament::setTenant($gifar);

        Livewire::test(ListUsers::class)->callTableBulkAction('deactivate', [$collega]);
        $this->assertFalse((bool) $collega->refresh()->is_active);

        Livewire::test(ListUsers::class)->callTableBulkAction('activate', [$collega]);
        $this->assertTrue((bool) $collega->refresh()->is_active);

        Livewire::test(ListUsers::class)->callTableBulkAction('delete', [$collega]);
        $this->assertFalse(User::whereKey($collega->getKey())->exists());
    }
}
