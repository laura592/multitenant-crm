<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RolePermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea i 4 ruoli applicativi (dipendente, collaboratore, partner, admin) per
 * ogni tenant esistente, coi permessi definiti in App\Support\RolePermissions,
 * e li assegna agli utenti di test creati da UserSeeder ({ruolo}@test.it).
 * Gestione Tenant e Ruoli resta riservata allo staff master
 * (users.is_super_admin), nessuno dei 4 ruoli la include
 * (docs/architecture.md §5.3).
 *
 * Idempotente: rieseguibile senza duplicare ruoli o assegnazioni.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        Tenant::query()->each(function (Tenant $tenant) use ($registrar) {
            $registrar->setPermissionsTeamId($tenant->id);
            $registrar->forgetCachedPermissions();

            foreach (RolePermissions::roles() as $roleName) {
                $role = Role::firstOrCreate([
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->id,
                ]);

                $role->syncPermissions(RolePermissions::for($roleName));
            }
        });

        $this->assignTestUsers();
    }

    private function assignTestUsers(): void
    {
        $tenant = Tenant::where('slug', 'alex')->first();

        if (! $tenant) {
            return;
        }

        foreach (RolePermissions::roles() as $role) {
            $user = User::where('email', "{$role}@test.it")->first();

            if ($user) {
                $this->assignRole($tenant, $user, $role);
            }
        }
    }

    private function assignRole(Tenant $tenant, User $user, string $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $roleModel = Role::where(['name' => $role, 'guard_name' => 'web', 'tenant_id' => $tenant->id])->first();

        if ($roleModel && ! $user->hasRole($roleModel)) {
            $user->assignRole($roleModel);
        }
    }
}
