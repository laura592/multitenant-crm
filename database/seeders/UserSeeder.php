<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Support\RolePermissions;
use Illuminate\Database\Seeder;

/**
 * Un utente di test per ciascuno dei 4 ruoli applicativi (dipendente,
 * collaboratore, partner, admin), tutti sul tenant master "Alex". Servono a
 * testare login e permessi in locale senza usare indirizzi di persone reali.
 * L'"admin" di test e' anche is_super_admin, per poter testare anche le
 * schermate di gestione tenant/ruoli riservate allo staff master.
 * Il ruolo Spatie viene assegnato da RolesAndPermissionsSeeder, che deve
 * girare dopo (quando i Role del tenant esistono gia').
 * Idempotente: firstOrCreate per email.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'alex')->first();

        if (! $tenant) {
            return;
        }

        foreach (RolePermissions::roles() as $role) {
            User::firstOrCreate(
                ['email' => "{$role}@test.it"],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Test '.ucfirst($role),
                    'password' => bcrypt('password'),
                    'is_super_admin' => $role === 'admin',
                ]
            );
        }
    }
}
