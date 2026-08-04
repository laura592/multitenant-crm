<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MachineUnitProposal;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\PhoneNumber;
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
            'newMachines' => $this->proposeInstalledMachines(),
        ];
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function reverifyLinkedCustomers(): array
    {
        $autofilled = [];
        $diffs = [];

        Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->each(function (Customer $customer) use (&$autofilled, &$diffs) {
                $remote = $this->findEurekaCustomer($customer);

                if ($remote === null) {
                    return;
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
                    } elseif (
                        ! in_array($localField, $this->fieldsExcludedFromDiff(), true)
                        && mb_strtolower($localValue) !== mb_strtolower($remoteValue)
                    ) {
                        $diffLabels[] = "{$label} (CRM: \"{$localValue}\", Eureka: \"{$remoteValue}\")";
                    }
                }

                if ($updates !== []) {
                    $customer->update($updates);
                }

                if ($filledLabels === [] && $diffLabels === []) {
                    return;
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
            });

        return [$autofilled, $diffs];
    }

    private function findEurekaCustomer(Customer $customer): ?array
    {
        $candidates = filled($customer->vat_number)
            ? $this->client->cercaClientePerPiva($customer->vat_number)
            : $this->client->cercaClienti($customer->company_name ?: (string) $customer->full_name);

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

        Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('gestionale_code')
            ->whereNull('gestionale_suggested_code')
            ->each(function (Customer $customer) use (&$proposals) {
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
     * Propone MachineUnit NUOVE (non ancora nel CRM) trovate su Eureka via
     * `/show/q/art_installati` per ogni cliente collegato — a differenza di
     * proposeMachineUnitLinks() qui il record locale non esiste affatto,
     * quindi non c'e' un gestionale_suggested_code su cui appoggiarsi: la
     * proposta vive in MachineUnitProposal (vedi model) finche' non viene
     * confermata (crea davvero la MachineUnit) o scartata (dismissed_at
     * valorizzato, non cancellata — altrimenti ricomparirebbe al giro
     * successivo perche' non piu' tra le matricole "note").
     *
     * Non creiamo mai un Product nuovo da soli: se non c'e' gia' un Product
     * con quel gestionale_code, la proposta resta senza product_id — va
     * scelto/collegato a mano in fase di conferma.
     *
     * @return array<int, array{proposal: MachineUnitProposal, customer: Customer}>
     */
    private function proposeInstalledMachines(): array
    {
        $proposals = [];

        $knownSerials = MachineUnit::query()->pluck('serial_number')
            ->merge(MachineUnitProposal::query()->pluck('serial_number'))
            ->map(fn (string $serial) => mb_strtolower(trim($serial)))
            ->flip()
            ->all();

        Customer::query()
            ->where('tenant_id', $this->tenant->id)
            ->whereNotNull('gestionale_code')
            ->each(function (Customer $customer) use (&$proposals, &$knownSerials) {
                $installed = $this->client->articoliInstallati((int) $customer->gestionale_code);

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

                    // firstOrCreate (non create): $knownSerials e' un check in
                    // memoria costruito a inizio ciclo, non riflette scritture
                    // concorrenti fatte durante i minuti in cui questo giro e'
                    // in esecuzione (each() ripagina con OFFSET/LIMIT, non e'
                    // immune a un cliente visto due volte se il set di
                    // risultati si muove nel frattempo) - senza firstOrCreate
                    // una matricola vista due volte nello stesso giro rompe il
                    // vincolo unique tenant_id+serial_number e manda in errore
                    // l'intero comando gestionale:sync, che quindi non
                    // arriverebbe mai a inviare il digest.
                    $proposal = MachineUnitProposal::firstOrCreate(
                        ['tenant_id' => $this->tenant->id, 'serial_number' => $serial],
                        [
                            'customer_id' => $customer->id,
                            'product_id' => $product?->id,
                            'model_name' => $modelName,
                            'eureka_article_id' => $articleId,
                            'eureka_article_code' => $row['articolo'] ?? null,
                        ],
                    );

                    if (! $proposal->wasRecentlyCreated) {
                        continue;
                    }

                    $proposals[] = ['proposal' => $proposal, 'customer' => $customer];
                }
            });

        return $proposals;
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
