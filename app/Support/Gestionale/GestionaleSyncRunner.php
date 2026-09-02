<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Confronta clienti/prodotti gia' collegati a Eureka con l'anagrafica reale
 * (autocompila i campi vuoti nel CRM, segnala le differenze senza mai
 * sovrascrivere un valore gia' presente) e propone nuovi collegamenti per
 * chi non ne ha ancora uno — mai un'assegnazione automatica, solo proposte
 * da confermare a mano (vedi CustomerResource/ProductResource, azione
 * "Conferma collegamento"). Nessuna scrittura viene mai fatta verso Eureka:
 * tutto qui e' lettura da Eureka + eventuale scrittura nel CRM.
 *
 * Limite noto: i prodotti gia' collegati non vengono ri-verificati (solo
 * proposti quando mancano) perche' salviamo solo l'id_eureka numerico, non
 * il "codice" testuale che servirebbe per un lookup esatto — vedi
 * app/Support/Gestionale/EurekaClient::cercaArticoli() e la nota nel piano.
 */
class GestionaleSyncRunner
{
    private EurekaClient $client;

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $installedByCustomer = null;

    /** @var Collection<int, Customer> */
    private $linkedCustomers;

    public function __construct(private readonly Tenant $tenant)
    {
        $this->client = new EurekaClient($tenant);
    }

