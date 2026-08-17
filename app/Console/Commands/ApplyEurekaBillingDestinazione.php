<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Applica su Customer/MachineUnit.billing_customer_id il pagante reale gia'
 * noto da Eureka (ServiceReport.eureka_destinazione_code, gia' letto da
 * BackfillServiceReportEurekaDestinazione/ImportEurekaServiceReports): quel
 * dato e' corretto e presente in CRM dal 2026-08-13, ma nessun comando lo
 * aveva mai riportato sul collegamento di fatturazione vero e proprio
 * (trovato dal vivo su Dersut Caffe' SPA / Glb Food Company, 2026-08-17,
 * vedi eureka:audit-billing-destinazione per la diagnosi read-only).
 *
 * Legge SOLO da ServiceReport, non lo scrive mai — i rapportini restano
 * intoccati per esplicita richiesta. Scrive unicamente billing_customer_id
 * su Customer o MachineUnit.
 *
 * Non sovrascrive mai un billing_customer_id gia' impostato che diverga da
 * quanto dice Eureka: quello e' un conflitto reale (possibile override
 * intenzionale gia' fatto in CRM) e va segnalato per revisione manuale, non
 * deciso automaticamente qui.
 */
class ApplyEurekaBillingDestinazione extends Command
{
    protected $signature = 'eureka:apply-billing-destinazione
        {--tenant=       : Slug tenant (default: tenant master)}
        {--dry-run       : Mostra cosa verrebbe scritto senza salvare nulla}';

    protected $description = "Applica su Customer/MachineUnit.billing_customer_id il pagante reale gia' noto da Eureka (destinazione), senza toccare i rapportini";

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');

        $reports = ServiceReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('eureka_destinazione_code')
            ->orderByDesc('intervention_date')
            ->get(['id', 'customer_id', 'machine_unit_id', 'eureka_destinazione_code', 'eureka_destinazione_label', 'intervention_date', 'number']);

        if ($reports->isEmpty()) {
            $this->warn('Nessun rapportino con destinazione valorizzata.');

            return self::SUCCESS;
        }

        // Un solo rappresentante per gruppo cliente+macchina: il piu' recente
        // (gia' ordinato sopra) — stesso criterio dell'audit di sola lettura.
        $groups = $reports->unique(fn (ServiceReport $r) => $r->customer_id.'|'.($r->machine_unit_id ?? 'none'));

