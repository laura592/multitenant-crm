<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EurekaClient;
use App\Support\Geocoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa/aggiorna i rapportini storici dal gestionale Eureka.
 *
 * Comportamento:
 * - Scarica la lista di rapportini per periodo con una sola chiamata HTTP.
 * - Usa eureka_service_report_id come chiave di idempotenza.
 * - Crea automaticamente clienti e prodotti mancanti quando Eureka li fornisce.
 * - Prefers il tecnico Alessandro Signorato come fallback se non viene passato
 *   un tecnico esplicito.
 * - Con --with-detail fa anche GET /schedelavoro/(id) per ogni record (lento:
 *   ~2s/chiamata) per importare sl_sintomo, sl_lavorazione e righe dettaglio.
 */
class ImportEurekaServiceReports extends Command
{
    protected $signature = 'eureka:import-service-reports
        {--tenant=       : Slug tenant (default: tenant master)}
        {--customer=     : UUID cliente locale — limita l\'import a un solo cliente}
        {--from=         : Data inizio periodo YYYY-MM-DD (default: inizio anno corrente)}
        {--to=           : Data fine periodo YYYY-MM-DD (default: oggi)}
        {--technician=   : Email o UUID del tecnico da assegnare ai rapportini importati}
        {--limit=        : Numero massimo di rapportini da importare}
        {--with-detail   : Recupera anche sl_sintomo/sl_lavorazione/dettaglio con GET singolo (lento)}
        {--dry-run       : Mostra cosa verrebbe fatto senza scrivere nulla}';

    protected $description = 'Importa/aggiorna i rapportini storici dal gestionale Eureka';

    public function __construct(private readonly EurekaClient $eureka)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        $technician = $this->resolveTechnician($tenant);
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) ($this->option('limit') ?: 0));

        $from = $this->option('from') ?: date('Y').'-01-01';
        $to = $this->option('to') ?: date('Y-m-d');

        $this->info(sprintf(
            '%sTenant: %s — periodo: %s → %s',
            $dryRun ? '[DRY RUN] ' : '',
            $tenant->slug,
            $from,
            $to,
        ));

        // Raccolta rapportini: se si filtra per cliente specifico, query per customer;
        // altrimenti una sola query per periodo (molto più veloce su 2000+ clienti).
        $summaries = collect();

        if ($this->option('customer')) {
            $customers = Customer::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('gestionale_code')
                ->whereKey($this->option('customer'))
                ->get();

            if ($customers->isEmpty()) {
                $this->warn('Cliente non trovato o senza gestionale_code.');

                return self::SUCCESS;
            }

            foreach ($customers as $customer) {
                $code = (int) $customer->gestionale_code;
                if ($code <= 0) {
                    continue;
                }

                try {
                    $rows = $this->eureka->searchServiceReports([
                        'id_codice_f15' => $code,
                        'data_da' => $from.'T00:00:00',
                        'data_a' => $to.'T23:59:59',
                    ]);
                } catch (\Throwable $e) {
                    $this->warn("Errore ricerca rapportini cliente {$customer->company_name}: {$e->getMessage()}");
                    continue;
                }

                foreach ($rows as $row) {
                    $summaries->push($row + ['_local_customer_id' => $customer->id]);
                }
            }
        } else {
            // Ricerca per periodo: una sola chiamata, Eureka restituisce tutti i rapportini
            try {
                $rows = $this->eureka->searchServiceReports([
                    'data_da' => $from.'T00:00:00',
                    'data_a' => $to.'T23:59:59',
                ]);
                foreach ($rows as $row) {
                    $summaries->push($row);
                }
            } catch (\Throwable $e) {
                $this->error("Errore ricerca rapportini per periodo: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $summaries = $summaries
            ->unique(fn (array $row) => (int) ($row['id'] ?? 0))
            ->sortByDesc(fn (array $row) => (string) ($row['data_documento'] ?? $row['data'] ?? ''))
            ->values();

        if ($limit > 0) {
            $summaries = $summaries->take($limit)->values();
        }

        $withDetail = (bool) $this->option('with-detail');
        $this->info(sprintf(
            'Rapportini da elaborare: %d%s',
            $summaries->count(),
            $withDetail ? ' (con dettaglio — lento)' : '',
        ));

        // Pre-carica la mappa gestionale_code → UUID locale per evitare N query
        $customerMap = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('gestionale_code')
            ->pluck('id', 'gestionale_code')
            ->all();

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($summaries as $summary) {
            $eurekaId = (int) ($summary['id'] ?? 0);
            if ($eurekaId <= 0) {
                continue;
            }

            // Recupera dettaglio solo se richiesto (lento: ~2s/call)
            $detail = null;
            if ($withDetail) {
                try {
                    $detail = $this->eureka->getServiceReport($eurekaId);
                } catch (\Throwable $e) {
                    $this->warn("Errore lettura dettaglio #{$eurekaId}: {$e->getMessage()}");
                }
            }

            // Risolve cliente: dal ciclo --customer (già nel summary) o dalla mappa,
            // creando eventualmente il cliente locale se Eureka lo identifica.
            // $dryRun passato esplicitamente: senza, questa chiamata scriveva
            // clienti/prodotti nuovi anche in modalita' di prova (bug reale,
            // trovato verificando l'import su dati veri).
            $localCustomerId = $this->resolveLocalCustomerId($tenant, $summary, $detail, $customerMap, $dryRun);

            if (! $localCustomerId) {
                $skipped++;
                continue;
            }

            // Risolve prodotto macchina: prima dal detail (ha 'sl_articolo'), poi dal summary
            $articleId = (int) (($detail['sl_articolo']['id_eureka'] ?? null) ?: ($summary['id_articolo_m10'] ?? 0));
            $machineProduct = $this->resolveOrCreateProduct($tenant, [
                'id_eureka' => $articleId,
                'codice' => (($detail['sl_articolo']['codice'] ?? null) ?: ($summary['codice_articolo'] ?? null)),
                'descr1' => (($detail['sl_articolo']['descr1'] ?? null) ?: ($summary['descr_articolo_1'] ?? null)),
                'descrizione' => (($detail['sl_articolo']['descr1'] ?? null) ?: ($summary['descr_articolo_1'] ?? null)),
            ], $dryRun);

            $machineSerial = $this->normalizeText(
                ($detail ? ($detail['sl_matricola'] ?? null) : null)
                ?? ($summary['matricola'] ?? null)
            );
            $machineUnit = $machineSerial
                ? MachineUnit::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereRaw('LOWER(serial_number) = LOWER(?)', [$machineSerial])
                    ->first()
                : null;

            $dateRaw = $detail['data'] ?? $summary['data_documento'] ?? null;
            $interventionDate = $dateRaw ? substr((string) $dateRaw, 0, 10) : now()->toDateString();

            $payload = [
                'tenant_id' => $tenant->id,
                'eureka_service_report_id' => $eurekaId,
                'customer_id' => $localCustomerId,
                'machine_product_id' => $machineProduct?->id,
                'machine_unit_id' => $machineUnit?->id,
                'machine_serial_number' => $machineSerial,
                'technician_id' => $technician?->id,
                'intervention_type' => $detail
                    ? $this->mapInterventionType($detail)
                    : ServiceReport::TYPE_RIPARAZIONE,
                'intervention_date' => $interventionDate,
                'arrival_at' => $detail['sl_dataora_appuntamento'] ?? null,
                'problem_description' => $detail
                    ? $this->normalizeText($detail['sl_sintomo'] ?? null)
                    : null,
                'work_performed' => ($detail
                    ? $this->normalizeText($detail['sl_lavorazione'] ?? null)
                    : null) ?: 'Importato da Eureka',
                'status' => $this->mapStatus($detail['stato_documento'] ?? $summary['stato_documento'] ?? null),
                'notes' => $detail ? $this->buildNotes($detail) : null,
            ];

            $existing = ServiceReport::query()
                ->where('tenant_id', $tenant->id)
                ->where('eureka_service_report_id', $eurekaId)
                ->first();

            if ($existing) {
                $existing->fill($payload);

                if (! $existing->isDirty()) {
                    $unchanged++;
                    continue;
                }

                $this->line("  <info>UPDATE</info> rapportino #{$eurekaId} ({$existing->number})");
                $updated++;

                if (! $dryRun) {
                    DB::transaction(function () use ($tenant, $existing, $detail): void {
                        $existing->save();
                        if ($detail) {
                            $this->syncDetailRows($tenant, $existing, $detail);
                        }
                    });
                }
            } else {
                $number = $this->resolveUniqueNumber($tenant, $detail ?? $summary);
                $this->line("  <info>CREATE</info> rapportino #{$eurekaId} → {$number}");
                $created++;

                if (! $dryRun) {
                    DB::transaction(function () use ($tenant, $payload, $detail, $number): void {
                        $report = new ServiceReport($payload);
                        $report->number = $number;
                        $report->save();
                        if ($detail) {
                            $this->syncDetailRows($tenant, $report, $detail);
                        }
                    });
                }
            }
        }

        $this->info(sprintf(
            '%sCompletato. Creati: %d, aggiornati: %d, invariati: %d, saltati: %d.',
            $dryRun ? '[DRY RUN] ' : '',
            $created,
            $updated,
            $unchanged,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function resolveTenant(): Tenant
    {
        $slug = trim((string) $this->option('tenant'));

        $tenant = $slug !== ''
            ? Tenant::query()->where('slug', $slug)->firstOrFail()
            : Tenant::query()->where('is_master', true)->firstOrFail();

        return $tenant;
    }

    private function resolveTechnician(Tenant $tenant): ?User
    {
        $option = trim((string) $this->option('technician'));

        if ($option !== '') {
            $user = User::query()
                ->where('tenant_id', $tenant->id)
                ->where(fn ($q) => $q->where('email', $option)->orWhere('id', $option))
                ->first();

            if (! $user) {
                $this->warn("Tecnico '{$option}' non trovato — uso il fallback preferito.");
            } else {
                return $user;
            }
        }

        $preferred = User::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query): void {
                $query->where('email', 'like', '%signorato%')
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%signorato%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%alessandro%']);
            })
            ->first();

        return $preferred
            ?? User::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('created_at')
                ->first();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $detail
     * @param array<int|string, string> $customerMap
     */
    private function resolveLocalCustomerId(Tenant $tenant, array $row, ?array $detail, array &$customerMap, bool $dryRun): ?string
    {
        foreach (array_filter([
            (int) ($row['id_codice_f15'] ?? 0),
            (int) ($detail['id_intestatario'] ?? 0),
            (int) ($detail['id_codice_f15'] ?? 0),
        ], fn (int $code) => $code > 0) as $code) {
            if (isset($customerMap[$code])) {
                return $customerMap[$code];
            }

            $existing = Customer::query()
                ->where('tenant_id', $tenant->id)
                ->where('gestionale_code', $code)
                ->first();

            if ($existing) {
                $customerMap[$code] = $existing->id;

                return $existing->id;
            }

            $created = $this->resolveOrCreateCustomer($tenant, $code, $row, $detail, $dryRun);
            if ($created) {
                $customerMap[$code] = $created->id;

                return $created->id;
            }
        }

        return null;
    }

    private function resolveOrCreateCustomer(Tenant $tenant, int $code, array $row, ?array $detail, bool $dryRun): ?Customer
    {
        if ($code <= 0) {
            return null;
        }

        $existing = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->where('gestionale_code', $code)
            ->first();

        if ($existing) {
            return $existing;
        }

        $companyName = $this->extractCustomerCompanyName($row, $detail)
            ?: 'Cliente Eureka '.$code;

        if ($dryRun) {
            $this->line("  <comment>[DRY RUN] Cliente NON creato: {$companyName} (codice gestionale {$code})</comment>");

            return null;
        }

        $address = $this->extractCustomerAddress($row, $detail);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'source' => Customer::SOURCE_GESTIONALE,
            'company_name' => $companyName,
            'gestionale_code' => $code,
            'emails' => $this->extractCustomerEmails($row, $detail),
            'phones' => $this->extractCustomerPhones($row, $detail),
            ...$address,
        ]);

        $this->geocodeIfPossible($customer);

        return $customer;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $detail
     * @return array{street: ?string, postal_code: ?string, city: ?string, province: ?string}
     */
    private function extractCustomerAddress(array $row, ?array $detail): array
    {
        $pick = function (array $keys) use ($row, $detail) {
            foreach ($keys as $key) {
                $value = $detail['intestatario'][$key] ?? $detail[$key] ?? $row[$key] ?? null;
                $normalized = $value !== null ? $this->normalizeText($value) : null;
                if ($normalized) {
                    return $normalized;
                }
            }

            return null;
        };

        return [
            'street' => $pick(['indirizzo', 'indirizzo1', 'via']),
            'postal_code' => $pick(['cap']),
            'city' => $pick(['citta', 'comune']),
            // 'sigla_prov' e' la chiave confermata vista nelle risposte reali
            // di GET /anagrafica/cerca (vedi Customer::GESTIONALE_TRACKED_FIELDS
            // doc comment) — le altre restano un ripiego best-effort.
            'province' => $pick(['sigla_prov', 'provincia', 'prov']),
        ];
    }

    /**
     * Eureka non fornisce coordinate: senza questo, i clienti creati da qui
     * (source gestionale) restano invisibili a "Cliente piu' vicino"
     * (App\Filament\Pages\ClientiVicini, filtra su lat/lng non nulle) finche'
     * qualcuno non passa a mano dal form o non lancia
     * customers:geocode-coordinates. Se extractCustomerAddress() non trova
     * nulla di utile (i nomi dei campi Eureka sono un'ipotesi, non
     * documentati) semplicemente non succede nulla, come oggi.
     */
    private function geocodeIfPossible(Customer $customer): void
    {
        if (blank($customer->city) && blank($customer->postal_code)) {
            return;
        }

        $coords = Geocoder::geocodeBestEffort($customer->geocodingAddressCandidates());
        usleep(1_100_000);

        if (! $coords) {
            return;
        }

        $customer->forceFill([
            'latitude' => $coords['lat'],
            'longitude' => $coords['lng'],
        ])->save();
    }

    private function resolveOrCreateProduct(Tenant $tenant, array $data, bool $dryRun = false): ?Product
    {
        if (! is_array($data)) {
            return null;
        }

        $articleId = (int) ($data['id_eureka'] ?? 0);
        $code = $this->normalizeText($data['codice'] ?? null);
        $name = $this->normalizeText($data['descr1'] ?? $data['descrizione'] ?? null) ?: 'Prodotto Eureka';
        $description = $this->normalizeText($data['descrizione'] ?? $data['descr1'] ?? null);

        if ($articleId <= 0 && ! $code) {
            return null;
        }

        $existing = Product::query()
            ->where('tenant_id', $tenant->id)
            ->when($articleId > 0, fn ($q) => $q->where('eureka_article_id', $articleId))
            ->when($code !== null, fn ($q) => $q->where('sku', $code))
            ->first();

        if ($existing) {
            return $existing;
        }

        $sku = $code ?: 'eureka-'.$articleId;
        $existingBySku = Product::query()->where('sku', $sku)->first();
        if ($existingBySku) {
            return $existingBySku;
        }

        if ($dryRun) {
            $this->line("  <comment>[DRY RUN] Prodotto macchina NON creato: {$name} (sku {$sku})</comment>");

            return null;
        }

        return Product::create([
            'tenant_id' => $tenant->id,
            'sku' => $sku,
            'type' => 'service',
            'name' => $name,
            'description' => $description,
            'eureka_article_id' => $articleId > 0 ? $articleId : null,
            'source' => 'terzo',
        ]);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function syncDetailRows(Tenant $tenant, ServiceReport $report, array $detail): void
    {
        $report->materialsUsed()->delete();

        foreach (($detail['dettaglio'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $material = $this->resolveOrCreateMaterial($tenant, [
                'id_eureka' => $row['id_articolo'] ?? null,
                'codice' => $row['codice'] ?? null,
                'descr1' => $row['descrizione'] ?? null,
                'descrizione' => $row['descrizione'] ?? null,
            ]);

            if (! $material) {
                continue;
            }

            ServiceReportMaterial::create([
                'service_report_id' => $report->id,
                'material_id' => $material->id,
                'quantity' => max(0.0, (float) ($row['quantita'] ?? 1)),
                'unit_cost_snapshot' => (float) ($row['prezzo'] ?? 0) ?: null,
                'notes' => $this->normalizeText($row['descrizione'] ?? null),
            ]);
        }
    }

    /**
     * Come resolveOrCreateProduct() ma su Materiali: i ricambi/articoli usati
     * in una scheda lavoro (dettaglio[]) non vanno nel catalogo preventivi
     * (Product) — finirebbero anche li' nel selettore, mescolati al listino
     * ufficiale. sl_articolo (bene/macchina principale) resta invece su
     * Product tramite resolveOrCreateProduct(), non lo tocca questo metodo.
     *
     * @param array<string, mixed> $data
     */
    private function resolveOrCreateMaterial(Tenant $tenant, array $data): ?Material
    {
        $articleId = (int) ($data['id_eureka'] ?? 0);
        $code = $this->normalizeText($data['codice'] ?? null);
        $type = $this->normalizeText($data['descr1'] ?? $data['descrizione'] ?? null) ?: 'Materiale Eureka';

        if ($articleId <= 0 && ! $code) {
            return null;
        }

        $existing = Material::query()
            ->where('tenant_id', $tenant->id)
            ->when($articleId > 0, fn ($q) => $q->where('gestionale_code', $articleId))
            ->when($code !== null, fn ($q) => $q->orWhere('code', $code))
            ->first();

        if ($existing) {
            return $existing;
        }

        $materialCode = $code ?: 'eureka-'.$articleId;
        $existingByCode = Material::query()->where('code', $materialCode)->first();
        if ($existingByCode) {
            return $existingByCode;
        }

        return Material::create([
            'tenant_id' => $tenant->id,
            'source' => Material::SOURCE_EUREKA,
            'code' => $materialCode,
            'gestionale_code' => $articleId > 0 ? $articleId : null,
            'category' => 'Eureka',
            'type' => $type,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $detail
     */
    private function extractCustomerCompanyName(array $row, ?array $detail): ?string
    {
        foreach (array_filter([
            $detail['intestatario']['rag_sociale'] ?? null,
            $detail['intestatario']['rag_sociale_1'] ?? null,
            $detail['rag_sociale'] ?? null,
            $row['rag_sociale_1'] ?? null,
            $row['rag_sociale'] ?? null,
            $row['ragione_sociale'] ?? null,
            $row['nome'] ?? null,
            $detail['nome'] ?? null,
        ], fn ($value) => $value !== null && $value !== '') as $value) {
            $normalized = $this->normalizeText($value);
            if ($normalized) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $detail
     * @return array<int, string>
     */
    private function extractCustomerEmails(array $row, ?array $detail): array
    {
        $values = array_filter([
            $detail['intestatario']['email'] ?? null,
            $detail['email'] ?? null,
            $row['email'] ?? null,
            $row['mail'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => $this->normalizeText($value),
            $values,
        ), fn ($value) => $value !== null)));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $detail
     * @return array<int, string>
     */
    private function extractCustomerPhones(array $row, ?array $detail): array
    {
        $values = array_filter([
            $detail['intestatario']['nr_telefono'] ?? null,
            $detail['telefono'] ?? null,
            $row['nr_telefono'] ?? null,
            $row['telefono'] ?? null,
            $row['tel'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => $this->normalizeText($value),
            $values,
        ), fn ($value) => $value !== null)));
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function mapInterventionType(array $detail): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $this->normalizeText($detail['sl_lavorazione'] ?? null),
            $this->normalizeText($detail['sl_sintomo'] ?? null),
        ])));

        return match (true) {
            str_contains($haystack, 'install') => ServiceReport::TYPE_INSTALLAZIONE,
            str_contains($haystack, 'garanzia') => ServiceReport::TYPE_GARANZIA,
            str_contains($haystack, 'manutenz') => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            default => ServiceReport::TYPE_RIPARAZIONE,
        };
    }

    private function mapStatus(mixed $statoDocumento): string
    {
        // stato_documento=10 → documento aperto/in corso → bozza
        // altrimenti → completato
        return (int) $statoDocumento === 10 ? 'bozza' : 'completato';
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function buildNotes(array $detail): ?string
    {
        $parts = array_filter([
            $this->normalizeText($detail['note'] ?? null),
            ($numero = (int) ($detail['numero'] ?? 0)) > 0
                ? "Numero documento Eureka: {$numero}"
                : null,
        ]);

        return $parts ? implode("\n\n", $parts) : null;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function resolveUniqueNumber(Tenant $tenant, array $detail): string
    {
        // Il detail usa 'numero', il summary usa 'numero_doc_t23'
        $numero = (int) ($detail['numero'] ?? $detail['numero_doc_t23'] ?? 0);
        $base = $numero > 0 ? 'SL-'.$numero : 'SL-EK-'.(int) ($detail['id_eureka'] ?? 0);

        $candidate = $base;
        $i = 2;
        while (ServiceReport::query()->where('tenant_id', $tenant->id)->where('number', $candidate)->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Rimuove \r (inserito da Eureka a metà stringa, cf. spec §6.1) e collassa spazi
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\r", ' ', (string) $value)));

        return $text !== '' ? $text : null;
    }
}