    /**
     * @return array{autofilled: array, diffs: array, customerLinks: array, productLinks: array, machineUnitLinks: array, newMachines: array, eurekaNotes: array, doppioniRapportini: array, eurekaUnreachable: bool, apiIssues: array}
     */
    public function run(): array
    {
        RegistroSync::avvio('sync-anagrafiche', ['tenant' => $this->tenant->slug]);

        [$autofilled, $diffs] = $this->reverifyLinkedCustomers();

        $esito = [
            'autofilled' => $autofilled,
            'diffs' => $diffs,
            'customerLinks' => $this->proposeCustomerLinks(),
            'productLinks' => $this->proposeProductLinks(),
            'machineUnitLinks' => $this->proposeMachineUnitLinks(),
            'newMachines' => $this->importInstalledMachines(),
            'fusioniMacchine' => $this->proponiFusioniMacchine(),
            'eurekaNotes' => $this->syncEurekaNotes(),
            'doppioniRapportini' => $this->proponiDoppioniRapportini(),
            // Un'interruzione di Eureka per tutta la durata della sync
            // produce comunque array vuoti da ogni metodo sopra (best-effort
            // by design) — indistinguibile da "niente da segnalare" senza
            // questo controllo separato. Vedi EurekaClient::hadCompleteFailure().
            'eurekaUnreachable' => $this->client->hadCompleteFailure(),
            // Eureka in piedi ma un singolo endpoint che risponde sempre
            // male (tipicamente 403 per diritti di modulo revocati lato
            // fornitore): hadCompleteFailure() sopra non scatta, perche' il
            // resto risponde, e la funzionalita' che dipende da
            // quell'endpoint smette di produrre risultati senza dirlo.
            // Vedi EurekaClient::failedEndpoints().
            'apiIssues' => $this->client->failedEndpoints(),
        ];

        RegistroSync::esito('sync-anagrafiche', [
            'tenant' => $this->tenant->slug,
            'campi_autocompilati' => count($esito['autofilled']),
            'differenze_da_rivedere' => count($esito['diffs']),
            'collegamenti_proposti' => count($esito['customerLinks']) + count($esito['productLinks']) + count($esito['machineUnitLinks']),
            'macchine_create' => count($esito['newMachines']),
            'fusioni_macchine_proposte' => count($esito['fusioniMacchine']),
            'note_aggiornate' => count($esito['eurekaNotes']),
            'doppioni_proposti' => count($esito['doppioniRapportini']),
            'endpoint_in_errore' => $esito['apiIssues'],
        ]);

        return $esito;
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function reverifyLinkedCustomers(): array
    {
        $autofilled = [];
        $diffs = [];

        $customers = Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->get();

        // Una ricerca anagrafica per cliente, tutte in gruppi concorrenti
        // invece che una alla volta (vedi EurekaClient::pooledGet) — con
        // ~2000 clienti era il vero collo di bottiglia del sync.
        $searchParams = $customers->mapWithKeys(fn (Customer $customer) => [
            $customer->id => filled($customer->vat_number)
                ? ['piva' => $customer->vat_number]
                : ['nome' => $customer->company_name ?: (string) $customer->full_name, 'like' => 'true'],
        ])->all();

        $searchResults = $this->client->pooledGet('/anagrafica/cerca', $searchParams);

        foreach ($customers as $customer) {
            $remote = $this->matchEurekaCustomer($searchResults[$customer->id] ?? [], $customer);

            if ($remote === null) {
                continue;
            }

            $filledLabels = [];
            $diffLabels = [];
            $updates = [];

            $newEmails = $this->mergeEurekaEmails($customer, $this->normalizedRemoteValue($remote['email'] ?? null));
            if ($newEmails !== []) {
                $filledLabels[] = 'Email aggiunte: '.implode(', ', $newEmails);
            }

            $newPhones = $this->mergeEurekaPhones($customer, $this->normalizedRemoteValue($remote['nr_telefono'] ?? null));
            if ($newPhones !== []) {
                $filledLabels[] = 'Telefoni aggiunti: '.implode(', ', $newPhones);
            }

            foreach ($this->customerFieldMap() as $localField => [$remoteKey, $label]) {
                $remoteValue = $this->normalizedRemoteValue($remote[$remoteKey] ?? null);

                if ($remoteValue === null) {
                    continue;
                }

                if ($this->looksLikePlaceholder($localField, $remoteValue, $remote)) {
                    continue;
                }

                $localValue = $this->normalizedLocalValue($customer, $localField);

                if ($localValue === null) {
                    $updates[$localField] = $remoteValue;
                    $filledLabels[] = "{$label}: {$remoteValue}";
                } elseif (mb_strtolower($localValue) !== mb_strtolower($remoteValue)) {
                    if (in_array($localField, $this->fieldsAutoAdoptedFromEureka(), true)) {
                        $updates[$localField] = $remoteValue;
                        $filledLabels[] = "{$label} aggiornata: \"{$localValue}\" → \"{$remoteValue}\"";
                    } elseif (! in_array($localField, $this->fieldsExcludedFromDiff(), true)) {
                        $diffLabels[] = "{$label} (CRM: \"{$localValue}\", Eureka: \"{$remoteValue}\")";
                    }
                }
            }

            if ($updates !== []) {
                $customer->update($updates);
            }

            if ($filledLabels === [] && $diffLabels === []) {
                continue;
            }

            $note = trim(implode(' — ', array_filter([
                $filledLabels !== [] ? 'Compilati automaticamente: '.implode(', ', $filledLabels) : null,
                $diffLabels !== [] ? 'Da rivedere: '.implode(', ', $diffLabels) : null,
            ])));

            $customer->flagGestionaleReview([$note]);

            if ($filledLabels !== []) {
                $autofilled[] = ['customer' => $customer, 'fields' => $filledLabels];
            }

            if ($diffLabels !== []) {
                $diffs[] = ['customer' => $customer, 'fields' => $diffLabels];
            }
        }

        return [$autofilled, $diffs];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates  risultati gia' scaricati (vedi pooledGet in reverifyLinkedCustomers/proposeCustomerLinks)
     */
    private function matchEurekaCustomer(array $candidates, Customer $customer): ?array
    {
        foreach ($candidates as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === (int) $customer->gestionale_code) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{customer: Customer, label: string, id: int}>
     */
    private function proposeCustomerLinks(): array
    {
        $proposals = [];

        // Codici Eureka gia' assegnati a un cliente di questo tenant: senza
        // questo controllo due clienti CRM diversi (spesso doppioni dello
        // stesso cliente reale, es. sede vs referente) possono ricevere lo
        // stesso suggerimento, e confermarlo va a sbattere sul vincolo unique
        // su gestionale_code (UniqueConstraintViolationException). withTrashed()
        // perche' il vincolo unique e' fisico sul DB e resta occupato anche
        // da un cliente soft-eliminato.
        $usedCodes = Customer::withTrashed()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->pluck('gestionale_code')
            ->all();

        $candidates = Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('gestionale_code')
            ->whereNull('gestionale_suggested_code')
            ->get()
            ->filter(fn (Customer $customer) => filled($customer->vat_number) || filled($customer->company_name));

        // Una ricerca anagrafica per cliente, tutte in gruppi concorrenti
        // invece che una alla volta — stesso principio di
        // reverifyLinkedCustomers()/importInstalledMachines(): su liste
        // lunghe di clienti senza collegamento era altrettanto il vero
        // collo di bottiglia.
        $searchParams = $candidates->mapWithKeys(fn (Customer $customer) => [
            $customer->id => filled($customer->vat_number)
                ? ['piva' => $customer->vat_number]
                : ['nome' => $customer->company_name, 'like' => 'true'],
        ])->all();

        $searchResults = $this->client->pooledGet('/anagrafica/cerca', $searchParams);

        foreach ($candidates as $customer) {
            $results = $searchResults[$customer->id] ?? [];

            // La ricerca per P.IVA e' gia' un match preciso lato Eureka; la
            // ricerca per nome e' un "contains" (cercaClienti()), va filtrata
            // per corrispondenza esatta come faceva la chiamata singola.
            $matches = filled($customer->vat_number)
                ? $results
                : array_values(array_filter(
                    $results,
                    fn (array $c) => mb_strtolower(trim($c['rag_sociale_1'] ?? '')) === mb_strtolower(trim($customer->company_name)),
                ));

            $matches = array_values(array_filter(
                $matches,
                fn (array $c) => ! in_array((int) ($c['id'] ?? 0), $usedCodes, true),
            ));

            if (count($matches) !== 1) {
                continue;
            }

            $match = $matches[0];
            $label = $match['rag_sociale_1'] ?? '?';
            $customer->update(['gestionale_suggested_code' => $match['id'], 'gestionale_suggested_label' => $label]);
            $proposals[] = ['customer' => $customer, 'label' => $label, 'id' => $match['id']];
        }

        return $proposals;
    }

    /**
     * @return array<int, array{product: Product, label: string, id: int}>
     */
    private function proposeProductLinks(): array
    {
        $proposals = [];

        $candidates = Product::query()
            ->where('type', Product::TYPE_MACHINE)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant->id))
            ->whereNull('gestionale_code')
            ->whereNull('gestionale_suggested_code')
            ->get()
            ->filter(fn (Product $product) => filled($product->sku));

        // Stesso principio di proposeCustomerLinks(): una ricerca articolo
        // per prodotto, tutte in gruppi concorrenti. cercaArticoli() ha il
        // termine di ricerca nel path (non in query string), quindi
        // pooledGetByPath() invece di pooledGet().
        //
        // Cerca per sku (codice), non per nome: cercaArticoli() e'
        // "ricerca per codice" lato Eureka, e prodotti diversi possono
        // condividere la prima parola del nome (es. "A300 NM 1G H1 W3" e
        // "A300 NM 1G 2P H1 W3") — matchare sul nome proponeva lo stesso
        // articolo a varianti diverse dello stesso modello.
        $paths = $candidates->mapWithKeys(fn (Product $product) => [
            $product->id => '/articoli/lista/'.rawurlencode((string) $product->sku),
        ])->all();

        $searchResults = $this->client->pooledGetByPath($paths);

        foreach ($candidates as $product) {
            $matches = $searchResults[$product->id] ?? [];

            if (count($matches) !== 1) {
                continue;
            }

            $match = $matches[0];
            $label = "{$match['codice']} — {$match['descr1']}";
            $product->update(['gestionale_suggested_code' => $match['id_eureka'], 'gestionale_suggested_label' => $label]);
            $proposals[] = ['product' => $product, 'label' => $label, 'id' => $match['id_eureka']];
        }

        return $proposals;
    }

    /**
     * Richiede che il prodotto collegato sia gia' agganciato a Eureka
     * (serve il suo id_eureka come id_articolo_m10 per la ricerca m14) —
     * finche' nessun Product ha gestionale_code, questa fase non produce
     * nulla: e' un limite noto, non un bug (vedi nota nel piano).
     *
     * @return array<int, array{machineUnit: MachineUnit, label: string, id: int}>
     */
    private function proposeMachineUnitLinks(): array
    {
        $proposals = [];

        // Niente piu' filtro su product_id/prodotto collegato: serviva solo
        // perche' la vecchia ricerca su /crm_api/m14/search voleva
        // id_articolo_m10 per restringere il campo. Qui il confronto avviene
        // dentro l'elenco installato presso IL cliente della macchina, che e'
        // gia' un contesto stretto: una matricola che compare li' e' quella
        // macchina, che il prodotto sia collegato o no.
        $candidates = MachineUnit::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('serial_number')
            ->whereNotNull('current_customer_id')
            ->where('source', '!=', MachineUnit::SOURCE_EUREKA)
            ->whereNull('gestionale_code')
            ->whereNull('gestionale_suggested_code')
            ->get();

        $installedByCustomer = $this->installedMachinesByCustomer();

        foreach ($candidates as $machineUnit) {
            $rows = $installedByCustomer[$machineUnit->current_customer_id] ?? [];
            $serial = mb_strtolower(trim((string) $machineUnit->serial_number));

            $matches = array_values(array_filter(
                $rows,
                fn (array $row) => mb_strtolower(trim((string) ($row['matricola'] ?? ''))) === $serial,
            ));

            if (count($matches) !== 1) {
                continue;
            }

            $articleId = (int) ($matches[0]['id'] ?? 0);

            if ($articleId <= 0) {
                continue;
            }

            $label = trim((string) $matches[0]['matricola']);

            // gestionale_suggested_code porta l'id ARTICOLO (M10), non l'id
            // matricola (M14) che portava la proposta da /crm_api/m14/search:
            // art_installati non espone l'id M14 e non abbiamo altro modo di
            // leggerlo finche' il modulo `crm` resta negato (403). Per questo
            // la conferma NON scrive gestionale_code — che la migration
            // definisce "id m14 (matricola) su Eureka" — ma marca la macchina
            // come proveniente da Eureka: e' quel campo, non gestionale_code,
            // a decidere l'invio di sl_matricola nel rapportino
            // (ServiceReport::toGestionalePayload()) e le due letture
            // esistenti trattano gia' i due segnali come equivalenti
            // (MachineUnitResource::table(), ServiceReportResource::isMachineUnitLinkedToEureka()).
            // Vedi MachineUnit::confermaCollegamentoEureka().
            $machineUnit->update([
                'gestionale_suggested_code' => $articleId,
                'gestionale_suggested_label' => $label,
            ]);

            $proposals[] = ['machineUnit' => $machineUnit, 'label' => $label, 'id' => $articleId];
        }

        return $proposals;
    }

    /**
     * Propone l'abbinamento fra i rapportini compilati nel CRM e le schede
     * lavoro importate da Eureka che documentano lo stesso intervento.
     *
     * Non serve nessuna chiamata a Eureka: lavora sui rapportini gia'
     * importati, quindi gira anche quando il gestionale non risponde.
     *
     * Come per i collegamenti di clienti e macchinari, qui si PROPONE
     * soltanto. Unire due rapportini cancella dati firmati dal cliente ed e'
     * irreversibile: la conferma resta a una persona, dalla pagina "Sync
     * Eureka".
     *
     * @return array<int, array{nostro: ServiceReport, importato: ServiceReport, motivo: string}>
     */
    /**
     * Propone la fusione fra macchine che sono lo stesso apparecchio.
     *
     * L'import degli installati ha creato due volte la stessa orzina perche'
     * Eureka la scrive con e senza spazi, e in anagrafica c'erano gia'
     * "A 300 3400000310192" accanto a "3400000310192". Ogni doppione e' un
     * rapportino che non si abbinera' mai.
     *
     * Come per i doppioni dei rapportini: si propone e basta. Fondere due
     * macchine sposta rapportini e storico di manutenzione.
     *
     * @return array<int, array{tenere: MachineUnit, assorbire: MachineUnit, motivo: string}>
     */
    private function proponiFusioniMacchine(): array
    {
        // Le proposte gia' in piedi non si rifanno: una che una persona ha
        // gia' scartato non deve ricomparire ad ogni sync.
        $macchine = MachineUnit::query()
            ->whereNull('fusione_suggerita_id')
            ->get(['id', 'serial_number', 'model_name', 'gestionale_code', 'current_customer_id', 'created_at']);

        $proposte = ConfrontoMacchine::proposte($macchine);

        foreach ($proposte as $proposta) {
            $proposta['assorbire']->update([
                'fusione_suggerita_id' => $proposta['tenere']->id,
                'fusione_suggerita_motivo' => $proposta['motivo'],
            ]);

            RegistroSync::movimento('sync-anagrafiche', 'fusione macchine proposta', [
                'tenere' => $proposta['tenere']->serial_number,
                'assorbire' => $proposta['assorbire']->serial_number,
                'motivo' => $proposta['motivo'],
            ]);
        }

        return $proposte;
    }

    private function proponiDoppioniRapportini(): array
    {
        // Una proposta il cui bersaglio e' stato unito a un ALTRO rapportino
        // non ha piu' niente da decidere, e lasciata li' fa esplodere il
        // confronto. Si chiude prima di proporne di nuove.
        ServiceReport::scartaProposteOrfane();

        $nostri = ServiceReport::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('source', ServiceReport::SOURCE_MANUALE)
            ->whereNull('eureka_service_report_id')
            ->whereNull('duplicato_suggerito_id')
            ->whereNotNull('customer_id')
            ->whereNotNull('intervention_date')
            ->with(['machineUnit', 'materialsUsed'])
            ->get();

        if ($nostri->isEmpty()) {
            return [];
        }

        // Si guardano solo le schede importate nello stesso arco di date dei
        // rapportini da abbinare: caricare tutto l'archivio sarebbe inutile.
        $importati = ServiceReport::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->whereIn('customer_id', $nostri->pluck('customer_id')->unique())
            ->whereBetween('intervention_date', [
                $nostri->min('intervention_date'),
                $nostri->max('intervention_date'),
            ])
            ->with(['machineUnit', 'materialsUsed'])
            ->get()
            ->groupBy('customer_id');

        $proposte = [];

        foreach ($nostri as $nostro) {
            $candidati = [];

            foreach ($importati->get($nostro->customer_id, collect()) as $importato) {
                $motivo = ConfrontoRapportini::confidenza($nostro, $importato);

                if ($motivo !== null) {
                    $candidati[] = [
                        'rapportino' => $importato,
                        'motivo' => $motivo,
                        'peso' => ConfrontoRapportini::peso($motivo),
                        'articoli' => ConfrontoRapportini::quantiArticoliInComune($nostro, $importato),
                    ];
                }
            }

            if ($candidati === []) {
                continue;
            }

            // Con piu' candidati non ci si arrende: Eureka spezza spesso un
            // intervento in due schede, una per macchina (RT-2026-0618:
            // disinstallazione su SL-695, installazione su SL-696), e in quel
            // caso una delle due somiglia molto piu' dell'altra. Restare
            // zitti lasciava sei rapportini bloccati senza dirlo a nessuno.
            //
            // Si ordina per confidenza e poi per articoli in comune, e si
            // propone il primo SOLO se stacca il secondo: a parita' il
            // sistema non sa distinguere, e far confermare a occhi chiusi
            // sarebbe peggio del silenzio.
            usort($candidati, fn (array $a, array $b) => [$b['peso'], $b['articoli']] <=> [$a['peso'], $a['articoli']]);

            if (isset($candidati[1])
                && [$candidati[0]['peso'], $candidati[0]['articoli']] === [$candidati[1]['peso'], $candidati[1]['articoli']]) {
                RegistroSync::movimento('sync-anagrafiche', 'doppione ambiguo, nessuna proposta', [
                    'nostro' => $nostro->number,
                    'candidati' => array_map(fn (array $c) => $c['rapportino']->gestionale_number, $candidati),
                ]);

                continue;
            }

            $scelto = $candidati[0];

            $nostro->update([
                'duplicato_suggerito_id' => $scelto['rapportino']->id,
                'duplicato_suggerito_motivo' => $scelto['motivo'],
            ]);

            $proposte[] = ['nostro' => $nostro, 'importato' => $scelto['rapportino'], 'motivo' => $scelto['motivo']];

            RegistroSync::movimento('sync-anagrafiche', 'doppione proposto', [
                'nostro' => $nostro->number,
                'importato' => $scelto['rapportino']->number,
                'scheda' => $scelto['rapportino']->gestionale_number,
                'motivo' => $scelto['motivo'],
            ]);
        }

        return $proposte;
    }

    /**
     * Ricopia nel CRM le note dell'anagrafica Eureka (Customer::eureka_note).
     *
     * A differenza di reverifyLinkedCustomers(), che compila solo i campi
     * vuoti e non sovrascrive mai un valore gia' presente, qui la
     * sovrascrittura e' voluta: eureka_note e' uno SPECCHIO di sola lettura
     * di un campo che vive su Eureka, non un campo del CRM. Se su Eureka la
     * nota cambia o viene svuotata, qui deve seguire. Per questo la colonna
     * e' separata da gestionale_review_note, che invece la scrive una
     * persona nel CRM e non va mai toccata da qui.
     *
     * Volutamente NON alimenta billing_customer_id: le note sono testo
     * libero ("PAGA RIVER CAFFE' TREVISO", "LAVAGGI MARTELLOZZO") e dedurne
     * un pagante significherebbe indovinare un'anagrafica da una stringa.
     * Servono come riscontro umano contro il pagante configurato, non come
     * sua fonte — stessa logica per cui i collegamenti restano proposte.
     *
     * @return array<int, array{customer: Customer, note: ?string}> solo quelle cambiate
     */
    private function syncEurekaNotes(): array
    {
        $note = $this->client->noteAnagrafiche();

        // Una risposta vuota puo' voler dire "nessuna nota" oppure "chiamata
        // fallita": nel dubbio non si cancella niente. Un eventuale
        // svuotamento vero si vedra' al giro successivo, mentre azzerare
        // 101 note per un 500 di passaggio non sarebbe recuperabile.
        if ($note === []) {
            return [];
        }

        $cambiate = [];

        $customers = Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->get();

        foreach ($customers as $customer) {
            $nuova = $note[(int) $customer->gestionale_code] ?? null;

            if ($nuova === $customer->eureka_note) {
                continue;
            }

            $customer->update(['eureka_note' => $nuova]);
            $cambiate[] = ['customer' => $customer, 'note' => $nuova];
        }

        return $cambiate;
    }

    /**
     * Macchine che Eureka dice installate presso ciascun cliente collegato,
     * indicizzate per id cliente CRM. Una chiamata per cliente, in gruppi
     * concorrenti (vedi reverifyLinkedCustomers()), ma fatta UNA volta sola
     * per esecuzione: la usano sia proposeMachineUnitLinks() sia
     * importInstalledMachines(), e ripeterla significherebbe raddoppiare le
     * chiamate all'endpoint piu' pesante del sync.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function installedMachinesByCustomer(): array
    {
        if ($this->installedByCustomer !== null) {
            return $this->installedByCustomer;
        }

        $customers = Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->get();

        $this->linkedCustomers = $customers;

        return $this->installedByCustomer = $this->client->pooledGet(
            '/show/q/art_installati',
            $customers->mapWithKeys(fn (Customer $customer) => [$customer->id => ['q' => (int) $customer->gestionale_code]])->all(),
        );
    }

    /**
     * Crea direttamente MachineUnit NUOVE (non ancora nel CRM) trovate su
     * Eureka via `/show/q/art_installati` per ogni cliente collegato — a
     * differenza di proposeMachineUnitLinks() qui il record locale non
     * esiste affatto. A differenza del resto di questa classe (proposte, mai
     * scrittura automatica), qui la creazione e' diretta: articoli e
     * matricole sono dati a basso rischio (non toccano fatturazione/clienti,
     * quelli restano sempre da confermare), scelta esplicita per non
     * lasciare un arretrato di conferme manuali una per una.
     *
     * Non creiamo mai un Product nuovo da soli: se non c'e' gia' un Product
     * con quel gestionale_code, la MachineUnit resta senza product_id — va
     * scelto/collegato a mano in un secondo momento (non inquina comunque il
     * catalogo preventivi, a differenza di crearne uno al volo).
     *
     * @return array<int, array{machineUnit: MachineUnit, customer: Customer}>
     */
    private function importInstalledMachines(): array
    {
        $imported = [];

        // Volutamente senza withTrashed(): una matricola soft-eliminata deve
        // restare "sconosciuta" qui, cosi' il ciclo sotto la incontra di
        // nuovo e la ripristina (invece di saltarla per sempre) se Eureka la
        // segnala ancora installata presso un cliente.
        // Qui (a differenza di prima) si tiene anche l'id, non solo la
        // presenza: serve per aggiornare eureka_billing_customer_code anche
        // sulle macchine gia' note, che altrimenti il ciclo sotto salterebbe
        // del tutto (era pensato solo per creare macchine nuove).
        $knownMachineUnits = MachineUnit::query()->get(['id', 'serial_number', 'eureka_billing_customer_code'])
            ->keyBy(fn (MachineUnit $m) => MachineUnit::chiaveMatricola($m->serial_number));
        $knownSerials = $knownMachineUnits->map(fn () => true)->all();

        $installedByCustomer = $this->installedMachinesByCustomer();
        $customers = $this->linkedCustomers;

        foreach ($customers as $customer) {
            $installed = $installedByCustomer[$customer->id] ?? [];

            foreach ($installed as $row) {
                $serial = trim((string) ($row['matricola'] ?? ''));
                $articleId = (int) ($row['id'] ?? 0);

                if ($serial === '' || $articleId <= 0) {
                    continue;
                }

                $key = MachineUnit::chiaveMatricola($serial);

                // id_intestatario_fattura_f15 (confermato dal vendor via email
                // 2026-08-24): chi paga davvero per questa macchina, se diverso
                // dal cliente presso cui e' installata — 0/assente quando
                // coincide col cliente. Va tenuto aggiornato ad ogni sync anche
                // per le macchine gia' note, non solo alla prima importazione
                // (vedi eureka:apply-machine-billing-payer per come si traduce
                // in billing_customer_id — questo comando scrive solo il
                // valore grezzo, non decide mai da solo il pagante).
                $billingCode = (int) ($row['id_intestatario_fattura_f15'] ?? 0);
                $billingCode = $billingCode > 0 ? $billingCode : null;

                if (isset($knownSerials[$key])) {
                    $known = $knownMachineUnits->get($key);

                    // Matricole segnaposto (tutta la stringa "0", es. "0000000")
                    // possono comparire nell'art_installati di piu' clienti
                    // diversi nello stesso giro e collassare su un'unica
                    // MachineUnit (bug reale gia' trovato/aggirato in
                    // ApplyEurekaBillingDestinazione, 2026-08-17: "FORNO"
                    // condivisa da 10 clienti) — scrivere qui il pagante
                    // dell'ultimo cliente processato sarebbe corretto solo per
                    // lui e silenziosamente sbagliato per tutti gli altri.
                    $isPlaceholderSerial = (bool) preg_match('/^0+$/', $serial);

                    if ($known && ! $isPlaceholderSerial && $known->eureka_billing_customer_code !== $billingCode) {
                        $known->eureka_billing_customer_code = $billingCode;
                        $known->save();
                    }

                    continue;
                }

                $knownSerials[$key] = true;

                $modelName = collect([
                    $row['desc_articolo_1'] ?? null,
                    $row['desc_articolo_2'] ?? null,
                    $row['desc_articolo_3'] ?? null,
                ])->filter()->implode(' ') ?: ($row['articolo'] ?? null);

                $product = Product::query()->where('gestionale_code', $articleId)->first();

                // numero_doc_t23/data_documento sono il riferimento al DDT con cui
                // Eureka dice che la macchina e' stata consegnata/installata (vedi
                // doc API §5) — sono la data vera di installazione, non "oggi"
                // (che e' solo quando questo sync l'ha vista per la prima volta).
                // Il numero doc si salva nella nota del posizionamento (non c'e'
                // un campo dedicato): senza, non resterebbe traccia della bolla
                // da nessuna parte una volta creata la MachineUnit.
                $documentNumber = (int) ($row['numero_doc_t23'] ?? 0);
                $installedAt = $this->parseEurekaDate($row['data_documento'] ?? null);

                $installNote = collect([
                    'Importata da Eureka',
                    filled($row['articolo'] ?? null) ? "articolo {$row['articolo']}" : null,
                    $documentNumber > 0 ? "bolla n. {$documentNumber}" : null,
                ])->filter()->implode(', ');

                // firstOrNew con withTrashed() (non firstOrCreate): una
                // matricola vista due volte (es. righe duplicate nella
                // risposta Eureka) romperebbe il vincolo unique
                // tenant_id+serial_number con una create() secca, mandando in
                // errore l'intero comando gestionale:sync — che quindi non
                // arriverebbe mai a inviare il digest. withTrashed() serve
                // anche a ritrovare (e ripristinare) una MachineUnit
                // soft-eliminata che Eureka segnala di nuovo installata,
                // invece di tentare un INSERT che sbatterebbe sullo stesso
                // vincolo unique.
                $machineUnit = MachineUnit::withTrashed()
                    ->firstOrNew(['tenant_id' => $this->tenant->id, 'serial_number' => $serial]);

                $isNew = ! $machineUnit->exists;
                $wasTrashed = $machineUnit->exists && $machineUnit->trashed();

                if (! $isNew && ! $wasTrashed) {
                    continue;
                }

                if ($isNew) {
                    $machineUnit->fill([
                        'product_id' => $product?->id,
                        'model_name' => $modelName,
                        'status' => MachineUnit::STATUS_INSTALLATA,
                        'source' => MachineUnit::SOURCE_EUREKA,
                        'eureka_billing_customer_code' => $billingCode,
                    ]);
                    $machineUnit->save();
                } else {
                    $machineUnit->eureka_billing_customer_code = $billingCode;
                    $machineUnit->restore();
                }

                $machineUnit->moveTo($customer, notes: $installNote, placedAt: $installedAt);

                $imported[] = ['machineUnit' => $machineUnit, 'customer' => $customer];

                // Le macchine il sync le crea da solo (non le propone): senza
                // questa riga comparirebbero in anagrafica senza che nessuno
                // sappia quando ne' da quale cliente.
                RegistroSync::movimento('sync-anagrafiche', 'macchina creata', [
                    'matricola' => $machineUnit->serial_number,
                    'modello' => $machineUnit->display_name,
                    'cliente' => $customer->company_name,
                    'codice_cliente' => $customer->gestionale_code,
                ]);
            }
        }

        return $imported;
    }

    /**
     * La citta' si compila se vuota (utile), ma non genera piu' una
     * segnalazione "da rivedere" quando entrambi i sistemi hanno un valore:
     * su dati reali era quasi sempre solo frazione vs comune (es. CRM "Onigo
     * di Pederobba" / Eureka "PEDEROBBA", stesso posto), non un errore —
     * troppo rumore per essere utile a chi controlla le segnalazioni.
     * (Le email non passano piu' da qui: vedi mergeEurekaEmails().)
     *
     * @return array<int, string>
     */
    private function fieldsExcludedFromDiff(): array
    {
        return ['city'];
    }

    /**
     * Campi dove Eureka e' la fonte di verita': una differenza non va
     * segnalata "da rivedere", si adotta subito il valore Eureka come per un
     * campo vuoto. La ragione sociale su Eureka e' quella fiscale/ufficiale,
     * quella nel CRM spesso solo il nome con cui e' stato salvato in origine
     * (maiuscole diverse, forma abbreviata, suffissi societari mancanti) —
     * non un errore da far decidere a mano.
     *
     * @return array<int, string>
     */
    private function fieldsAutoAdoptedFromEureka(): array
    {
        return ['company_name'];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function customerFieldMap(): array
    {
        return [
            'company_name' => ['rag_sociale_1', 'Ragione sociale'],
            'vat_number' => ['partita_iva', 'P.IVA'],
            'tax_code' => ['codice_fiscale', 'Codice fiscale'],
            'city' => ['citta', 'Città'],
            'province' => ['sigla_prov', 'Provincia'],
        ];
    }

    /**
     * Il CRM supporta piu' email per cliente (Customer.emails, un array) —
     * qui non serve scartare la differenza, si puo' arricchire per davvero:
     * se Eureka ha piu' indirizzi scritti in un solo campo (separati da
     * virgola/punto e virgola/slash — mai da "@" quindi split sicuro), si
     * aggiungono alle email gia' presenti invece di sovrascriverle o
     * segnalarle come "diverse". Vedi mergeEurekaPhones() per lo stesso
     * concetto sui telefoni, piu' delicato.
     *
     * @return array<int, string> le email aggiunte (vuoto se nessuna nuova)
     */
    private function mergeEurekaEmails(Customer $customer, ?string $remoteValue): array
    {
        if ($remoteValue === null) {
            return [];
        }

        $remoteEmails = collect(preg_split('/[,;\/]+/', $remoteValue))
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => str_contains($email, '@'));

        $existing = collect($customer->emails)->map(fn (string $email) => mb_strtolower($email));

        $new = $remoteEmails
            ->reject(fn (string $email) => $existing->contains(mb_strtolower($email)))
            ->unique(fn (string $email) => mb_strtolower($email))
            ->values();

        if ($new->isEmpty()) {
            return [];
        }

        $customer->update(['emails' => [...$customer->emails, ...$new]]);

        return $new->all();
    }

    /**
     * Stesso concetto di mergeEurekaEmails() ma piu' delicato: "," e "/" sono
     * sempre separatori tra numeri diversi (mai parte del formato di un
     * numero), ma "-" e' ambiguo — a volte e' interno a un singolo numero
     * (visto su dati reali: "0424-514008", nessuno spazio in tutto il
     * blocco) e a volte separa due numeri diversi ("041 5381522-041
     * 921603", ognuno gia' completo di prefisso). Regola usata: un blocco
     * SENZA nessuno spazio e' un numero solo, mai spezzato sul trattino; un
     * blocco CON uno spazio viene invece spezzato sul trattino, e un
     * frammento risultante senza il proprio prefisso (es. "0722
     * 629300-629355") eredita quello del frammento precedente invece di
     * essere scartato o salvato incompleto — stessa lezione della P.IVA
     * placeholder: mai scrivere un dato che potrebbe essere sbagliato. Un
     * controllo di lunghezza minima dopo la normalizzazione scarta comunque
     * i frammenti troppo corti per essere un numero vero (es. "0421 658"
     * troncato, visto su dati reali).
     *
     * @return array<int, string> i telefoni aggiunti, gia' normalizzati (vuoto se nessuno nuovo)
     */
    private function mergeEurekaPhones(Customer $customer, ?string $remoteValue): array
    {
        if ($remoteValue === null) {
            return [];
        }

        $numbers = [];

        foreach (preg_split('/[,;\/]+/', $remoteValue) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            if (! str_contains($chunk, ' ')) {
                // Nessuno spazio in tutto il blocco: e' un singolo numero
                // formattato col trattino al posto dello spazio (visto su
                // dati reali: "0424-514008"), non piu' numeri separati da
                // trattino — non va spezzato ulteriormente.
                $numbers[] = $chunk;

                continue;
            }

            $lastPrefix = null;

            foreach (preg_split('/-+/', $chunk) as $part) {
                $part = trim($part);

                if ($part === '') {
                    continue;
                }

                if (str_contains($part, ' ')) {
                    [$prefix] = explode(' ', $part, 2);
                    $lastPrefix = $prefix;
                    $numbers[] = $part;
                } elseif ($lastPrefix !== null) {
                    $numbers[] = "{$lastPrefix} {$part}";
                }
                // Nessuno spazio e nessun prefisso precedente da ereditare:
                // non abbastanza sicuro per ricostruirlo, si scarta.
            }
        }

        // "+39" (3 caratteri) + almeno 8 cifre: sotto questa soglia e'
        // quasi certamente un frammento troncato, non un numero vero.
        $normalized = collect($numbers)
            ->map(fn (string $number) => PhoneNumber::normalizeItalian($number))
            ->filter(fn (?string $number) => $number !== null && mb_strlen($number) >= 11);

        $existing = collect($customer->phones)->map(fn (string $phone) => PhoneNumber::normalizeItalian($phone));

        $new = $normalized
            ->reject(fn (string $number) => $existing->contains($number))
            ->unique()
            ->values();

        if ($new->isEmpty()) {
            return [];
        }

        $customer->update(['phones' => [...$customer->phones, ...$new]]);

        return $new->all();
    }

    /**
     * data_documento arriva come stringa ISO ("2024-03-21T00:00:00.000+01:00")
     * — non ci fidiamo del formato al 100% (e' un fornitore esterno), quindi
     * un parse fallito torna null invece di far esplodere l'intero sync per
     * una riga sola.
     */
    private function parseEurekaDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Oltre al trim, riduce spazi multipli interni a uno solo — visto su
     * dati reali (es. "S.A.S.  di Saccon Romeo", due spazi dopo il punto):
     * altrimenti il confronto case-insensitive con il valore locale segnala
     * un "diverso" per un dettaglio di formattazione, non un errore vero.
     */
    private function normalizedRemoteValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value));

        return $value === '' ? null : $value;
    }

    /**
     * P.IVA/Codice Fiscale su Eureka a volte sono un placeholder invece di
     * un valore vero — scoperto su dati reali: 32 clienti avevano
     * "partita_iva" identica al proprio id Eureka, altri valori tipo "2",
     * "22" troppo corti per essere plausibili. Va scartato PRIMA di
     * autocompilare o segnalare una differenza, altrimenti si scrive
     * spazzatura nel CRM o si segnala un "diverso" senza senso.
     */
    private function looksLikePlaceholder(string $field, string $remoteValue, array $remote): bool
    {
        if (! in_array($field, ['vat_number', 'tax_code'], true)) {
            return false;
        }

        if (mb_strlen($remoteValue) < 5) {
            return true;
        }

        return isset($remote['id']) && $remoteValue === (string) $remote['id'];
    }

    private function normalizedLocalValue(Customer $customer, string $field): ?string
    {
        $value = $customer->{$field};

        return blank($value) ? null : (string) $value;
    }
}
