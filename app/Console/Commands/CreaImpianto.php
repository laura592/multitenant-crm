<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Crea un impianto che in anagrafica non c'e' mai stato, con le sue vie, e
 * opzionalmente ci aggancia un rapportino (28/09/2026).
 *
 * Il caso: il tecnico scrive "Impianto acqua 3 vie gasata, naturale fredda e
 * ambientale" nel lavoro svolto, ma quell'impianto a sistema non esiste e il
 * rapportino resta senza macchina — RT-2026-0861, Bettiol Mattiuzzo. Sono
 * 162 i rapportini in questa condizione, ed e' il motivo principale per cui
 * le scadenze dei piani sono indietro: senza macchina non nasce nessun
 * lavaggio.
 *
 * Diverso da rapportini:assegna-impianti, che serve quando l'impianto c'e'
 * gia' e rifa' anche le righe del lavaggio. Qui le righe non si toccano:
 * RT-2026-0861 e' una riparazione con manodopera e chiamata, non un lavaggio,
 * e riscrivergli le voci sarebbe sbagliato.
 */
class CreaImpianto extends Command
{
    /**
     * Per tipo: prefisso della matricola e nome del modello, presi da come
     * sono gia' scritti i 54 impianti esistenti invece che inventati.
     *
     * @var array<string, array{prefisso: string, modello: string, articolo: string|null}>
     */
    private const TIPI = [
        'impianto_acqua' => ['prefisso' => 'IMP-ACQUA-', 'modello' => 'Impianto Acqua', 'articolo' => 'IMPIANTOACQUA'],
        'colonna_spina' => ['prefisso' => 'IMP-SPINA-', 'modello' => 'Impianto Spina', 'articolo' => null],
    ];

