<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\EurekaClient;
use Illuminate\Console\Command;

/**
 * ImportEurekaServiceReports non ha mai letto il campo "destinazione" della
 * scheda lavoro (chi paga davvero, se diverso dall'intestatario/luogo
 * dell'intervento - vedi doc API §6.1): quel dato quindi non e' mai finito in
 * CRM, e billing_customer_id (su Customer/MachineUnit) puo' essere rimasto
 * vuoto o sbagliato anche quando Eureka lo sa gia' da tempo (trovato dal vivo
 * su Hotel Margherita di Tiepolo Susanna / Martellozzo Lorenzo, 2026-08-13).
 *
 * Sola lettura: confronta, non corregge. Una chiamata per gruppo
 * cliente+macchina (non per rapportino) - il pagante e' una relazione di
 * business stabile nel tempo, non serve verificare ogni singolo documento
 * storico. Stesso meccanismo pooled/rate-limited di --with-detail
 * (EurekaClient::pooledGetServiceReports), che ha gia' dimostrato di reggere
 * al volume su questo stesso storico.
 */
class AuditEurekaBillingDestinazione extends Command
{
    protected $signature = 'eureka:audit-billing-destinazione
        {--tenant=       : Slug tenant (default: tenant master)}
        {--customer=     : UUID cliente locale — limita l\'audit a un solo cliente}
        {--limit=        : Numero massimo di gruppi cliente+macchina da controllare}';

    protected $description = "Confronta (sola lettura) il pagante reale su Eureka (campo destinazione) con billing_customer_id in CRM, per rapportini gia' importati";

    public function __construct(private readonly EurekaClient $eureka)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = $this->resolveTenant();

        $reports = ServiceReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('eureka_service_report_id')
            ->when($this->option('customer'), fn ($q) => $q->where('customer_id', $this->option('customer')))
            ->with(['customer', 'machineUnit.billingCustomer'])
            ->orderByDesc('intervention_date')
            ->get(['id', 'customer_id', 'machine_unit_id', 'eureka_service_report_id', 'intervention_date', 'number']);

        if ($reports->isEmpty()) {
            $this->warn('Nessun rapportino con eureka_service_report_id trovato per questo filtro.');

            return self::SUCCESS;
        }

        // Un solo rappresentante per gruppo cliente+macchina: il piu' recente
        // (gia' ordinato sopra), cosi' riflette lo stato attuale del pagante,
        // non uno storico superato.
        $groups = $reports->unique(fn (ServiceReport $r) => $r->customer_id.'|'.($r->machine_unit_id ?? 'none'));

        $limit = max(0, (int) ($this->option('limit') ?: 0));
        if ($limit > 0) {
            $groups = $groups->take($limit);
        }

        $this->info(sprintf('Gruppi cliente+macchina da controllare: %d (su %d rapportini totali)', $groups->count(), $reports->count()));

        // Mappa gestionale_code -> Customer locale, per risolvere l'id_eureka
        // della destinazione senza una query per riga.
        $customerByCode = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('gestionale_code')
            ->get(['id', 'gestionale_code', 'company_name', 'first_name', 'last_name'])
            ->keyBy(fn (Customer $c) => (int) $c->gestionale_code);

        $ids = $groups->pluck('eureka_service_report_id')->map(fn ($id) => (int) $id)->all();

        $this->info('Interrogo Eureka (pooled, rate-limited)...');
        $details = $this->eureka->pooledGetServiceReports($ids);

        $missing = count($ids) - count($details);
        if ($missing > 0) {
            $this->warn("Dettaglio non recuperato per {$missing} rapportini (errore o timeout) — esclusi dal report.");
        }

        $mismatches = [];
        $matches = 0;
        $unresolved = 0;

        foreach ($groups as $report) {
            $detail = $details[(int) $report->eureka_service_report_id] ?? null;
            if (! $detail) {
                continue;
            }

            $destinazioneCode = (int) ($detail['destinazione']['id_eureka'] ?? 0);
            $eurekaPayer = $destinazioneCode > 0 ? ($customerByCode[$destinazioneCode] ?? null) : null;

            // destinazione a volte ripete la stessa anagrafica dell'intestatario
            // (stesso id_eureka del cliente del rapportino): equivale a "nessun
            // pagante diverso", non e' un mismatch - va trattato come assenza di
            // destinazione (visto dal vivo su Hotel Marco Polo Carlotta SAS & C.).
            if ($eurekaPayer?->id === $report->customer_id) {
                $eurekaPayer = null;
                $destinazioneCode = 0;
            }

            $currentPayer = $report->machine_unit_id
                ? $report->machineUnit?->billingCustomer
                : $report->customer?->billingCustomer;

            $eurekaPayerId = $eurekaPayer?->id;
            $currentPayerId = $currentPayer?->id;

            if ($eurekaPayerId === $currentPayerId) {
                $matches++;

                continue;
            }

            // destinazione valorizzata su Eureka ma nessun cliente locale con
            // quel gestionale_code: non e' un mismatch collegabile subito,
            // serve prima capire chi e' (magari mai creato in CRM).
            if ($destinazioneCode > 0 && ! $eurekaPayer) {
                $unresolved++;
            }

            $mismatches[] = [
                'cliente' => $report->customer?->company_name ?? $report->customer?->full_name ?? $report->customer_id,
                'macchina' => $report->machineUnit?->display_name ?? '— (nessuna macchina collegata)',
                'crm_oggi' => $currentPayer?->company_name ?? $currentPayer?->full_name ?? '— (nessuno, paga il cliente stesso)',
                'eureka' => $destinazioneCode > 0
                    ? ($eurekaPayer?->company_name ?? $eurekaPayer?->full_name ?? "codice {$destinazioneCode} — non trovato in CRM")
                    : '— (nessuno, paga il cliente stesso)',
                'rapportino' => $report->number.' ('.$report->intervention_date?->format('d/m/Y').')',
            ];
        }

        $this->newLine();
        $this->info(sprintf(
            'Confrontati: %d. Coerenti: %d. Discrepanze: %d (di cui %d con pagante Eureka non ancora presente in CRM).',
            $matches + count($mismatches),
            $matches,
            count($mismatches),
            $unresolved,
        ));

        if ($mismatches !== []) {
            $this->newLine();
            $this->table(
                ['Cliente', 'Macchina', 'CRM oggi', 'Eureka dice', 'Rapportino'],
                collect($mismatches)->map(fn (array $row) => [
                    $row['cliente'], $row['macchina'], $row['crm_oggi'], $row['eureka'], $row['rapportino'],
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
