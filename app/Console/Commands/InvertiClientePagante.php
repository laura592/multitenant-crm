<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MachineUnitPlacement;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Macchine\EliminaPosizionamento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Macchine registrate al contrario: installate dal pagante e pagate dal
 * cliente, invece del viceversa (22/09/2026: la colonna IMP-SPINA-012 e
 * l'impianto IMP-ACQUA-007 sono dal Ginepro e li paga Bar Gighi's, non il
 * contrario come segnato il 20/08).
 *
 * Per ogni matricola la posizione attuale passa al pagante, e chi era il
 * cliente diventa il pagante. Se la macchina era dal pagante subito prima
 * (spostamento sbagliato) lo spostamento si toglie dallo storico, come
 * EliminaPosizionamento; altrimenti la posizione attuale si corregge sul
 * posto. Piani lavaggio/manutenzione e lavaggi della macchina intestati al
 * cliente sbagliato passano a quello giusto. Rapportini ed Eureka non si
 * toccano: il pagante del rapportino e' quello della scheda Eureka.
 *
 * Di default mostra soltanto; scrive con --esegui, dopo conferma.
 */
class InvertiClientePagante extends Command
{
    protected $signature = 'macchine:inverti-cliente-pagante
        {matricole* : Matricole delle macchine da correggere}
        {--tenant=  : Slug tenant (default: tenant master)}
        {--esegui   : Scrive le correzioni (senza, mostra soltanto)}';

    protected $description = 'Scambia cliente e pagante della posizione attuale di una macchina, con piani e lavaggi';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $correzioni = collect();

        foreach ($this->argument('matricole') as $matricola) {
            $macchina = MachineUnit::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('serial_number', $matricola)
                ->first();

            $aperta = $macchina?->placements()->whereNull('removed_at')->latest('placed_at')->first();

            if (! $macchina || ! $aperta?->billing_customer_id) {
                $this->warn("{$matricola}: ".(! $macchina ? 'non trovata' : 'nessuna posizione attuale con un pagante diverso dal cliente').', salto.');

                continue;
            }

            $cliente = Customer::withoutGlobalScopes()->find($aperta->billing_customer_id);
            $pagante = Customer::withoutGlobalScopes()->find($aperta->customer_id);
            $precedente = EliminaPosizionamento::precedente($aperta);
            $togliSpostamento = $precedente?->customer_id === $cliente->id;

            $piani = MaintenanceSchedule::withoutGlobalScopes()->where('machine_unit_id', $macchina->id)->where('customer_id', $pagante->id);
            $lavaggi = Lavaggio::withoutGlobalScopes()->where('machine_unit_id', $macchina->id)->where('customer_id', $pagante->id);

            $correzioni->push(compact('macchina', 'aperta', 'cliente', 'pagante', 'togliSpostamento', 'piani', 'lavaggi'));

            $this->line("<info>{$matricola}</info> ({$macchina->model_name})");
            $this->line('  ora:    da '.DisplayName::customerOption($pagante).', paga '.DisplayName::customerOption($cliente));
            $this->line('  dopo:   da '.DisplayName::customerOption($cliente).', paga '.DisplayName::customerOption($pagante));
            $this->line($togliSpostamento
                ? "  storico: tolgo lo spostamento del {$aperta->placed_at->format('d/m/Y')}, torna la posizione dal {$precedente->placed_at->format('d/m/Y')}"
                : "  storico: correggo la posizione attuale (dal {$aperta->placed_at->format('d/m/Y')})");
            $this->line("  piani da spostare: {$piani->count()}, lavaggi da spostare: {$lavaggi->count()}");
        }

        if ($correzioni->isEmpty()) {
            $this->info('Niente da correggere.');

            return self::SUCCESS;
        }

        if (! $this->option('esegui')) {
            $this->comment('Solo anteprima: rilancia con --esegui per scrivere.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Correggo {$correzioni->count()} macchine?")) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($correzioni) {
            foreach ($correzioni as $c) {
                if ($c['togliSpostamento']) {
                    EliminaPosizionamento::esegui($c['aperta']);
                } else {
                    MachineUnitPlacement::withoutGlobalScopes()->whereKey($c['aperta']->id)->update(['customer_id' => $c['cliente']->id]);
                    $c['macchina']->update(['current_customer_id' => $c['cliente']->id]);
                }

                // L'hook updated() di MachineUnit copia il pagante sulla
                // posizione attuale.
                MachineUnit::withoutGlobalScopes()->find($c['macchina']->id)->update([
                    'billing_customer_id' => $c['pagante']->id,
                    'eureka_billing_customer_code' => $c['pagante']->gestionale_code ? (int) $c['pagante']->gestionale_code : null,
                ]);

                $c['piani']->update(['customer_id' => $c['cliente']->id]);
                $c['lavaggi']->update(['customer_id' => $c['cliente']->id]);
            }
        });

        $this->info('Fatto.');

        return self::SUCCESS;
    }
}
