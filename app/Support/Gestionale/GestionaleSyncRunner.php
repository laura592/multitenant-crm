<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

    public function __construct(private readonly Tenant $tenant)
    {
        $this->client = new EurekaClient($tenant);
    }

    /**
     * @return array{autofilled: array, diffs: array, customerLinks: array, productLinks: array, machineUnitLinks: array, newMachines: array}
     */
    public function run(): array
    {
        [$autofilled, $diffs] = $this->reverifyLinkedCustomers();

        return [
            'autofilled' => $autofilled,
            'diffs' => $diffs,
            'customerLinks' => $this->proposeCustomerLinks(),
            'productLinks' => $this->proposeProductLinks(),
            'machineUnitLinks' => $this->proposeMachineUnitLinks(),
            'newMachines' => $this->importInstalledMachines(),
        ];
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
        // su gestionale_code (UniqueConstraintViolationException).
        $usedCodes = Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->pluck('gestionale_code')
            ->all();

        Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('gestionale_code')
            ->whereNull('gestionale_suggested_code')
            ->each(function (Customer $customer) use (&$proposals, $usedCodes) {
                if (filled($customer->vat_number)) {
                    $matches = $this->client->cercaClientePerPiva($customer->vat_number);
                } elseif (filled($customer->company_name)) {
                    $matches = array_values(array_filter(
                        $this->client->cercaClienti($customer->company_name),
                        fn (array $c) => mb_strtolower(trim($c['rag_sociale_1'] ?? '')) === mb_strtolower(trim($customer->company_name)),
                    ));
                } else {
                    return;
                }

                $matches = array_values(array_filter(
                    $matches,
                    fn (array $c) => ! in_array((int) ($c['id'] ?? 0), $usedCodes, true),
                ));

                if (count($matches) !== 1) {
                    return;
                }

                $match = $matches[0];
                $label = $match['rag_sociale_1'] ?? '?';
                $customer->update(['gestionale_suggested_code' => $match['id'], 'gestionale_suggested_label' => $label]);
                $proposals[] = ['customer' => $customer, 'label' => $label, 'id' => $match['id']];
            });

        return $proposals;
    }

    /**
     * @return array<int, array{product: Product, label: string, id: int}>
     */
    private function proposeProductLinks(): array
    {
        $proposals = [];

        Product::query()
            ->where('type', Product::TYPE_MACHINE)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $this->tenant->id))
            ->whereNull('gestionale_code')
            ->whereNull('gestionale_suggested_code')
            ->each(function (Product $product) use (&$proposals) {
                $keyword = Str::of((string) $product->name)->explode(' ')->first();

                if (blank($keyword)) {
                    return;
                }

                $results = $this->client->cercaArticoli($keyword);

                if (count($results) !== 1) {
                    return;
                }

                $match = $results[0];
                $label = "{$match['codice']} — {$match['descr1']}";
                $product->update(['gestionale_suggested_code' => $match['id_eureka'], 'gestionale_suggested_label' => $label]);
                $proposals[] = ['product' => $product, 'label' => $label, 'id' => $match['id_eureka']];
            });

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

        MachineUnit::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('serial_number')
            ->whereNotNull('product_id')
            ->whereNull('gestionale_code')
            ->whereNull('gestionale_suggested_code')
            ->whereHas('product', fn ($q) => $q->whereNotNull('gestionale_code'))
            ->with('product')
            ->each(function (MachineUnit $machineUnit) use (&$proposals) {
                $results = $this->client->cercaMatricole($machineUnit->product->gestionale_code, $machineUnit->serial_number);

                $matches = array_values(array_filter(
                    $results,
                    fn (array $item) => mb_strtolower(trim($item['matricola'] ?? '')) === mb_strtolower(trim($machineUnit->serial_number)),
                ));

                if (count($matches) !== 1) {
                    return;
                }

                $match = $matches[0];
                $label = $match['matricola'];
                $machineUnit->update(['gestionale_suggested_code' => $match['id'], 'gestionale_suggested_label' => $label]);
                $proposals[] = ['machineUnit' => $machineUnit, 'label' => $label, 'id' => $match['id']];
            });

        return $proposals;
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

        $knownSerials = MachineUnit::query()->pluck('serial_number')
            ->map(fn (string $serial) => mb_strtolower(trim($serial)))
            ->flip()
            ->all();

        $customers = Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->get();

        // Vedi reverifyLinkedCustomers(): stesso principio, una chiamata per
        // cliente ma tutte in gruppi concorrenti invece che in sequenza.
        $installedByCustomer = $this->client->pooledGet(
            '/show/q/art_installati',
            $customers->mapWithKeys(fn (Customer $customer) => [$customer->id => ['q' => (int) $customer->gestionale_code]])->all(),
        );

        foreach ($customers as $customer) {
            $installed = $installedByCustomer[$customer->id] ?? [];

            foreach ($installed as $row) {
                $serial = trim((string) ($row['matricola'] ?? ''));
                $articleId = (int) ($row['id'] ?? 0);

                if ($serial === '' || $articleId <= 0) {
                    continue;
                }

                $key = mb_strtolower($serial);

                if (isset($knownSerials[$key])) {
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

                // firstOrCreate (non create): una matricola vista due volte
                // (es. righe duplicate nella risposta Eureka) romperebbe il
                // vincolo unique tenant_id+serial_number con una create()
                // secca, mandando in errore l'intero comando gestionale:sync
                // — che quindi non arriverebbe mai a inviare il digest.
                $machineUnit = MachineUnit::firstOrCreate(
                    ['tenant_id' => $this->tenant->id, 'serial_number' => $serial],
                    [
                        'product_id' => $product?->id,
                        'model_name' => $modelName,
                        'status' => MachineUnit::STATUS_INSTALLATA,
                        'source' => MachineUnit::SOURCE_EUREKA,
                    ],
                );

                if (! $machineUnit->wasRecentlyCreated) {
                    continue;
                }

                $machineUnit->moveTo($customer, notes: $installNote, placedAt: $installedAt);

                $imported[] = ['machineUnit' => $machineUnit, 'customer' => $customer];
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
