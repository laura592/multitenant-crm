<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Applica su MachineUnit.billing_customer_id il pagante reale gia' noto da
 * Eureka a livello di macchina (MachineUnit.eureka_billing_customer_code,
 * gia' letto/aggiornato ad ogni gestionale:sync da
 * GestionaleSyncRunner::importInstalledMachines() — vedi il campo
 * id_intestatario_fattura_f15, confermato dal vendor via email 2026-08-24).
 *
 * Fonte diversa e piu' diretta di ApplyEurekaBillingDestinazione (che legge
 * ServiceReport.eureka_destinazione_code, disponibile solo se esiste gia' un
 * rapportino importato per quella macchina): questa e' per-macchina, sempre
 * disponibile appena la macchina compare in art_installati. Le due fonti
 * possono disaccordare — quando succede, questa vince (e' quella piu' diretta
 * e piu' fresca, aggiornata a ogni sync), coerente con la regola gia' decisa
 * "ha sempre ragione il gestionale" (2026-08-17, vedi memoria progetto).
 *
 * Legge SOLO da MachineUnit, non scrive mai su ServiceReport/Customer.
 */
class ApplyEurekaMachineBillingPayer extends Command
{
    protected $signature = 'eureka:apply-machine-billing-payer
        {--tenant=       : Slug tenant (default: tenant master)}
        {--dry-run       : Mostra cosa verrebbe scritto senza salvare nulla}';

    protected $description = "Applica su MachineUnit.billing_customer_id il pagante reale gia' noto da Eureka a livello di macchina (id_intestatario_fattura_f15)";

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');

        $machines = MachineUnit::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('eureka_billing_customer_code')
            ->get(['id', 'model_name', 'serial_number', 'current_customer_id', 'billing_customer_id', 'eureka_billing_customer_code']);

        if ($machines->isEmpty()) {
            $this->warn('Nessuna macchina con eureka_billing_customer_code valorizzato — esegui prima gestionale:sync.');

            return self::SUCCESS;
        }

        $customersById = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'gestionale_code', 'company_name', 'first_name', 'last_name'])
            ->keyBy('id');

        $customerIdByCode = $customersById
            ->filter(fn (Customer $c) => $c->gestionale_code !== null)
            ->mapWithKeys(fn (Customer $c) => [(int) $c->gestionale_code => $c->id]);

        $this->info(sprintf('%sMacchine con pagante Eureka noto: %d', $dryRun ? '[DRY RUN] ' : '', $machines->count()));

        $applied = 0;
        $alreadyCorrect = 0;
        $unresolved = 0;
        $selfPaying = 0;
        $overwritten = [];

        $label = fn (?Customer $c) => $c ? ($c->company_name ?: trim($c->first_name.' '.$c->last_name)) : null;

        foreach ($machines as $machine) {
            $code = (int) $machine->eureka_billing_customer_code;
            $payerId = $customerIdByCode[$code] ?? null;

            if (! $payerId) {
                $unresolved++;

                continue;
            }

            if ($payerId === $machine->current_customer_id) {
                // Il pagante Eureka coincide col cliente presso cui e'
                // installata: equivale a "nessun pagante diverso", non va
                // forzato un billing_customer_id esplicito per questo.
                $selfPaying++;

                continue;
            }

            $currentPayerId = $machine->billing_customer_id;

            if ($currentPayerId === $payerId) {
                $alreadyCorrect++;

                continue;
            }

            if ($currentPayerId !== null) {
                $overwritten[] = [
                    'macchina' => $machine->model_name.' ('.$machine->serial_number.')',
                    'crm_precedente' => $label($customersById->get($currentPayerId)) ?? $currentPayerId,
                    'eureka_dice' => $label($customersById->get($payerId)) ?? "codice {$code}",
                ];
            }

            $applied++;
            $this->line(sprintf(
                '  <info>%s</info> %s (%s) → billing_customer_id = %s',
                $dryRun ? 'SAREBBE APPLICATO' : 'APPLICATO',
                $machine->model_name,
                $machine->serial_number,
                $label($customersById->get($payerId)) ?? "codice {$code}",
            ));

            if (! $dryRun) {
                $machine->billing_customer_id = $payerId;
                $machine->save();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%sApplicati: %d (di cui %d sovrascritti su un valore CRM diverso). Gia\' corretti: %d. Pagante = cliente stesso (nessuna modifica necessaria): %d. Codice pagante non risolvibile in CRM: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $applied,
            count($overwritten),
            $alreadyCorrect,
            $selfPaying,
            $unresolved,
        ));

        if ($overwritten !== []) {
            $this->newLine();
            $this->warn('Sovrascritti — CRM aveva gia\' un billing_customer_id diverso, sostituito con quanto dice Eureka (fonte di verita\'):');
            $this->table(
                ['Macchina', 'CRM precedente', 'Eureka dice'],
                collect($overwritten)->map(fn (array $row) => [$row['macchina'], $row['crm_precedente'], $row['eureka_dice']])->all(),
            );
        }

        return self::SUCCESS;
    }

    private function resolveTenant(): Tenant
    {
        $slug = trim((string) $this->option('tenant'));

        return $slug !== ''
            ? Tenant::query()->where('slug', $slug)->firstOrFail()
            : Tenant::query()->where('is_master', true)->firstOrFail();
    }
}
