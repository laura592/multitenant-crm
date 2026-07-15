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
 * e li assegna alle persone reali dell'organigramma Alex/Gifar. Gestione
 * Tenant e Ruoli resta riservata allo staff master (users.is_super_admin),
 * nessuno dei 4 ruoli la include (docs/architecture.md §5.3).
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

        $this->assignRealPeople();
    }

    private function assignRealPeople(): void
    {
        $alex = Tenant::where('slug', 'alex')->first();
        $gifar = Tenant::where('slug', 'gifar')->first();

        if (! $alex) {
            return;
        }

        $this->assign($alex, 's.alessandro@alexcaffe.com', 'admin', ['tenant_id' => $alex->id, 'is_super_admin' => true]);
        $this->assign($alex, 'lauragrb.1990@gmail.com', 'dipendente', ['tenant_id' => $alex->id, 'is_super_admin' => false]);
        $this->assign($alex, 'cristina.burato@alexcaffe.com', 'dipendente', ['tenant_id' => $alex->id]);
        $this->assign($alex, 'igor.capiotto@alexcaffe.com', 'dipendente', ['tenant_id' => $alex->id]);
        $this->assign($alex, 'info@filipponadalon.it', 'collaboratore', ['tenant_id' => $alex->id]);

        if (! $gifar) {
            return;
        }

        $partner = User::firstOrCreate(
            ['email' => 'info@gifar.it'],
            [
                'tenant_id' => $gifar->id,
                'name' => 'Gifar - Referente commerciale',
                'password' => bcrypt(str()->random(32)),
            ]
        );
        $this->assignRole($gifar, $partner, 'partner');
    }

    private function assign(Tenant $tenant, string $email, string $role, array $userAttributes = []): void
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return;
        }

        $user->fill($userAttributes)->save();
        $this->assignRole($tenant, $user, $role);
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
