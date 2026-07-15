<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RolePermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Riusa le stesse definizioni di App\Support\RolePermissions usate dal
 * RolesAndPermissionsSeeder, cosi i test restano allineati ai permessi reali
 * invece di ricreare set arbitrari che potrebbero divergere.
 */
trait AssignsPermissionRoles
{
    protected function giveRole(User $user, Tenant $tenant, string $roleName): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();

        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $permissions = RolePermissions::for($roleName);
        foreach ($permissions as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }
}
