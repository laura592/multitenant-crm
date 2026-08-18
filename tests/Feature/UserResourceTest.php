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
}
