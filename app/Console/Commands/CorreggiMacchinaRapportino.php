<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sposta un rapportino sulla macchina giusta, anche se e' gia' su Eureka
 * (28/09/2026).
 *
 * Un rapportino passato in gestionale il CRM non lo lascia modificare: e'
 * la regola che tiene lo specchio fedele (ServiceReport::isLocked()). Ma qui
 * lo specchio e' rotto dalla parte del CRM, non di Eureka.
 *
 * RT-2026-0844: la scheda 796 dice `sl_articolo = SPINA 3 VIE`, cioe'
 * l'impianto alla spina. L'import l'aveva agganciato all'impianto ACQUA dello
 * stesso cliente. Conseguenza invisibile ma concreta: i piani lavaggio stanno
 * sull'impianto a spina, il rapportino no, quindi non si e' generato nessun
 * lavaggio e le scadenze di birra, vino e selz sono rimaste indietro.
 *
 * Correggere qui non contraddice Eureka: lo riallinea. `machine_unit_id` e'
 * un dato del CRM — Eureka la macchina la dichiara come articolo, e spesso
 * senza matricola.
 *
 * Non tocca niente su Eureka. La scheda resta esattamente com'e'.
 */
class CorreggiMacchinaRapportino extends Command
{
    protected $signature = 'rapportini:correggi-macchina
                            {rapportino : numero (RT-2026-0844) o id}
                            {matricola : matricola della macchina giusta}
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Sposta un rapportino sulla macchina giusta e rigenera i lavaggi, anche se e\' gia\' su Eureka';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();
        $chiave = (string) $this->argument('rapportino');

        $rapportino = ServiceReport::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('number', $chiave)->orWhere('id', $chiave))
            ->first();

        if (! $rapportino) {
            $this->error("Rapportino {$chiave} non trovato.");

            return self::FAILURE;
        }

        $nuova = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('serial_number', $this->argument('matricola'))
            ->first();

        if (! $nuova) {
            $this->error('Matricola '.$this->argument('matricola').' non trovata.');

            return self::FAILURE;
        }

        // La macchina dev'essere di questo cliente: spostare un rapportino su
        // una macchina di un altro cliente non e' una correzione, e' un altro
        // errore.
        if ($nuova->current_customer_id !== $rapportino->customer_id) {
            $this->error('La macchina '.$nuova->serial_number.' non e\' di '
                .DisplayName::titleCase($rapportino->customer?->company_name).'.');

            return self::FAILURE;
        }

        $vecchia = $rapportino->machineUnit;

        if ($vecchia && $vecchia->is($nuova)) {
            $this->info('Il rapportino e\' gia\' su '.$nuova->serial_number.': niente da fare.');

            return self::SUCCESS;
        }

        $this->table(['', 'Adesso', 'Dopo'], [
            ['Macchina', $vecchia?->serial_number ?? '—', $nuova->serial_number],
            ['Modello', mb_substr((string) ($vecchia?->model_name ?? '—'), 0, 34), mb_substr((string) $nuova->model_name, 0, 34)],
            ['Piani collegati', $this->pianiDi($rapportino, $vecchia), $this->pianiDi($rapportino, $nuova)],
        ]);

        $this->line('  Rapportino: '.$rapportino->number.' del '.$rapportino->intervention_date?->format('d/m/Y'));
        $this->line('  Cliente:    '.DisplayName::titleCase($rapportino->customer?->company_name));
        $this->line('  Lavaggi generati adesso: '.$rapportino->lavaggi()->count());

        if ($rapportino->isLocked()) {
            $this->warn('  Questo rapportino e\' su Eureka (scheda '.$rapportino->eureka_service_report_id.').');
            $this->warn('  Su Eureka NON verra\' toccato niente: si corregge solo il collegamento nel CRM.');
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Sposto '.$rapportino->number.' su '.$nuova->serial_number.'?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rapportino, $nuova) {
            // saveQuietly + forceFill: il salvataggio normale passa dalle
            // regole che su un rapportino bloccato rifiuterebbero la modifica,
            // ed e' giusto che sia cosi' ovunque tranne qui.
            $rapportino->forceFill([
                'machine_unit_id' => $nuova->id,
                'machine_product_id' => $nuova->product_id,
                'machine_material_id' => $nuova->material_id,
                'machine_serial_number' => $nuova->serial_number,
            ])->saveQuietly();

            // I lavaggi si rifanno sui piani della macchina giusta: e' il
            // motivo per cui la correzione serve, non un effetto collaterale.
            $rapportino->refresh()->syncMaintenanceSchedule();
        });

        $this->info('Fatto. '.$rapportino->number.' ora e\' su '.$nuova->serial_number.'.');
        $this->line('Lavaggi generati adesso: '.$rapportino->fresh()->lavaggi()->count());

        return self::SUCCESS;
    }

    private function pianiDi(ServiceReport $rapportino, ?MachineUnit $macchina): string
    {
        if (! $macchina) {
            return '—';
        }

        $piani = \App\Models\MaintenanceSchedule::query()
            ->withoutGlobalScopes()
            ->where('customer_id', $rapportino->customer_id)
            ->where('machine_unit_id', $macchina->id)
            ->get();

        return $piani->isEmpty()
            ? 'nessuno'
            : $piani->map(fn ($p) => ($p->beverage_type ?? $p->type).' '.($p->lines_count ?? '?').'v')->implode(', ');
    }
}
