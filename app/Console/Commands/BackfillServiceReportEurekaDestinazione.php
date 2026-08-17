<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\EurekaClient;
use Illuminate\Console\Command;

/**
 * ImportEurekaServiceReports non ha mai letto "destinazione" (chi paga
 * davvero, se diverso dall'intestatario - doc API §6.1) prima del
 * 2026-08-13: tutti i rapportini gia' importati hanno quindi
 * eureka_destinazione_code/label vuoti anche quando Eureka lo sa da tempo.
 * Una tantum: rilegge solo quel campo per lo storico gia' in CRM, senza
 * toccare nient'altro sul rapportino (stesso pooledGetServiceReports gia'
 * usato da --with-detail e dall'audit di sola lettura, stesso
 * rate-limiting).
 */
class BackfillServiceReportEurekaDestinazione extends Command
{
    protected $signature = 'service-reports:backfill-eureka-destinazione
        {--tenant=       : Slug tenant (default: tenant master)}
        {--limit=        : Numero massimo di rapportini da controllare (per un giro di prova)}
        {--dry-run       : Mostra quanti rapportini verrebbero aggiornati senza scrivere nulla}';

    protected $description = "Rilegge da Eureka il campo destinazione (chi paga davvero) per i rapportini gia' importati";

    public function __construct(private readonly EurekaClient $eureka)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $dryRun = (bool) $this->option('dry-run');

        $limit = max(0, (int) ($this->option('limit') ?: 0));

        $reports = ServiceReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('eureka_service_report_id')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get(['id', 'eureka_service_report_id', 'eureka_destinazione_code', 'eureka_destinazione_label']);

        $this->info(sprintf('%sRapportini da controllare: %d', $dryRun ? '[DRY RUN] ' : '', $reports->count()));

        $ids = $reports->pluck('eureka_service_report_id')->map(fn ($id) => (int) $id)->all();

        $this->info('Interrogo Eureka (pooled, rate-limited)...');
        $details = $this->eureka->pooledGetServiceReports($ids);

        $missing = count($ids) - count($details);
        if ($missing > 0) {
            $this->warn("Dettaglio non recuperato per {$missing} rapportini (errore o timeout) — lasciati invariati.");
        }

        $updated = 0;
        $unchanged = 0;

        foreach ($reports as $report) {
            $detail = $details[(int) $report->eureka_service_report_id] ?? null;
            if (! $detail) {
                continue;
            }

            $destinazioneCode = (int) ($detail['destinazione']['id_eureka'] ?? 0);
            $intestatarioCode = (int) ($detail['id_intestatario'] ?? 0);
            // Stessa normalizzazione di ImportEurekaServiceReports: una
            // destinazione che ripete l'anagrafica dell'intestatario
            // equivale a "nessun pagante diverso".
            $hasDestinazione = $destinazioneCode > 0 && $destinazioneCode !== $intestatarioCode;

            $report->eureka_destinazione_code = $hasDestinazione ? $destinazioneCode : null;
            $report->eureka_destinazione_label = $hasDestinazione
                ? $this->normalizeText($detail['destinazione']['rag_sociale'] ?? null)
                : null;

            if (! $report->isDirty()) {
                $unchanged++;

                continue;
            }

            $updated++;

            if (! $dryRun) {
                // saveQuietly(): questi due campi non hanno alcun effetto su
                // syncMaintenanceSchedule() (booted 'saved') - inutile e
                // rischioso far scattare quella logica su 3500+ righe solo
                // per un aggiornamento di sola metadata.
                $report->saveQuietly();
            }
        }

        $this->info(sprintf(
            '%sAggiornati: %d. Gia\' corretti: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $updated,
            $unchanged,
        ));

        return self::SUCCESS;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $text !== '' ? $text : null;
    }

    private function resolveTenant(): Tenant
    {
        $slug = trim((string) $this->option('tenant'));

        return $slug !== ''
            ? Tenant::query()->where('slug', $slug)->firstOrFail()
            : Tenant::query()->where('is_master', true)->firstOrFail();
    }
}