        $customersById = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'gestionale_code', 'company_name', 'first_name', 'last_name', 'billing_customer_id'])
            ->keyBy('id');

        $customerIdByCode = $customersById
            ->filter(fn (Customer $c) => $c->gestionale_code !== null)
            ->mapWithKeys(fn (Customer $c) => [(int) $c->gestionale_code => $c->id]);

        $machineUnits = MachineUnit::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $groups->pluck('machine_unit_id')->filter()->all())
            ->get(['id', 'billing_customer_id', 'model_name', 'serial_number'])
            ->keyBy('id');

        // Matricole segnaposto (interamente "0", es. "0000000", "00000")
        // fanno collassare su un'unica MachineUnit rapportini di clienti
        // fisicamente diversi (trovato dal vivo 2026-08-17: "FORNO"/0000000
        // condivisa da 10 clienti, "MACINADOSATORE FAEMA MC99"/000000 da 9,
        // "ADDOLCITORE AUTOMATICO LT 8"/00000 da 4). Scrivere
        // billing_customer_id su una di queste sarebbe corretto solo per un
        // cliente e sbagliato per tutti gli altri — quindi si salta, non si
        // sceglie arbitrariamente in base all'ordine di elaborazione. Una
        // macchina con matricola VERA vista su piu' clienti nel tempo invece
        // e' normale (si sposta tra locali, vedi MachineUnitPlacement) — il
        // gruppo piu' recente riflette gia' la relazione attuale, non va
        // trattata come ambigua solo perche' ha una storia.
        $placeholderMachineUnitIds = $machineUnits
            ->filter(fn (MachineUnit $m) => (bool) preg_match('/^0+$/', (string) $m->serial_number))
            ->keys();

        $ambiguousMachineUnitIds = $placeholderMachineUnitIds->isEmpty() ? [] : ServiceReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('machine_unit_id', $placeholderMachineUnitIds)
            ->select('machine_unit_id')
            ->groupBy('machine_unit_id')
            ->havingRaw('COUNT(DISTINCT customer_id) > 1')
            ->pluck('machine_unit_id')
            ->all();

        $this->info(sprintf(
            '%sGruppi cliente+macchina con destinazione nota: %d',
            $dryRun ? '[DRY RUN] ' : '',
            $groups->count(),
        ));

        $applied = 0;
        $alreadyCorrect = 0;
        $unresolved = 0;
        $ambiguous = 0;
        $overwritten = [];

        foreach ($groups as $report) {
            $code = (int) $report->eureka_destinazione_code;
            $payerId = $customerIdByCode[$code] ?? null;

            if (! $payerId) {
                $unresolved++;

                continue;
            }

            if ($payerId === $report->customer_id) {
                // Difensivo: l'import gia' esclude la destinazione quando
                // coincide con l'intestatario, non dovrebbe mai capitare qui.
                continue;
            }

            if ($report->machine_unit_id && in_array($report->machine_unit_id, $ambiguousMachineUnitIds, true)) {
                $ambiguous++;

                continue;
            }

            $target = $report->machine_unit_id
                ? $machineUnits->get($report->machine_unit_id)
                : $customersById->get($report->customer_id);

            if (! $target) {
                continue;
            }

            $currentPayerId = $target->billing_customer_id;

            if ($currentPayerId === $payerId) {
                $alreadyCorrect++;

                continue;
            }

            $label = fn (?Customer $c) => $c ? ($c->company_name ?: trim($c->first_name.' '.$c->last_name)) : null;

            // Il gestionale (Eureka) e' sempre la fonte di verita' su chi paga
            // davvero, anche quando CRM ha gia' un billing_customer_id diverso
            // — non e' un conflitto da rivedere a mano, si sovrascrive
            // (decisione esplicita dell'utente, 2026-08-17).
            if ($currentPayerId !== null) {
                $overwritten[] = [
                    'cliente' => $label($customersById->get($report->customer_id)) ?? $report->customer_id,
                    'macchina' => $target instanceof MachineUnit ? ($target->model_name.' ('.$target->serial_number.')') : '— (a livello cliente)',
                    'crm_precedente' => $label($customersById->get($currentPayerId)) ?? $currentPayerId,
                    'eureka_dice' => $label($customersById->get($payerId)) ?? $report->eureka_destinazione_label,
                    'rapportino' => $report->number.' ('.$report->intervention_date?->format('d/m/Y').')',
                ];
            }

            $applied++;
            $this->line(sprintf(
                '  <info>%s</info> %s → billing_customer_id = %s',
                $dryRun ? 'SAREBBE APPLICATO' : 'APPLICATO',
                $target instanceof MachineUnit ? "MachineUnit {$target->model_name} ({$target->serial_number})" : 'Customer '.$label($target),
                $label($customersById->get($payerId)),
            ));

            if (! $dryRun) {
                $target->billing_customer_id = $payerId;
                $target->save();
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%sApplicati: %d (di cui %d sovrascritti su un valore CRM diverso). Gia\' corretti: %d. Macchine ambigue (matricola condivisa tra piu\' clienti, saltate): %d. Codice destinazione non risolvibile in CRM: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $applied,
            count($overwritten),
            $alreadyCorrect,
            $ambiguous,
            $unresolved,
        ));

        if ($overwritten !== []) {
            $this->newLine();
            $this->warn('Sovrascritti — CRM aveva gia\' un billing_customer_id diverso, sostituito con quanto dice Eureka (fonte di verita\'):');
            $this->table(
                ['Cliente', 'Macchina', 'CRM precedente', 'Eureka dice', 'Rapportino'],
                collect($overwritten)->map(fn (array $row) => [
                    $row['cliente'], $row['macchina'], $row['crm_precedente'], $row['eureka_dice'], $row['rapportino'],
                ])->all(),
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
