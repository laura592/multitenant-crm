<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeCustomerJob;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Support\Gestionale\RegistroSync;
use App\Models\User;
use App\Support\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Importa/aggiorna i rapportini storici dal gestionale Eureka.
 *
 * Comportamento:
 * - Scarica la lista di rapportini per periodo con una sola chiamata HTTP.
 * - Usa eureka_service_report_id come chiave di idempotenza.
 * - Crea automaticamente clienti e articoli mancanti quando Eureka li fornisce.
 *   Gli articoli (sia il bene di sl_articolo sia i ricambi del dettaglio)
 *   finiscono in Materiali: su Eureka sono un'anagrafica sola, e il catalogo
 *   Prodotti resta quello commerciale a listino, usato per i preventivi.
 * - Prefers il tecnico Alessandro Signorato come fallback se non viene passato
 *   un tecnico esplicito.
 * - Con --with-detail fa anche GET /schedelavoro/(id) per ogni record, per
 *   importare sl_sintomo, sl_lavorazione e righe dettaglio — in gruppi
 *   concorrenti (vedi EurekaClient::pooledGetServiceReports), non piu' una
 *   chiamata alla volta (~2s/call in sequenza era il vero collo di
 *   bottiglia su import di migliaia di rapportini).
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
        {--with-detail   : Recupera anche sl_sintomo/sl_lavorazione/dettaglio (una chiamata extra per rapportino, in pool)}
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

        // Pre-carica i rapportini gia' importati (per eureka_service_report_id)
        // per evitare una query di lookup per ogni riga del ciclo sotto.
        $existingReportsMap = ServiceReport::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('eureka_service_report_id', $summaries->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy('eureka_service_report_id');

        // Recupera tutti i dettagli in gruppi concorrenti invece che uno alla
        // volta: era il vero collo di bottiglia di --with-detail (~2s/call in
        // sequenza su migliaia di rapportini, vedi EurekaClient::pooledGetServiceReports).
        $detailsById = [];
        if ($withDetail) {
            $detailsById = $this->eureka->pooledGetServiceReports(
                $summaries->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );

            $missing = $summaries->count() - count($detailsById);
            if ($missing > 0) {
                $this->warn("Dettaglio non recuperato per {$missing} rapportini (errore o risposta non valida) — importati solo dai dati di riepilogo.");
            }
        }

        // Cache in memoria per prodotti/materiali risolti in questo ciclo:
        // resolveExistingProduct/resolveOrCreateMaterial altrimenti ri-
        // interrogano il DB per ogni riga anche quando la chiave e' identica
        // a una gia' vista poco prima (es. stessa macchina su piu' rapportini).
        $productCache = [];
        $materialCache = [];

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($summaries as $summary) {
            $eurekaId = (int) ($summary['id'] ?? 0);
            if ($eurekaId <= 0) {
                continue;
            }

            $detail = $detailsById[$eurekaId] ?? null;

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

            // Risolve l'articolo del bene: prima dal detail (ha 'sl_articolo'), poi dal summary.
            //
            // Il bene di sl_articolo e' un articolo Eureka come i ricambi del
            // dettaglio, quindi la sua casa e' Materiali: creare qui un Product
            // per ogni macchina incontrata riempiva il catalogo preventivi di
            // apparecchi del parco installato che a listino non esistono (e che
            // eureka:sweep-materials-catalog aveva spesso gia' importato in
            // Materiali, con lo stesso codice). Un Product gia' a catalogo lo
            // riusiamo comunque, se il codice combacia: e' il caso delle
            // macchine che vendiamo davvero (Franke, Dalla Corte, Bianchi).
            $articleId = (int) (($detail['sl_articolo']['id_eureka'] ?? null) ?: ($summary['id_articolo_m10'] ?? 0));
            $articleData = [
                'id_eureka' => $articleId,
                'codice' => (($detail['sl_articolo']['codice'] ?? null) ?: ($summary['codice_articolo'] ?? null)),
                'descr1' => (($detail['sl_articolo']['descr1'] ?? null) ?: ($summary['descr_articolo_1'] ?? null)),
                'descrizione' => (($detail['sl_articolo']['descr1'] ?? null) ?: ($summary['descr_articolo_1'] ?? null)),
            ];
            $machineProduct = $this->resolveExistingProduct($tenant, $articleData, $productCache, $dryRun);
            $machineMaterial = $this->resolveOrCreateMaterial($tenant, $articleData, $materialCache, $dryRun);

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

            // La matricola nasce da un articolo: questo e' l'unico punto in cui
            // Eureka ci passa i due dati insieme (sl_matricola + sl_articolo),
            // quindi e' qui che si aggancia il macchinario al suo articolo. Solo
            // se manca: un collegamento gia' messo (a mano o da un import
            // precedente) non va sovrascritto.
            if ($machineUnit && $machineMaterial && blank($machineUnit->material_id) && ! $dryRun) {
                $machineUnit->update(['material_id' => $machineMaterial->id]);
            }

            // Eureka distingue "data" (data del documento, spesso quando la
            // scheda e' stata archiviata in ufficio) da
            // "sl_dataora_appuntamento" (quando il tecnico e' stato davvero
            // dal cliente, a volte giorni prima) — quest'ultima esiste solo
            // nel detail (--with-detail), non nel summary della lista.
            // intervention_date deve riflettere la data vera
            // dell'intervento, non quella del documento: la data documento
            // resta comunque tracciata a parte in gestionale_document_date.
            $documentDateRaw = $detail['data'] ?? $summary['data_documento'] ?? null;
            $documentDate = $documentDateRaw ? substr((string) $documentDateRaw, 0, 10) : now()->toDateString();

            // sl_dataora_appuntamento non e' sempre affidabile: su un piccolo
            // numero di schede storiche contiene anni palesemente corrotti
            // lato Eureka (es. "0245-09-11", "1024-10-22", "2027-06-03" per
            // un documento del 2024) — trovato confrontando 3596 rapportini
            // gia' importati: il 99.5% ha un gap <= 180 giorni dalla data
            // documento, i pochi corrotti sono a migliaia di giorni o
            // addirittura secoli di distanza. 400 giorni tiene tutti i gap
            // plausibili (max osservato tra quelli sani: 371) scartando solo
            // i valori chiaramente spazzatura.
            $appointmentRaw = $detail['sl_dataora_appuntamento'] ?? null;
            $appointmentDate = $appointmentRaw ? substr((string) $appointmentRaw, 0, 10) : null;

            if ($appointmentDate && abs(Carbon::parse($documentDate)->diffInDays(Carbon::parse($appointmentDate), false)) > 400) {
                $appointmentDate = null;
            }

            $interventionDate = $appointmentDate ?? $documentDate;

            // "destinazione" (doc API §6.1): chi paga davvero, se diverso
            // dall'intestatario - mai letta prima da questo import (vedi
            // audit fatturazione comodato, 2026-08-13). Solo nel detail
            // (--with-detail), non nel summary. A volte ripete la stessa
            // anagrafica dell'intestatario (stesso id_eureka): equivale a
            // "nessun pagante diverso", va trattata come assenza.
            $destinazioneCode = (int) ($detail['destinazione']['id_eureka'] ?? 0);
            $intestatarioCode = (int) ($detail['id_intestatario'] ?? $summary['id_codice_f15'] ?? 0);
            $hasDestinazione = $destinazioneCode > 0 && $destinazioneCode !== $intestatarioCode;

            $payload = [
                'tenant_id' => $tenant->id,
                'source' => ServiceReport::SOURCE_EUREKA,
                'eureka_service_report_id' => $eurekaId,
                'eureka_destinazione_code' => $hasDestinazione ? $destinazioneCode : null,
                'eureka_destinazione_label' => $hasDestinazione
                    ? $this->normalizeText($detail['destinazione']['rag_sociale'] ?? null)
                    : null,
                'customer_id' => $localCustomerId,
                'machine_product_id' => $machineProduct?->id,
                'machine_material_id' => $machineMaterial?->id,
                'machine_unit_id' => $machineUnit?->id,
                'machine_serial_number' => $machineSerial,
                'technician_id' => $technician?->id,
                'intervention_type' => $detail
                    ? $this->mapInterventionType($detail)
                    : ServiceReport::TYPE_RIPARAZIONE,
                'intervention_date' => $interventionDate,
                'gestionale_document_date' => $documentDate,
                'problem_description' => $detail
                    ? $this->normalizeText($detail['sl_sintomo'] ?? null)
                    : null,
                'work_performed' => $detail
                    ? $this->normalizeText($detail['sl_lavorazione'] ?? null)
                    : null,
                'status' => $this->mapStatus($detail['stato_documento'] ?? $summary['stato_documento'] ?? null),
                'eureka_stato_documento' => $this->normalizeStatoDocumento($detail['stato_documento'] ?? $summary['stato_documento'] ?? null),
                'eureka_stato_label' => $this->statoDocumentoLabel($detail['stato_documento'] ?? $summary['stato_documento'] ?? null),
                'notes' => $detail ? $this->buildNotes($detail) : null,
            ];

            $existing = $existingReportsMap->get($eurekaId);

            if ($existing) {
                if ($existing->source !== ServiceReport::SOURCE_EUREKA) {
                    // Rapportino nato nel CRM (source=manuale) e poi
                    // agganciato a Eureka da un invio
                    // (SendServiceReportToGestionaleJob) — il match qui e'
                    // per eureka_service_report_id, non per "number" (che
                    // resta il numero RT-... stabile del CRM, mai riscritto
                    // da un invio: il numero gestionale finisce in
                    // gestionale_number).
                    // toGestionalePayload() non manda ne' intervention_date
                    // ne' il tecnico a Eureka: il "suo" record riflette solo
                    // la data di creazione lato loro e nessun tecnico, quindi
                    // un sync qui sovrascriverebbe dati corretti del CRM con
                    // i fallback dell'import. Successo davvero da un invio di
                    // test: ha riportato tecnico, data, stato e descrizioni
                    // di RT-2026-0002..0014 ai valori di fallback invece di
                    // lasciare quelli reali del CRM.
                    // Il CRM resta la fonte autorevole per questi rapportini,
                    // Eureka e' solo la destinazione dell'invio.
                    $unchanged++;

                    continue;
                }

                $existing->fill($payload);

                if (! $existing->isDirty()) {
                    $unchanged++;

                    continue;
                }

                $this->line("  <info>UPDATE</info> rapportino #{$eurekaId} ({$existing->number})");
                $updated++;

                if (! $dryRun) {
                    // L'elenco dei rapportini gia' importati si carica una
                    // volta sola all'inizio, e con --with-detail il ciclo dura
                    // parecchi minuti: nel frattempo qualcuno puo' eliminare
                    // dal pannello proprio uno di quelli in coda. Allora
                    // save() fa un UPDATE che tocca zero righe e passa senza
                    // protestare, e a esplodere e' l'inserimento delle righe
                    // materiale, che non trova piu' il padre (successo in
                    // produzione il 03/09/2026 su RT-2026-0676).
                    //
                    // Un rapportino sparito non deve fermare gli altri
                    // seicento: si salta e si annota.
                    if (! ServiceReport::withTrashed()->whereKey($existing->getKey())->exists()) {
                        $this->warn("  eliminato nel frattempo, saltato: #{$eurekaId}");
                        RegistroSync::problema('import-rapportini', 'rapportino eliminato durante l\'import', [
                            'scheda_eureka' => $eurekaId,
                            'numero' => $existing->number,
                        ]);
                        $updated--;
                        $skipped++;

                        continue;
                    }

                    DB::transaction(function () use ($tenant, $existing, $detail, &$materialCache): void {
                        $existing->save();
                        if ($detail) {
                            $this->syncDetailRows($tenant, $existing, $detail, $materialCache);
                        }
                    });
                }
            } else {
                $gestionaleNumber = $this->resolveGestionaleNumber($tenant, $detail ?? $summary, $interventionDate);
                $this->line("  <info>CREATE</info> rapportino #{$eurekaId} → {$gestionaleNumber}");
                $created++;

                if (! $dryRun) {
                    RegistroSync::movimento('import-rapportini', 'rapportino creato', [
                        'scheda_eureka' => $eurekaId,
                        'numero_gestionale' => $gestionaleNumber,
                        'data_intervento' => $interventionDate,
                        'cliente' => $payload['customer_id'] ?? null,
                    ]);
                }

                if (! $dryRun) {
                    DB::transaction(function () use ($tenant, $payload, $detail, $gestionaleNumber, $interventionDate, &$materialCache): void {
                        $report = new ServiceReport($payload);
                        // Anche uno storico ripescato da Eureka ha ormai un
                        // numero interno CRM (RT-...): assegnato qui esplicitamente
                        // sull'anno dell'intervento (non quello odierno, che
                        // userebbe di default ServiceReport::booted() lasciando
                        // "number" vuoto) — created_at riflette solo quando la
                        // riga e' stata importata, non l'anno del documento
                        // originale, che per uno storico puo' essere anni fa.
                        // Il numero Eureka finisce in gestionale_number (vedi la
                        // stessa scelta in
                        // SendServiceReportToGestionaleJob::resolveGestionaleNumber()).
                        $report->number = ServiceReport::nextNumberForTenant($tenant->id, (int) substr($interventionDate, 0, 4));
                        $report->gestionale_number = $gestionaleNumber;
                        $report->save();
                        if ($detail) {
                            $this->syncDetailRows($tenant, $report, $detail, $materialCache);
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

        if (! $dryRun) {
            RegistroSync::esito('import-rapportini', [
                'tenant' => $tenant->slug,
                'creati' => $created,
                'aggiornati' => $updated,
                'invariati' => $unchanged,
                'saltati' => $skipped,
            ]);
        }

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
            // Niente filtro tenant_id qui: alcuni utenti (es. Alessandro
            // Signorato) hanno users.tenant_id vuoto pur operando su piu'
            // tenant (l'appartenenza vera passa dal pivot model_has_roles,
            // che per lui e' anch'esso vuoto — verificato dal vivo). Un
            // --technician esplicito e' un identificativo scelto a mano
            // dall'utente umano, non va scartato per un campo che in
            // pratica non riflette l'appartenenza reale.
            $user = User::query()
                ->where(fn ($q) => $q->where('email', $option)->orWhere('id', $option))
                ->first();

            if (! $user) {
                $this->warn("Tecnico '{$option}' non trovato — uso il fallback preferito.");
            } else {
                return $user;
            }
        }

        // Stesso motivo di sopra: niente filtro tenant_id sulla ricerca per
        // nome/email, altrimenti Alessandro Signorato (il tecnico giusto per
        // lo storico, confermato dalla proprietaria) non risulterebbe mai
        // trovato e si cadrebbe sul fallback "primo utente creato" — che ha
        // gia' assegnato erroneamente i rapportini a un'altra persona.
        $preferred = User::query()
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
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $detail
     * @param  array<int|string, string>  $customerMap
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

            // resolveOrCreateCustomer() gia' controlla l'esistenza prima di
            // creare — niente bisogno di una query di lookup separata qui
            // solo per poi ripeterla dentro quel metodo.
            $resolved = $this->resolveOrCreateCustomer($tenant, $code, $row, $detail, $dryRun);
            if ($resolved) {
                $customerMap[$code] = $resolved->id;

                return $resolved->id;
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

        // Geocodifica in coda: era una usleep(1.1s) sincrona per ogni cliente
        // nuovo (rate-limit di Nominatim, vedi GeocodeCustomerJob), che
        // sommata su un import che crea molti clienti aggiungeva minuti
        // interi al comando. Il worker la processa fuori da questo ciclo.
        GeocodeCustomerJob::dispatch($customer);

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $detail
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
     * Cerca a catalogo il Product che corrisponde a sl_articolo, senza mai
     * crearlo: le macchine sconosciute nascono in Materiali
     * (resolveOrCreateMaterial), non nel catalogo preventivi — vedi il doc
     * comment della classe. Il match resta utile per le macchine che vendiamo
     * davvero (Franke, Dalla Corte, Bianchi): quelle sono a listino, e un
     * rapportino su una di esse deve agganciarsi a quel Product invece di
     * ignorarlo.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, Product|null>  $productCache
     */
    private function resolveExistingProduct(Tenant $tenant, array $data, array &$productCache, bool $dryRun = false): ?Product
    {
        $articleId = (int) ($data['id_eureka'] ?? 0);
        $code = $this->normalizeText($data['codice'] ?? null);
        $name = $this->normalizeText($data['descr1'] ?? $data['descrizione'] ?? null) ?: 'Prodotto Eureka';
        $description = $this->normalizeText($data['descrizione'] ?? $data['descr1'] ?? null);

        if ($articleId <= 0 && ! $code) {
            return null;
        }

        // Stessa macchina spesso vista su piu' rapportini nello stesso
        // import: senza cache, ogni riga ri-interroga il DB per un prodotto
        // gia' risolto poco prima nello stesso ciclo. array_key_exists e non
        // isset: ora la maggior parte degli articoli NON e' a catalogo, e un
        // null va ricordato come una qualsiasi altra risposta.
        $cacheKey = $articleId.'|'.($code ?? '');
        if (array_key_exists($cacheKey, $productCache)) {
            return $productCache[$cacheKey];
        }

        // Il catalogo commerciale e' quasi tutto condiviso (tenant_id NULL,
        // vedi SharedAcrossTenants): filtrare sul solo tenant non avrebbe mai
        // trovato una macchina a listino.
        $existing = Product::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id'))
            ->when($articleId > 0, fn ($q) => $q->where('eureka_article_id', $articleId))
            ->when($code !== null, fn ($q) => $q->where('sku', $code))
            ->first();

        if ($existing) {
            return $productCache[$cacheKey] = $this->backfillProductEurekaCode($existing, $articleId, $name, $description, $dryRun);
        }

        $existingBySku = $code
            ? Product::query()->where('sku', $code)->first()
            : null;

        if ($existingBySku) {
            return $productCache[$cacheKey] = $this->backfillProductEurekaCode($existingBySku, $articleId, $name, $description, $dryRun);
        }

        return $productCache[$cacheKey] = null;
    }

    /**
     * I prodotti gia' presenti a catalogo (es. dal dump di produzione, matchati
     * per SKU) non hanno mai eureka_article_id/gestionale_code: senza questo
     * backfill restano orfani del codice Eureka anche dopo essere stati
     * agganciati a un rapportino importato, bloccando per sempre l'invio a
     * gestionale di quel rapportino (vedi ServiceReport::gestionaleValidationErrors()).
     *
     * Aggiorna anche il nome se era rimasto al segnaposto "Prodotto Eureka":
     * succede quando il PRIMO rapportino che ha creato questo prodotto veniva
     * da un summary senza dettaglio (niente sl_articolo.descr1) — un run
     * successivo con --with-detail su un altro rapportino dello stesso
     * articolo porta il nome vero, ma senza questo aggiornamento il prodotto
     * (gia' creato) restava genericamente etichettato per sempre, anche
     * quando un dettaglio migliore era disponibile nel frattempo. Verificato
     * dal vivo su articolo Eureka 190 ("IMPIANTO ALLA SPINA 2 VIE" /
     * "SPINA 2 VIE"): presente su Eureka con nome reale, ma creato qui come
     * "Prodotto Eureka" perche' il primo summary incontrato non aveva
     * descr_articolo_1.
     */
    private function backfillProductEurekaCode(Product $product, int $articleId, ?string $name, ?string $description, bool $dryRun): Product
    {
        $dirty = [];

        if ($articleId > 0) {
            if (blank($product->eureka_article_id)) {
                $dirty['eureka_article_id'] = $articleId;
            }
            if (blank($product->gestionale_code)) {
                $dirty['gestionale_code'] = $articleId;
            }
        }

        if ($product->name === 'Prodotto Eureka' && $name && $name !== 'Prodotto Eureka') {
            $dirty['name'] = $name;

            if (blank($product->description) && $description) {
                $dirty['description'] = $description;
            }
        }

        if (empty($dirty)) {
            return $product;
        }

        if ($dryRun) {
            $this->line("  <comment>[DRY RUN] Prodotto \"{$product->name}\" riceverebbe: ".implode(', ', array_keys($dirty)).'</comment>');

            return $product;
        }

        $product->update($dirty);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, Material>  $materialCache
     */
    private function syncDetailRows(Tenant $tenant, ServiceReport $report, array $detail, array &$materialCache): void
    {
        $report->materialsUsed()->delete();
        $createdMaterialIds = [];

        foreach (($detail['dettaglio'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $material = $this->resolveOrCreateMaterial($tenant, [
                'id_eureka' => $row['id_articolo'] ?? null,
                'codice' => $row['codice'] ?? null,
                'descr1' => $row['descrizione'] ?? null,
                'descrizione' => $row['descrizione'] ?? null,
            ], $materialCache);

            if (! $material) {
                continue;
            }

            $createdMaterialIds[] = $material->id;

            $quantita = max(0.0, (float) ($row['quantita'] ?? 1));

            // Eureka manda spesso prezzo=0 sulle righe di intervento (CHIORD
            // e le installazioni: da sole 3.200 righe sullo storico), e
            // prenderlo alla lettera lasciava senza valore proprio le voci
            // fatturabili. Quando la riga non porta un prezzo si usa il
            // listino dell'articolo, che l'import del catalogo tiene
            // aggiornato — meglio il prezzo corrente del nulla.
            $prezzo = (float) ($row['prezzo'] ?? 0) ?: null;
            $prezzo ??= ((float) ($material->list_price ?? 0) ?: null);

            // "importo" = prezzo_netto * quantita' (sconti di riga gia'
            // applicati, IVA esclusa): il valore economico reale della riga,
            // che unit_cost_snapshot da solo non da' (e' il prezzo unitario
            // lordo). Se manca anche quello si ricostruisce dal prezzo, che
            // e' comunque meglio di una riga senza importo.
            $importo = (float) ($row['importo'] ?? 0) ?: null;
            $importo ??= ($prezzo === null ? null : round($prezzo * $quantita, 2));

            ServiceReportMaterial::create([
                'service_report_id' => $report->id,
                'material_id' => $material->id,
                'quantity' => $quantita,
                'unit_cost_snapshot' => $prezzo,
                'line_total_snapshot' => $importo,
                'notes' => $this->normalizeText($row['descrizione'] ?? null),
            ]);
        }

        $this->syncArticleMentionsFromNotes($tenant, $report, $detail, $createdMaterialIds, $materialCache);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, Material>  $materialCache
     */
    private function syncArticleMentionsFromNotes(Tenant $tenant, ServiceReport $report, array $detail, array $createdMaterialIds, array &$materialCache): void
    {
        $note = $detail['note'] ?? null;

        // Non si puo' passare da normalizeText() qui: collassa TUTTI gli
        // spazi bianchi (incluse le newline, vedi normalizeText()) in un
        // singolo spazio, il che fonde tutte le righe "Aggiunto articolo: ..."
        // (una per articolo, separate da \r/\n nel testo grezzo di Eureka) in
        // un unico blob. Quel blob combacia comunque con isArticleMention()
        // (il match non e' ancorato all'inizio riga), quindi l'INTERO blob —
        // mention multiple e qualsiasi testo libero frammisto — veniva
        // classificato come "riga di mention" e scartato in blocco, invece di
        // isolare solo le singole mention. Qui si preservano le newline reali
        // (comprese quelle sole \r usate da Eureka a meta' stringa) e si
        // collassano solo gli spazi orizzontali dentro ciascuna riga.
        $text = $this->stripRtf($note !== null ? (string) $note : null);
        if ($text === null || $text === '') {
            return;
        }

        $remainingLines = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
            if ($line === '') {
                continue;
            }

            if ($this->isArticleMention($line)) {
                $mention = $this->extractArticleMention($line);
                if ($mention === null) {
                    continue;
                }

                $material = $this->resolveOrCreateMaterial($tenant, [
                    'id_eureka' => null,
                    'codice' => $mention['code'],
                    'descr1' => $mention['code'],
                    'descrizione' => $mention['code'],
                ], $materialCache);

                if (! $material || in_array($material->id, $createdMaterialIds, true)) {
                    continue;
                }

                $createdMaterialIds[] = $material->id;

                ServiceReportMaterial::create([
                    'service_report_id' => $report->id,
                    'material_id' => $material->id,
                    'quantity' => max(0.0, (float) $mention['quantity']),
                    'notes' => $line,
                ]);

                continue;
            }

            $remainingLines[] = $line;
        }

        // Ricostruisce le note come buildNotes() (testo pulito + eventuale
        // "Numero documento Eureka: X"), non solo $remainingLines: prima
        // sovrascriveva $report->notes con le sole righe residue, perdendo
        // la riga del numero documento (aggiunta da buildNotes() ma non
        // nota qui). E salva sempre quando il risultato cambia, non solo
        // quando restano righe: con il bug sopra $remainingLines finiva
        // quasi sempre vuoto (l'intera nota era un unico blob di mention),
        // la condizione "!== []" restava falsa e il salvataggio non
        // scattava mai, lasciando le note sporche originali in DB.
        $numero = (int) ($detail['numero'] ?? 0);
        $parts = array_filter([
            $remainingLines !== [] ? implode("\n", $remainingLines) : null,
            $numero > 0 ? "Numero documento Eureka: {$numero}" : null,
        ]);
        $newNotes = $parts ? implode("\n\n", $parts) : null;

        if ($newNotes !== $report->notes) {
            $report->notes = $newNotes;
            $report->save();
        }
    }

    private function isArticleMention(string $line): bool
    {
        return $this->extractArticleMention($line) !== null;
    }

    /**
     * La riga che Eureka scrive nelle note quando l'ufficio aggiunge un
     * articolo: "18/08/26 12:12 EUREKA - Aggiunto articolo: 1,5x ORE".
     *
     * La quantita' puo' avere la virgola decimale, e senza ammetterla il
     * gruppo non combaciava: la regex ripiegava sul primo pezzo utile e
     * prendeva "1" come CODICE articolo, creando materiali fantasma
     * chiamati "1", "5", "1x" (trovati dal vivo il 02/09/2026).
     *
     * Il codice deve iniziare con una lettera o una cifra: "Aggiunto
     * articolo: 1x ." non deve produrre nulla.
     */
    private function articleMentionPattern(): string
    {
        return '/\b(?:aggiunto|aggiunta)\s+articolo\s*:\s*(?:(?<quantity>\d+(?:[.,]\d+)?)\s*(?:x|×)\s*)?(?<code>[A-Za-z0-9][A-Za-z0-9._\/-]*)\b/i';
    }

    /**
     * @return array{code: string, quantity: float}|null
     */
    private function extractArticleMention(string $line): ?array
    {
        if (! preg_match($this->articleMentionPattern(), $line, $matches)) {
            return null;
        }

        // "1x ." non ha un codice: la regex ripiega su "1x", che e' il
        // moltiplicatore, non un articolo.
        if (preg_match('/^\d+(?:[.,]\d+)?x$/i', $matches['code'])) {
            return null;
        }

        $quantity = isset($matches['quantity']) && $matches['quantity'] !== ''
            ? (float) str_replace(',', '.', $matches['quantity'])
            : 1.0;
        $code = $this->normalizeText($matches['code']);
        if ($code === null || $code === '') {
            return null;
        }

        return [
            'code' => $code,
            'quantity' => $quantity,
        ];
    }

    /**
     * L'anagrafica articoli di Eureka: sia i ricambi del dettaglio[] sia il
     * bene principale di sl_articolo passano di qui. Nessuno dei due va nel
     * catalogo preventivi (Product), dove finirebbe nel selettore mescolato
     * al listino ufficiale — vedi resolveExistingProduct(), che a catalogo
     * cerca soltanto.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, Material>  $materialCache
     */
    private function resolveOrCreateMaterial(Tenant $tenant, array $data, array &$materialCache, bool $dryRun = false): ?Material
    {
        $articleId = (int) ($data['id_eureka'] ?? 0);
        $code = $this->normalizeText($data['codice'] ?? null);
        $type = $this->normalizeText($data['descr1'] ?? $data['descrizione'] ?? null) ?: 'Materiale Eureka';

        if ($articleId <= 0 && ! $code) {
            return null;
        }

        // Stesso ricambio spesso ripetuto su piu' rapportini/righe nello
        // stesso import: vedi la stessa cache su resolveExistingProduct().
        $cacheKey = $articleId.'|'.($code ?? '');
        if (isset($materialCache[$cacheKey])) {
            return $materialCache[$cacheKey];
        }

        // L'or va raggruppato: senza le parentesi la condizione diventava
        // "(tenant AND gestionale_code) OR code", cioe' un codice uguale
        // faceva match anche su un materiale di un altro tenant.
        $existing = Material::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q
                ->when($articleId > 0, fn ($q) => $q->where('gestionale_code', $articleId))
                ->when($code !== null, fn ($q) => $q->orWhere('code', $code)))
            ->first();

        if ($existing) {
            return $materialCache[$cacheKey] = $existing;
        }

        $materialCode = $code ?: 'eureka-'.$articleId;
        $existingByCode = Material::query()->where('code', $materialCode)->first();
        if ($existingByCode) {
            return $materialCache[$cacheKey] = $existingByCode;
        }

        if ($dryRun) {
            $this->line("  <comment>[DRY RUN] Materiale NON creato: {$type} (codice {$materialCode})</comment>");

            return null;
        }

        return $materialCache[$cacheKey] = Material::create([
            'tenant_id' => $tenant->id,
            'source' => Material::SOURCE_EUREKA,
            'code' => $materialCode,
            'gestionale_code' => $articleId > 0 ? $articleId : null,
            'category' => 'Eureka',
            'type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $detail
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
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $detail
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
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $detail
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
     * @param  array<string, mixed>  $detail
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
        // Una scheda che arriva da qui VIVE in Eureka: e' li' che va corretta,
        // e "in_gestionale" e' lo stato che lo dice (lo stesso che assegna
        // SendServiceReportToGestionaleJob dopo un invio riuscito).
        //
        // Prima si mappava stato_documento=10 ("Archiviato") su "inviato": era
        // sbagliato, perche' nel CRM "inviato" significa spedito via mail AL
        // CLIENTE dall'azione di invio — cosa mai avvenuta per queste schede.
        // Lo stato grezzo di Eureka resta comunque salvato in
        // eureka_stato_documento/eureka_stato_label per chi deve distinguere
        // archiviato da ricevuto.
        return 'in_gestionale';
    }

    /**
     * "Stato mobile" Eureka, scala fissa 0-10 confermata dal vendor via email
     * 2026-08-24 (indipendente dai codici stato per-tenant visti in Eureka,
     * es. "C"/"EP"). Salvato cosi' com'e' — non usato ancora da mapStatus(),
     * che resta volutamente binario: e' materiale grezzo per quando servira'
     * una logica di invio al gestionale piu' fine (non ancora progettata).
     */
    private function normalizeStatoDocumento(mixed $statoDocumento): ?int
    {
        return $statoDocumento === null ? null : (int) $statoDocumento;
    }

    /**
     * @see self::normalizeStatoDocumento()
     */
    private function statoDocumentoLabel(mixed $statoDocumento): ?string
    {
        if ($statoDocumento === null) {
            return null;
        }

        // Mappatura confermata dal vendor via email 2026-08-24, non dedotta:
        // valori diversi da questi (es. 0, 4, 5, 8, 9) non sono mai stati
        // confermati e restano senza etichetta piuttosto che indovinati.
        return match ((int) $statoDocumento) {
            1 => 'Nuova',
            2 => 'Inviata ai tablet',
            3 => 'Nessuno stato assegnato',
            6 => 'Evaso parzialmente',
            7 => 'Finita/Conclusa',
            10 => 'Chiuso',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
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
     * Il "numero" di Eureka NON e' univoco nel tempo (si ripete su anni diversi,
     * es. il documento 601 esiste sia nel 2023 che nel 2024/2025/2026 per clienti
     * diversi — verificato dal vivo): un contatore -2/-3/-4 appiccicato in ordine
     * di importazione sarebbe arbitrario e fuorviante (sembra una revisione, non
     * lo e'). "numero/anno" replica invece la convenzione italiana standard
     * numero/anno (come una fattura) ed e' verificato univoco su tutto lo
     * storico importato finora (0 collisioni su 3606 rapportini). Il controllo
     * di unicita' e' su gestionale_number (non piu' "number", che per un
     * rapportino ripescato da Eureka e' ormai il numero interno CRM
     * RT-..., assegnato da ServiceReport::booted()).
     *
     * @param  array<string, mixed>  $detail
     */
    private function resolveGestionaleNumber(Tenant $tenant, array $detail, string $interventionDate): string
    {
        // Il detail usa 'numero', il summary usa 'numero_doc_t23'
        $numero = (int) ($detail['numero'] ?? $detail['numero_doc_t23'] ?? 0);
        $year = substr($interventionDate, 0, 4);
        $base = $numero > 0 ? "SL-{$numero}/{$year}" : 'SL-EK-'.(int) ($detail['id_eureka'] ?? 0);

        $candidate = $base;
        $i = 2;
        while (ServiceReport::query()->where('tenant_id', $tenant->id)->where('gestionale_number', $candidate)->exists()) {
            $candidate = $base.'-'.$i++;
        }

        return $candidate;
    }

    private function stripRtf(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Il NUL arriva dalla conversione RTF -> testo semplice fatta
        // dall'API: nel JSON e' escapato come \u0000, quindi il payload e'
        // valido, ma il decoder lo trasforma in un carattere vero che
        // finirebbe dritto in colonna. Verificato 2026-09-01: ogni scheda
        // lavoro ne contiene da 3 a 7 nel campo note, anche in mezzo al
        // testo e non solo in coda. Va tolto qui e non in normalizeText()
        // perche' questo e' l'unico punto attraversato da entrambi i
        // percorsi (buildNotes e syncArticleMentionsFromNotes), e perche'
        // \s non intercetta il NUL: il collasso degli spazi non lo toglie.
        //
        // I rapportini gia' in archivio sono puliti solo perche' importati
        // prima che l'API esponesse questo campo cosi'.
        $value = str_replace("\x00", '', $value);

        if ($value === '') {
            return null;
        }

        if (! str_starts_with($value, '{\\rtf')) {
            return $value;
        }

        // Rimuove gli ultimi caratteri di chiusura RTF
        $value = (string) preg_replace('/\}\s*$/', '', $value);

        // Sostituisce \par (paragrafi) con newlines
        $value = (string) preg_replace('/\\\\par\b/', "\n", $value);

        // Rimuove i tag RTF (es. \f0, \fs22, \ansi, ecc.)
        $value = (string) preg_replace('/\\\\[a-z]+\d*\s?/', '', $value);

        // Rimuove i gruppi di comandi (es. {\*\generator...})
        $value = (string) preg_replace('/\{[^}]*\}/', '', $value);

        // Rimuove le parentesi graffe rimanenti
        $value = (string) preg_replace('/[{}]/', '', $value);

        return $value;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Converte RTF a testo semplice se necessario
        $text = $this->stripRtf((string) $value);

        if ($text === null || $text === '') {
            return null;
        }

        // Rimuove \r (inserito da Eureka a metà stringa, cf. spec §6.1) e collassa spazi
        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\r", ' ', $text)));

        return $text !== '' ? $text : null;
    }
}