    protected $signature = 'macchine:crea-impianto
                            {cliente : ragione sociale, anche parziale, o id}
                            {--tipo=impianto_acqua : impianto_acqua oppure colonna_spina}
                            {--vie= : bevanda:vie, es. acqua:3 oppure birra:2,vino:1}
                            {--nome= : modello, se diverso da quello di default del tipo}
                            {--matricola= : matricola, se non si vuole il progressivo libero}
                            {--rapportino= : numero del rapportino da agganciare (le righe non si toccano)}
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa creerebbe senza scrivere}';

    protected $description = 'Crea un impianto mancante in anagrafica, con le sue vie, e ci aggancia un rapportino';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $tipo = (string) $this->option('tipo');

        if (! isset(self::TIPI[$tipo])) {
            $this->error('Tipo sconosciuto. Sono: '.implode(', ', array_keys(self::TIPI)));

            return self::FAILURE;
        }

        $cliente = $this->cliente($tenant->id, (string) $this->argument('cliente'));

        if (! $cliente) {
            return self::FAILURE;
        }

        $vie = self::vie((string) $this->option('vie'));

        if ($vie === []) {
            $this->error('Serve --vie, es. --vie=acqua:3');

            return self::FAILURE;
        }

        $matricola = (string) ($this->option('matricola') ?: $this->prossimaMatricola($tenant->id, $tipo));

        if (MachineUnit::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('serial_number', $matricola)->exists()) {
            $this->error("La matricola {$matricola} esiste gia'.");

            return self::FAILURE;
        }

        $nome = (string) ($this->option('nome') ?: self::TIPI[$tipo]['modello']);

        $articolo = self::TIPI[$tipo]['articolo']
            ? Material::query()->withoutGlobalScopes()->where('code', self::TIPI[$tipo]['articolo'])->first()
            : null;

        $rapportino = null;

        if ($numero = $this->option('rapportino')) {
            $rapportino = ServiceReport::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('number', $numero)->orWhere('id', $numero))
                ->first();

            if (! $rapportino) {
                $this->error("Rapportino {$numero} non trovato.");

                return self::FAILURE;
            }

            if ($rapportino->isLocked()) {
                $this->error($rapportino->number.' e\' gia\' su Eureka: non si tocca.');

                return self::FAILURE;
            }

            if ($rapportino->customer_id !== $cliente->id) {
                $this->error($rapportino->number.' e\' di '.DisplayName::titleCase($rapportino->customer?->company_name).', non di '.DisplayName::titleCase($cliente->company_name).'.');

                return self::FAILURE;
            }

            if ($rapportino->machine_unit_id) {
                $this->error($rapportino->number.' e\' gia\' su '.$rapportino->machineUnit?->serial_number.'. Per spostarlo usa rapportini:assegna-impianti.');

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->table(['', 'Verra\' creato'], array_filter([
            ['Cliente', DisplayName::titleCase($cliente->company_name)],
            ['Matricola', $matricola],
            ['Modello', $nome],
            ['Tipo', $tipo],
            ['Articolo', $articolo?->code ?? '— (nessuno per questo tipo)'],
            ['Vie', collect($vie)->map(fn (int $v, string $b) => "$b $v")->implode(', ')],
            $rapportino ? ['Rapportino agganciato', $rapportino->number.' del '.$rapportino->intervention_date?->format('d/m/Y')] : null,
        ]));

        if ($rapportino) {
            $this->line('  Le righe di '.$rapportino->number.' restano come sono: '
                .($rapportino->materialsUsed->map(fn ($m) => $m->material?->code)->implode(' ') ?: 'nessuna'));
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Creo l\'impianto?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($tenant, $cliente, $matricola, $nome, $tipo, $articolo, $vie, $rapportino) {
            $macchina = MachineUnit::create([
                'tenant_id' => $tenant->id,
                'current_customer_id' => $cliente->id,
                'serial_number' => $matricola,
                'model_name' => $nome,
                'type' => $tipo,
                'material_id' => $articolo?->id,
                'source' => 'manuale',
            ]);

            foreach ($vie as $bevanda => $numeroVie) {
                // Senza cadenza, come i 27 piani acqua gia' a sistema: quanto
                // spesso si lava lo sa l'ufficio, non questo comando. Finche'
                // resta vuota il piano non scade e non entra nei promemoria.
                MaintenanceSchedule::create([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $cliente->id,
                    'machine_unit_id' => $macchina->id,
                    'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
                    'status' => MaintenanceSchedule::STATUS_ATTIVO,
                    'beverage_type' => $bevanda,
                    'lines_count' => $numeroVie,
                ]);
            }

            if ($rapportino) {
                // saveQuietly: il rapportino e' gia' firmato e chiuso, qui si
                // sta correggendo un'anagrafica mancante, non rifacendo
                // l'intervento. Gli osservatori rimanderebbero notifiche.
                $rapportino->forceFill([
                    'machine_unit_id' => $macchina->id,
                    'machine_product_id' => $macchina->product_id,
                    'machine_material_id' => $macchina->material_id,
                    'machine_serial_number' => $macchina->serial_number,
                ])->saveQuietly();
            }
        });

        $this->info('Fatto: '.$matricola.' creato per '.DisplayName::titleCase($cliente->company_name).'.');

        if ($rapportino) {
            $this->line('  '.$rapportino->number.' e\' ora su '.$matricola.'.');
        }

        $this->line('  I piani sono senza cadenza: impostala dal pannello se questo impianto va lavato a scadenza.');

        return self::SUCCESS;
    }

    private function cliente(string $tenantId, string $chiave): ?\App\Models\Customer
    {
        $trovati = \App\Models\Customer::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('id', $chiave)->orWhere('company_name', 'like', '%'.$chiave.'%'))
            ->limit(10)
            ->get();

        if ($trovati->isEmpty()) {
            $this->error("Nessun cliente per \"{$chiave}\".");

            return null;
        }

        if ($trovati->count() > 1) {
            $this->error('Piu\' di un cliente corrisponde a "'.$chiave.'":');

            foreach ($trovati as $c) {
                $this->line('  '.$c->id.'  '.DisplayName::titleCase($c->company_name));
            }

            $this->line('Rilancia con l\'id.');

            return null;
        }

        return $trovati->first();
    }

    private function prossimaMatricola(string $tenantId, string $tipo): string
    {
        $prefisso = self::TIPI[$tipo]['prefisso'];

        // Il progressivo si legge dalle matricole esistenti, cancellate
        // comprese: riusare il numero di un impianto dismesso farebbe
        // sembrare che i suoi vecchi rapportini siano di questo.
        $ultimo = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('serial_number', 'like', $prefisso.'%')
            ->pluck('serial_number')
            ->map(fn (string $s) => (int) preg_replace('/\D/', '', str_replace($prefisso, '', $s)))
            ->max() ?? 0;

        return $prefisso.str_pad((string) ($ultimo + 1), 3, '0', STR_PAD_LEFT);
    }

    /** @return array<string, int> */
    private static function vie(string $spec): array
    {
        $out = [];

        foreach (array_filter(explode(',', $spec)) as $pezzo) {
            [$bevanda, $n] = array_pad(explode(':', trim($pezzo), 2), 2, null);

            if ($bevanda && is_numeric($n)) {
                $out[mb_strtolower(trim($bevanda))] = (int) $n;
            }
        }

        return $out;
    }
}
