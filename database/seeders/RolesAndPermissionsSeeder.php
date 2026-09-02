<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RolePermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea i ruoli applicativi (dipendente, amministrazione, partner, admin,
 * amministratore) MANCANTI in ogni tenant, coi permessi definiti in
 * App\Support\RolePermissions, e li assegna agli utenti di test creati da
 * UserSeeder ({ruolo}@test.it). Gestione Tenant e Ruoli resta riservata allo
 * staff master (users.is_super_admin), nessuno dei ruoli la include
 * (docs/architecture.md §5.3).
 *
 * NON tocca i permessi di un ruolo che esiste gia'. Prima lo faceva
 * (syncPermissions su tutti i ruoli ad ogni esecuzione) e, siccome update.sh
 * lo rilanciava dopo ogni `git pull`, ogni aggiornamento cancellava i permessi
 * aggiunti a mano dalla pagina Ruoli del pannello: chi li aveva concessi se
 * li ritrovava spariti senza spiegazione.
 *
 * Dal 2026-09-02 questo seeder NON gira piu' in fase di aggiornamento: serve
 * a preparare un ambiente nuovo (`php artisan db:seed`), non la produzione
 * gia' avviata. Online ruoli e permessi si toccano solo a mano, dal pannello
 * o con un comando esplicito che mostra il diff e chiede conferma:
 *
 *     php artisan ruoli:sincronizza [--crea-mancanti]
 *
 * (App\Console\Commands\SincronizzaRuoli).
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

                // Solo sui ruoli appena creati (tenant nuovo, ruolo nuovo):
                // su quelli esistenti i permessi restano come sono, vedi il
                // commento in testa alla classe.
                if ($role->wasRecentlyCreated) {
                    $role->syncPermissions(RolePermissions::for($roleName));
                }
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
