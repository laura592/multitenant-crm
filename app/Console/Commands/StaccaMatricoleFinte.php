<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\RegistroSync;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ripara quello che le matricole finte di Eureka ("000000", "0000000"...)
 * hanno sporcato prima che l'import smettesse di usarle (21/09/2026).
 *
 * L'import cercava la macchina per matricola in tutto l'archivio: un
 * segnaposto di soli zeri trovava la prima macchina col segnaposto uguale, di
 * un cliente qualunque. Due danni:
 *
 * 1. Rapportini attaccati alla macchina di un ALTRO cliente (71 in produzione:
 *    la sanificazione dell'acqua di Strana Coppia su un macinadosatore). Qui
 *    si staccano. Se la macchina e' dello stesso cliente il collegamento si
 *    lascia: puo' essere giusto per caso, e nel dubbio non si toglie.
 *
 * 2. Macchine che si sono prese il modello della scheda (il macinadosatore
 *    risultava "SPINA 3 VIE", il forno "SPINA 5 VIE"): dal modello dipende il
 *    codice manutenzione. Si toglie solo quando il nome della macchina e
 *    quello del modello non hanno una parola in comune — "IMPIANTO ALLA SPINA
 *    8 VIE" con "SPINA 8 VIE" resta.
 */
class StaccaMatricoleFinte extends Command
{
    protected $signature = 'macchine:stacca-matricole-finte
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa farebbe, senza scrivere}';

    protected $description = 'Stacca i rapportini dalle macchine trovate con una matricola finta di Eureka, e toglie i modelli sbagliati';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->option('tenant'))->firstOrFail();

        $finte = MachineUnit::withoutGlobalScopes()->whereNull('deleted_at')->where('tenant_id', $tenant->id)
            ->with(['material' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->filter(fn (MachineUnit $m) => self::finta($m->serial_number))
            ->keyBy('id');

        $rapportini = ServiceReport::withoutGlobalScopes()->whereNull('deleted_at')->where('tenant_id', $tenant->id)
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->whereIn('machine_unit_id', $finte->keys())
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->filter(fn (ServiceReport $r) => self::finta($r->machine_serial_number)
                && $finte[$r->machine_unit_id]->current_customer_id !== $r->customer_id);

        $modelli = $finte->filter(fn (MachineUnit $m) => $m->material && ! self::parolaInComune($m->model_name, $m->material->type ?? $m->material->code));

        $this->info("Rapportini attaccati alla macchina di un altro cliente: {$rapportini->count()}");
        $this->table(['Rapportino', 'Scheda', 'Cliente', 'Macchina sbagliata'], $rapportini->take(15)->map(fn (ServiceReport $r) => [
            $r->number, $r->gestionale_number, mb_substr((string) $r->customer?->company_name, 0, 30),
            mb_substr((string) $finte[$r->machine_unit_id]->model_name, 0, 30),
        ])->all());
        if ($rapportini->count() > 15) {
            $this->line('... e altri '.($rapportini->count() - 15).'.');
        }

        $this->newLine();
        $this->info("Macchine con un modello che non e' il loro: {$modelli->count()}");
        $this->table(['Macchina', 'Matricola', 'Modello da togliere'], $modelli->map(fn (MachineUnit $m) => [
            $m->model_name, $m->serial_number, $m->material->code.' — '.$m->material->type,
        ])->values()->all());

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if ($rapportini->isEmpty() && $modelli->isEmpty()) {
            return self::SUCCESS;
        }

        if (! $this->confirm('Procedo?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        // Sotto il modello: e' una correzione di un collegamento sbagliato,
        // non una modifica del rapportino — niente pagante fissato, niente
        // "ultima modifica" cambiata su rapportini che nessuno ha toccato.
        ServiceReport::withoutGlobalScopes()->whereIn('id', $rapportini->pluck('id'))
            ->toBase()->update(['machine_unit_id' => null, 'machine_serial_number' => null]);

        MachineUnit::withoutGlobalScopes()->whereIn('id', $modelli->pluck('id'))
            ->toBase()->update(['material_id' => null]);

        RegistroSync::movimento('matricole-finte', 'riparati', [
            'rapportini_staccati' => $rapportini->count(),
            'modelli_tolti' => $modelli->map(fn (MachineUnit $m) => "{$m->model_name}: {$m->material->code}")->values()->all(),
        ]);

        $this->info("Fatto: {$rapportini->count()} rapportini staccati, {$modelli->count()} modelli tolti.");

        return self::SUCCESS;
    }

    public static function finta(?string $matricola): bool
    {
        return $matricola !== null && trim($matricola) !== '' && trim($matricola, "0 \t") === '';
    }

    private static function parolaInComune(?string $a, ?string $b): bool
    {
        $parole = fn (?string $t) => collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $t)))
            ->filter(fn (string $p) => mb_strlen($p) >= 3)
            ->reject(fn (string $p) => in_array($p, ['per', 'con', 'alla', 'impianto', 'macchina'], true));

        return $parole($a)->intersect($parole($b))->isNotEmpty();
    }
}
