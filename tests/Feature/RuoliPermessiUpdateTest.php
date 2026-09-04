<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Support\RolePermissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * update.sh lancia RolesAndPermissionsSeeder dopo ogni `git pull`: finche' il
 * seeder riallineava TUTTI i ruoli al codice, ogni aggiornamento cancellava i
 * permessi concessi a mano dalla pagina Ruoli del pannello (successo piu'
 * volte su "amministrazione").
 */
class RuoliPermessiUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_update_non_toglie_i_permessi_concessi_a_mano_dal_pannello(): void
    {
        [$tenant, $role] = $this->tenantConRuolo('amministrazione');

        // La concessione fatta a mano nel pannello: amministrazione non ha
        // i preventivi in RolePermissions.
        $role->givePermissionTo('view_any_quote');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertContains(
            'view_any_quote',
            $this->permessiDi($tenant, 'amministrazione'),
            'Il seeder ha cancellato un permesso concesso dal pannello: e\' il bug per cui i privilegi sparivano ad ogni update.'
        );
    }

    /**
     * Il seeder resta per preparare un ambiente nuovo (`php artisan db:seed`),
     * non per la produzione gia' avviata: update.sh non lo lancia piu'.
     */
    public function test_il_seeder_crea_i_ruoli_mancanti_coi_permessi_del_codice(): void
    {
        $tenant = $this->tenantConPermessiGenerati();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertEqualsCanonicalizing(
            RolePermissions::for('amministrazione'),
            $this->permessiDi($tenant, 'amministrazione')
        );
    }

    /**
     * Il punto della questione: `./update.sh` non deve poter cambiare ruoli e
     * permessi in produzione, nemmeno di rimbalzo tramite un seeder.
     */
    public function test_lo_script_di_aggiornamento_non_lancia_nessun_seeder(): void
    {
        // Solo le righe eseguite: nei commenti i due comandi ci sono, come
        // promemoria di cosa lanciare a mano quando lo si decide.
        $eseguite = collect(file(base_path('update.sh'), FILE_IGNORE_NEW_LINES))
            ->reject(fn (string $riga) => str_starts_with(trim($riga), '#'))
            ->implode("\n");

        $this->assertStringNotContainsString('db:seed', $eseguite);
        $this->assertStringNotContainsString('ruoli:sincronizza', $eseguite);
        $this->assertStringNotContainsString('shield:generate', $eseguite);
    }

    public function test_il_comando_crea_i_ruoli_di_un_tenant_nuovo_solo_se_glielo_chiedi(): void
    {
        $tenant = $this->tenantConPermessiGenerati();

        $this->artisan('ruoli:sincronizza --force')->assertSuccessful();
        $this->assertDatabaseCount('roles', 0);

        $this->artisan('ruoli:sincronizza --crea-mancanti --force')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            RolePermissions::for('amministrazione'),
            $this->permessiDi($tenant, 'amministrazione')
        );
    }

    public function test_il_comando_esplicito_riallinea_al_codice(): void
    {
        [$tenant, $role] = $this->tenantConRuolo('amministrazione');
        $role->givePermissionTo('view_any_quote');

        $this->artisan('ruoli:sincronizza --force')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            RolePermissions::for('amministrazione'),
            $this->permessiDi($tenant, 'amministrazione')
        );
    }

    public function test_il_comando_in_dry_run_non_scrive_niente(): void
    {
        [$tenant, $role] = $this->tenantConRuolo('amministrazione');
        $role->givePermissionTo('view_any_quote');

        $this->artisan('ruoli:sincronizza --dry-run')->assertSuccessful();

        $this->assertContains('view_any_quote', $this->permessiDi($tenant, 'amministrazione'));
    }

    /** @return array{0: Tenant, 1: Role} */
    private function tenantConRuolo(string $ruolo): array
    {
        $tenant = $this->tenantConPermessiGenerati();

        $role = Role::create(['name' => $ruolo, 'guard_name' => 'web', 'tenant_id' => $tenant->id]);
        $role->syncPermissions(RolePermissions::for($ruolo));

        return [$tenant, $role];
    }

    /**
     * I permessi in tabella li genera `shield:generate` (o la pagina Ruoli):
     * senza record, syncPermissions() non troverebbe i nomi del codice.
     */
    private function tenantConPermessiGenerati(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();

        foreach (RolePermissions::roles() as $ruolo) {
            foreach (RolePermissions::for($ruolo) as $permesso) {
                Permission::findOrCreate($permesso, 'web');
            }
        }

        Permission::findOrCreate('view_any_quote', 'web');

        return $tenant;
    }

    /** @return array<int, string> */
    private function permessiDi(Tenant $tenant, string $ruolo): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();

        return Role::where(['name' => $ruolo, 'guard_name' => 'web', 'tenant_id' => $tenant->id])
            ->firstOrFail()
            ->permissions
            ->pluck('name')
            ->all();
    }
    /**
     * Cancellare e' di chi amministra.
     *
     * Non e' una cosa che si fa dal campo o dall'ufficio (indicazione
     * dell'ufficio, 03/09/2026): chi sbaglia un rapportino lo corregge, non
     * lo fa sparire. Il test esiste perche' la deriva e' silenziosa —
     * un self::MANAGE aggiunto per comodita' a un ruolo qualsiasi ci
     * rimetterebbe dentro delete e delete_any senza che nessuno se ne
     * accorga.
     */
    public function test_solo_admin_puo_cancellare(): void
    {
        foreach (['dipendente', 'amministrazione', 'amministratore', 'partner'] as $ruolo) {
            $conDelete = array_values(array_filter(
                \App\Support\RolePermissions::for($ruolo),
                fn (string $permesso) => str_contains($permesso, 'delete'),
            ));

            $this->assertSame([], $conDelete, "{$ruolo} non deve poter cancellare");
        }

        $this->assertNotEmpty(array_filter(
            \App\Support\RolePermissions::for('admin'),
            fn (string $permesso) => str_contains($permesso, 'delete'),
        ), 'admin deve poter cancellare');
    }

}
