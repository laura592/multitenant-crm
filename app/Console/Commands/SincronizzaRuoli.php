<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Tenant;
use App\Support\RolePermissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * L'UNICO modo in cui il codice tocca ruoli e permessi in produzione.
 *
 * `./update.sh` non li sfiora piu': prima lanciava RolesAndPermissionsSeeder ad
 * ogni `git pull` e quel seeder riallineava tutto al codice, cancellando i
 * permessi concessi a mano dalla pagina Ruoli del pannello. Da qui in avanti
 * ruoli e permessi in produzione cambiano solo quando li cambia una persona:
 * dal pannello, o lanciando a mano questo comando.
 *
 * Mostra sempre il diff per tenant/ruolo e chiede conferma prima di scrivere.
 *
 * Da lanciare quando si aggiunge/toglie un permesso in RolePermissions o si
 * pubblica una Resource/Page nuova (--dry-run per vedere e basta), e con
 * --crea-mancanti dopo aver creato un tenant nuovo, che nasce senza ruoli.
 * Vedi docs/checklist-rilascio.md.
 */
class SincronizzaRuoli extends Command
{
    protected $signature = 'ruoli:sincronizza
        {--tenant= : Slug di un singolo tenant (default: tutti)}
        {--role=* : Uno o piu ruoli da riallineare (default: tutti)}
        {--crea-mancanti : Crea i ruoli che non esistono ancora (tenant nuovo)}
        {--dry-run : Mostra soltanto le differenze, senza scrivere}
        {--force : Applica senza chiedere conferma}';

    protected $description = 'Riallinea i permessi dei ruoli a App\Support\RolePermissions (sovrascrive le modifiche fatte dal pannello)';

    public function handle(): int
    {
        $ruoli = $this->option('role') ?: RolePermissions::roles();

        if ($sconosciuti = array_diff($ruoli, RolePermissions::roles())) {
            $this->error('Ruoli sconosciuti: '.implode(', ', $sconosciuti));

            return self::FAILURE;
        }

        $tenants = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->get()
            : Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->error('Nessun tenant trovato'.($this->option('tenant') ? " con slug \"{$this->option('tenant')}\"." : '.'));

            return self::FAILURE;
        }

        $modifiche = $this->calcolaDifferenze($tenants, $ruoli);

        if ($modifiche === []) {
            $this->info('Ruoli gia allineati al codice: niente da fare.');

            return self::SUCCESS;
        }

        $this->mostra($modifiche);

        if ($this->option('dry-run')) {
            $this->comment('--dry-run: niente e stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Applico? I permessi in rosso verranno tolti ai ruoli, anche se concessi a mano dal pannello.')) {
            $this->comment('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        $this->applica($modifiche);

        $this->info('Fatto. Ricordati di riavviare la coda se hai worker attivi.');

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Tenant>  $tenants
     * @param  array<int, string>  $ruoli
     * @return array<int, array{tenant: Tenant, ruolo: string, role: ?Role, nuovo: bool, aggiunti: array<int, string>, tolti: array<int, string>}>
     */
    private function calcolaDifferenze($tenants, array $ruoli): array
    {
        $registrar = app(PermissionRegistrar::class);
        $modifiche = [];

        foreach ($tenants as $tenant) {
            $registrar->setPermissionsTeamId($tenant->id);
            $registrar->forgetCachedPermissions();

            foreach ($ruoli as $ruolo) {
                $role = Role::where([
                    'name' => $ruolo,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->id,
                ])->first();

                if (! $role) {
                    if (! $this->option('crea-mancanti')) {
                        $this->warn("· {$tenant->slug}: il ruolo \"{$ruolo}\" non esiste (per crearlo: --crea-mancanti).");

                        continue;
                    }

                    $modifiche[] = [
                        'tenant' => $tenant,
                        'ruolo' => $ruolo,
                        'role' => null,
                        'nuovo' => true,
                        'aggiunti' => RolePermissions::for($ruolo),
                        'tolti' => [],
                    ];

                    continue;
                }

                $attuali = $role->permissions->pluck('name')->all();
                $previsti = RolePermissions::for($ruolo);

                $aggiunti = array_values(array_diff($previsti, $attuali));
                $tolti = array_values(array_diff($attuali, $previsti));

                if ($aggiunti === [] && $tolti === []) {
                    continue;
                }

                $modifiche[] = ['nuovo' => false] + compact('tenant', 'ruolo', 'role', 'aggiunti', 'tolti');
            }
        }

        return $modifiche;
    }

    /** @param array<int, array<string, mixed>> $modifiche */
    private function mostra(array $modifiche): void
    {
        foreach ($modifiche as $m) {
            $stato = $m['nuovo'] ? ' <fg=yellow>[ruolo nuovo]</>' : '';
            $this->line("<options=bold>{$m['tenant']->slug} / {$m['ruolo']}</>{$stato} (+".count($m['aggiunti']).' / -'.count($m['tolti']).')');

            foreach ($m['aggiunti'] as $permesso) {
                $this->line("  <fg=green>+ {$permesso}</>");
            }

            foreach ($m['tolti'] as $permesso) {
                $this->line("  <fg=red>- {$permesso}</>");
            }
        }
    }

    /** @param array<int, array<string, mixed>> $modifiche */
    private function applica(array $modifiche): void
    {
        $registrar = app(PermissionRegistrar::class);

        foreach ($modifiche as $m) {
            $registrar->setPermissionsTeamId($m['tenant']->id);
            $registrar->forgetCachedPermissions();

            $previsti = RolePermissions::for($m['ruolo']);

            // Un permesso citato in RolePermissions ma mai generato da
            // `shield:generate` non esiste come record: syncPermissions()
            // fallirebbe. Lo creiamo, ma lo diciamo - di solito e' il segno
            // che manca una rigenerazione, o che c'e' un refuso nel nome.
            foreach ($m['aggiunti'] as $permesso) {
                if (! Permission::where(['name' => $permesso, 'guard_name' => 'web'])->exists()) {
                    Permission::findOrCreate($permesso, 'web');
                    $this->warn("· permesso creato ex novo: {$permesso} (non c'era in tabella)");
                }
            }

            $role = $m['role'] ?? Role::create([
                'name' => $m['ruolo'],
                'guard_name' => 'web',
                'tenant_id' => $m['tenant']->id,
            ]);

            $role->syncPermissions($previsti);

            $this->line("· allineato {$m['tenant']->slug} / {$m['ruolo']}");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
