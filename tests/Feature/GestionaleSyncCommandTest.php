<?php

namespace Tests\Feature;

use App\Mail\GestionaleSyncDigestMail;
use App\Mail\GestionaleSyncFailedMail;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GestionaleSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Http::fake() con soli pattern specifici NON blocca le URL non
        // abbinate: escono in rete davvero, verso l'API di produzione del
        // fornitore. Non se ne era accorto nessuno finche' le chiamate non
        // stubbate erano veloci; con la lettura in blocco delle note
        // (EurekaClient::noteAnagrafiche(), timeout 180s) un singolo test e'
        // passato da 1 a 125 secondi, cioe' il tempo della chiamata vera.
        // Da qui in poi una richiesta non stubbata fa fallire il test invece
        // di raggiungere Eureka.
        Http::preventStrayRequests();
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'is_master' => true,
            'notify_customer_gestionale_emails' => ['ufficio@alexcaffe.it'],
        ]);
    }

    public function test_autofills_blank_fields_and_flags_differences_without_overwriting(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gdp Italia SRL',
            'gestionale_code' => 1,
            'province' => 'VE', // gia' valorizzato: diverso da Eureka, non va sovrascritto
        ]);

        Http::fake([
            '*anagrafica/cerca*nome=Gdp*' => Http::response([[
                'id' => 1,
                'rag_sociale_1' => 'GDP ITALIA SRL',
                'partita_iva' => '00554810242',
                'codice_fiscale' => '00554810242',
                'citta' => 'SAN GIUSEPPE DI CASSOLA                           ',
                'sigla_prov' => 'VI',
                'email' => 'info@gdpitalia.com',
                'nr_telefono' => '0424-514008',
            ]], 200),
            '*art_installati*' => Http::response([], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $customer->refresh();
        $this->assertSame('00554810242', $customer->vat_number);
        $this->assertSame('00554810242', $customer->tax_code);
        $this->assertSame('info@gdpitalia.com', $customer->primaryEmail());
        $this->assertSame('+390424514008', $customer->primaryPhone());
        // Campo gia' valorizzato: mai toccato, anche se diverso da Eureka.
        $this->assertSame('VE', $customer->province);
        $this->assertNotNull($customer->gestionale_review_flagged_at);
        $this->assertStringContainsString('Provincia', $customer->gestionale_review_note);

        Mail::assertSent(GestionaleSyncDigestMail::class, function ($mail) use ($customer) {
            $autofilledIds = collect($mail->results['autofilled'])->map(fn ($row) => $row['customer']->id);
            $diffIds = collect($mail->results['diffs'])->map(fn ($row) => $row['customer']->id);

            return $mail->hasTo('ufficio@alexcaffe.it')
                && $autofilledIds->contains($customer->id)
                && $diffIds->contains($customer->id);
        });
    }

    public function test_different_city_is_not_flagged_as_a_diff_but_still_autofills_when_blank(): void
    {
        // La citta' su Eureka spesso e' solo il comune invece della frazione
        // (es. "PEDEROBBA" invece di "Onigo di Pederobba", stesso posto):
        // troppo rumore per segnalarla come "da rivedere".
        Mail::fake();

        $tenant = $this->makeTenant();

        $withCity = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Duna Rossa',
            'gestionale_code' => 10,
            'city' => 'Duna Verde di Caorle',
        ]);

        $withoutCity = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Duna Blu',
            'gestionale_code' => 11,
        ]);

        Http::fake([
            '*anagrafica/cerca*nome=Duna+Rossa*' => Http::response([[
                'id' => 10, 'rag_sociale_1' => 'DUNA ROSSA', 'citta' => 'CAORLE',
            ]], 200),
            '*anagrafica/cerca*nome=Duna+Blu*' => Http::response([[
                'id' => 11, 'rag_sociale_1' => 'DUNA BLU', 'citta' => 'CAORLE',
            ]], 200),
            '*art_installati*' => Http::response([], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $withCity->refresh();
        $withoutCity->refresh();

        $this->assertSame('Duna Verde di Caorle', $withCity->city, 'citta\' gia\' valorizzata: mai toccata');
        $this->assertNull($withCity->gestionale_review_flagged_at, 'la sola differenza di citta\' non deve generare una segnalazione');

        $this->assertSame('CAORLE', $withoutCity->city, 'citta\' vuota: si compila comunque');
    }

    public function test_merges_new_emails_from_eureka_without_removing_existing_ones(): void
    {
        // Il CRM supporta piu' email per cliente: quando Eureka ne ha piu'
        // di una scritte in un solo campo (separate da virgola/slash — mai
        // ambiguo perche' un'email non contiene mai quei caratteri), vanno
        // AGGIUNTE a quelle gia' presenti, non confrontate come se fosse un
        // conflitto a valore singolo. Caso reale: Dersut Caffe' aveva solo
        // la PEC salvata, Eureka aveva due email operative diverse.
        Mail::fake();

        $tenant = $this->makeTenant();

        $withEmail = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Dersut Caffe',
            'gestionale_code' => 20,
            'pec' => 'dersutcaffe@legalmail.it',
            'emails' => ['dersutcaffe@legalmail.it'],
        ]);

        $withoutEmail = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Senza Email',
            'gestionale_code' => 21,
        ]);

        $alreadyUpToDate = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gia Aggiornato',
            'gestionale_code' => 22,
            'emails' => ['stessa@esempio.it'],
        ]);

        Http::fake([
            '*anagrafica/cerca*nome=Dersut*' => Http::response([[
                'id' => 20, 'rag_sociale_1' => 'DERSUT CAFFE', 'email' => 'info@dersut.it, p.dorio@dersut.it',
            ]], 200),
            '*anagrafica/cerca*nome=Senza+Email*' => Http::response([[
                'id' => 21, 'rag_sociale_1' => 'SENZA EMAIL', 'email' => 'nuova@esempio.it',
            ]], 200),
            '*anagrafica/cerca*nome=Gia+Aggiornato*' => Http::response([[
                'id' => 22, 'rag_sociale_1' => 'GIA AGGIORNATO', 'email' => 'STESSA@ESEMPIO.IT',
            ]], 200),
            '*art_installati*' => Http::response([], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $withEmail->refresh();
        $withoutEmail->refresh();
        $alreadyUpToDate->refresh();

        $this->assertSame(
            ['dersutcaffe@legalmail.it', 'info@dersut.it', 'p.dorio@dersut.it'],
            $withEmail->emails,
            'le due email nuove si aggiungono, la PEC gia\' presente non si tocca'
        );
        $this->assertNotNull($withEmail->gestionale_review_flagged_at, 'aggiungere email e\' un\'azione da segnalare, come un autofill');
        $this->assertStringContainsString('Email aggiunte', $withEmail->gestionale_review_note);

        $this->assertSame(['nuova@esempio.it'], $withoutEmail->emails, 'email vuota: si compila comunque');

        $this->assertSame(['stessa@esempio.it'], $alreadyUpToDate->emails);
        $this->assertNull($alreadyUpToDate->gestionale_review_flagged_at, 'stessa email (case diversa): nulla da aggiungere, nessuna segnalazione');
    }

    public function test_proposes_customer_link_via_exact_piva_match(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'A Casa Vecia',
            'vat_number' => '04251310274',
        ]);

        Http::fake([
            '*anagrafica/cerca*piva=04251310274*' => Http::response([[
                'id' => 19,
                'rag_sociale_1' => 'A CASA VECIA di PROCURA PAOLO E FABIO',
                'partita_iva' => '04251310274',
            ]], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $customer->refresh();
        $this->assertSame(19, $customer->gestionale_suggested_code);
        $this->assertSame('A CASA VECIA di PROCURA PAOLO E FABIO', $customer->gestionale_suggested_label);
        $this->assertNull($customer->gestionale_code, 'la proposta non deve mai auto-assegnarsi');
    }

    public function test_proposes_product_link_only_when_search_is_unambiguous(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $unique = Product::create(['sku' => 'UNIQUEMODEL', 'type' => Product::TYPE_MACHINE, 'name' => 'UniqueModelXYZ']);
        $ambiguous = Product::create(['sku' => 'XTCLASSIC-2G', 'type' => Product::TYPE_MACHINE, 'name' => 'XTClassic Due Gruppi']);

        // La ricerca articoli parte dallo SKU, non dal nome (vedi
        // GestionaleSyncRunner::proposeProductLinks(): nomi diversi possono
        // condividere la prima parola, i codici no) — le fake vanno quindi
        // agganciate al codice.
        Http::fake([
            '*articoli/lista/UNIQUEMODEL*' => Http::response([[
                'id_eureka' => 555, 'codice' => 'UNIQUEMODEL', 'descr1' => 'MACCHINA UNICA',
            ]], 200),
            '*articoli/lista/XTCLASSIC-2G*' => Http::response([
                ['id_eureka' => 111, 'codice' => 'XTCLASSIC', 'descr1' => 'MACCHINA DALLA CORTE XT CLASSIC'],
                ['id_eureka' => 222, 'codice' => 'XTCLASSIC2', 'descr1' => 'ALTRA VARIANTE XT CLASSIC'],
            ], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(555, $unique->fresh()->gestionale_suggested_code);
        $this->assertSame('UNIQUEMODEL — MACCHINA UNICA', $unique->fresh()->gestionale_suggested_label);
        $this->assertNull($ambiguous->fresh()->gestionale_suggested_code, 'piu\' di un risultato: nessuna proposta automatica');
    }

    /**
     * La proposta nasce da /show/q/art_installati (l'elenco installato presso
     * il cliente della macchina), non piu' da /crm_api/m14/search: quella
     * rotta risponde 403 dal 2026-08-27. gestionale_suggested_code porta
     * quindi l'id ARTICOLO, non l'id matricola — vedi
     * MachineUnit::confermaCollegamentoEureka().
     */
    public function test_proposes_machine_unit_link_when_matricola_is_installed_at_the_customer(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $product = Product::create([
            'sku' => 'A600FM', 'type' => Product::TYPE_MACHINE, 'name' => 'A600 FM', 'gestionale_code' => 19339,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Hotel Marco Polo', 'gestionale_code' => 3033,
        ]);

        $matched = MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'current_customer_id' => $customer->id, 'serial_number' => '00113684',
        ]);

        $notFound = MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'current_customer_id' => $customer->id, 'serial_number' => 'INESISTENTE-001',
        ]);

        Http::fake([
            '*art_installati*' => Http::response([[
                'id_codice_f15' => 3033, 'id' => 19339, 'matricola' => '00113684',
                'articolo' => 'A600FM', 'desc_articolo_1' => "MACCHINA PER CAFFE' FRANKE A600FM",
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(19339, $matched->fresh()->gestionale_suggested_code);
        $this->assertSame('00113684', $matched->fresh()->gestionale_suggested_label);
        $this->assertNull($matched->fresh()->gestionale_code, 'la proposta non deve mai auto-assegnarsi');
        $this->assertNull($notFound->fresh()->gestionale_suggested_code);
    }

    /**
     * La conferma marca source=eureka e lascia gestionale_code vuoto: quella
     * colonna e' l'id matricola M14, che art_installati non espone e che non
     * possiamo piu' leggere (modulo `crm` negato).
     */
    public function test_confirming_a_machine_link_marks_it_eureka_sourced_without_faking_a_matricola_id(): void
    {
        $tenant = $this->makeTenant();

        $product = Product::create([
            'sku' => 'A600FM', 'type' => Product::TYPE_MACHINE, 'name' => 'A600 FM', 'gestionale_code' => 19339,
        ]);

        $machineUnit = MachineUnit::create([
            'tenant_id' => $tenant->id, 'serial_number' => '00113684',
            'gestionale_suggested_code' => 19339, 'gestionale_suggested_label' => '00113684',
        ]);

        $machineUnit->confermaCollegamentoEureka();
        $machineUnit->refresh();

        $this->assertSame(MachineUnit::SOURCE_EUREKA, $machineUnit->source);
        $this->assertNull($machineUnit->gestionale_code);
        $this->assertNull($machineUnit->gestionale_suggested_code);
        // La proposta porta l'articolo Eureka: utile per una macchina
        // importata senza prodotto, ma non deve sovrascriverne uno gia' scelto.
        $this->assertSame($product->id, $machineUnit->product_id);
    }

    /**
     * Un prodotto non collegato non e' piu' un motivo per saltare la
     * proposta (lo era solo perche' la vecchia ricerca m14 pretendeva
     * id_articolo_m10): dentro l'elenco installato presso quel cliente la
     * matricola basta da sola.
     */
    public function test_proposes_machine_unit_link_even_when_product_is_not_linked_to_eureka(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $product = Product::create(['sku' => 'NOLINK', 'type' => Product::TYPE_MACHINE, 'name' => 'Senza collegamento']);

        $customer = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Hotel Marco Polo', 'gestionale_code' => 3033,
        ]);

        $machineUnit = MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id,
            'current_customer_id' => $customer->id, 'serial_number' => 'SN-001',
        ]);

        Http::fake([
            '*art_installati*' => Http::response([[
                'id_codice_f15' => 3033, 'id' => 40404, 'matricola' => 'SN-001', 'articolo' => 'IGNOTO',
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'crm_api/m14/search'));
        $this->assertSame(40404, $machineUnit->fresh()->gestionale_suggested_code);
    }

    public function test_does_not_autofill_placeholder_looking_piva_or_codice_fiscale(): void
    {
        // Scoperto su dati reali: Eureka a volte mette come "partita_iva" lo
        // stesso id del cliente (placeholder quando manca il dato vero), o
        // valori troppo corti per essere plausibili (es. "2"). Non vanno
        // mai scritti nel CRM.
        Mail::fake();

        $tenant = $this->makeTenant();

        $placeholderId = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Corrispettivi',
            'gestionale_code' => 2,
        ]);

        $tooShort = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'A.C.R.A. Mogliano Veneto',
            'gestionale_code' => 3,
        ]);

        Http::fake([
            '*anagrafica/cerca*nome=Corrispettivi*' => Http::response([[
                'id' => 2,
                'rag_sociale_1' => 'CORRISPETTIVI',
                'partita_iva' => '2', // = id: placeholder
                'codice_fiscale' => '2',
            ]], 200),
            '*anagrafica/cerca*nome=A.C.R.A*' => Http::response([[
                'id' => 3,
                'rag_sociale_1' => 'A.C.R.A. MOGLIANO VENETO',
                'partita_iva' => '22', // troppo corto per essere vero
                'codice_fiscale' => '22',
            ]], 200),
            '*art_installati*' => Http::response([], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $placeholderId->refresh();
        $tooShort->refresh();

        $this->assertNull($placeholderId->vat_number);
        $this->assertNull($placeholderId->tax_code);
        $this->assertNull($tooShort->vat_number);
        $this->assertNull($tooShort->tax_code);
    }

    public function test_sends_no_digest_when_nothing_to_report(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gdp Italia SRL',
            'gestionale_code' => 1,
            'vat_number' => '00554810242',
            'tax_code' => '00554810242',
            'city' => 'San Giuseppe Di Cassola',
            'province' => 'VI',
            'emails' => ['info@gdpitalia.com'],
            'phones' => ['0424-514008'],
        ]);

        Http::fake([
            '*anagrafica/cerca*piva=00554810242*' => Http::response([[
                'id' => 1,
                'rag_sociale_1' => 'GDP ITALIA SRL',
                'partita_iva' => '00554810242',
                'codice_fiscale' => '00554810242',
                'citta' => 'San Giuseppe Di Cassola',
                'sigla_prov' => 'VI',
                'email' => 'info@gdpitalia.com',
                'nr_telefono' => '0424-514008',
            ]], 200),
            '*art_installati*' => Http::response([], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_sends_failure_alert_instead_of_digest_when_eureka_is_unreachable(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gdp Italia SRL',
            'gestionale_code' => 1,
            'vat_number' => '00554810242',
        ]);

        // Ogni chiamata a Eureka risponde 502: nessun risultato da nessun
        // metodo di GestionaleSyncRunner (tutti best-effort, tornano array
        // vuoti), esattamente come "niente da segnalare" — deve pero'
        // scatenare l'alert di irraggiungibilita', non il silenzio del test
        // sopra.
        Http::fake([
            '*' => Http::response('Bad Gateway', 502),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        Mail::assertSent(GestionaleSyncFailedMail::class, fn ($mail) => $mail->hasTo('ufficio@alexcaffe.it'));
        Mail::assertNotSent(GestionaleSyncDigestMail::class);
    }

    /**
     * Il caso vero del 2026-08-27: il fornitore introduce i diritti per
     * modulo e /crm_api/m14/search inizia a rispondere 403, mentre tutto il
     * resto di Eureka continua a funzionare. Prima di questo controllo la
     * ricerca matricole tornava semplicemente vuota e il sync riportava "0
     * righe da controllare" — indistinguibile da una notte tranquilla.
     */
    public function test_reports_a_single_forbidden_endpoint_even_when_the_rest_of_eureka_works(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $product = Product::create([
            'sku' => 'A300', 'type' => Product::TYPE_MACHINE, 'name' => 'Franke A300', 'gestionale_code' => 19356,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gdp Italia SRL',
            'gestionale_code' => 1,
            'vat_number' => '00554810242',
            'province' => 'VI',
            'city' => 'SAN GIUSEPPE DI CASSOLA',
            'email' => 'info@gdpitalia.com',
        ]);

        MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => '34000000335017',
        ]);

        Http::fake([
            // Un endpoint negato mentre il resto risponde: e' la forma che
            // ha avuto il 403 su /crm_api/m14/search del 2026-08-27, ora
            // riprodotta sull'endpoint da cui dipendono le macchine.
            '*art_installati*' => Http::response('<html><body><h1>403: Forbidden</h1></body></html>', 403),
            '*anagrafica/cerca*' => Http::response([[
                'id' => 1,
                'rag_sociale_1' => 'GDP ITALIA SRL',
                'partita_iva' => '00554810242',
                'citta' => 'SAN GIUSEPPE DI CASSOLA',
                'sigla_prov' => 'VI',
                'email' => 'info@gdpitalia.com',
            ]], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')
            ->expectsOutputToContain('/show/q/*')
            ->assertExitCode(0);

        // Eureka risponde: non e' il caso "irraggiungibile", quindi deve
        // arrivare il digest normale (con il riquadro rosso), non l'alert.
        Mail::assertNotSent(GestionaleSyncFailedMail::class);
        Mail::assertSent(GestionaleSyncDigestMail::class, function (GestionaleSyncDigestMail $mail) {
            $issues = $mail->results['apiIssues'];

            return count($issues) === 1
                && $issues[0]['endpoint'] === '/show/q/*'
                && str_contains($issues[0]['statuses'], '403');
        });
    }

    /**
     * Il digest parte anche quando non c'e' nulla da rivedere, se una
     * chiamata e' stata rifiutata: e' proprio il silenzio a ingannare.
     */
    public function test_sends_digest_with_nothing_to_review_when_an_endpoint_is_forbidden(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $product = Product::create([
            'sku' => 'A300', 'type' => Product::TYPE_MACHINE, 'name' => 'Franke A300', 'gestionale_code' => 19356,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gdp Italia SRL',
            'gestionale_code' => 1,
        ]);

        MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => '34000000335017',
        ]);

        Http::fake([
            '*art_installati*' => Http::response('', 403),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        Mail::assertSent(GestionaleSyncDigestMail::class);
    }

    /**
     * Il rovescio della medaglia: un 500 di passaggio su una chiamata sola
     * (il fornitore ne produce a raffica, vedi EurekaClient) non deve
     * trasformare ogni sync in un allarme — le chiamate restano best-effort.
     */
    public function test_does_not_report_a_sporadic_server_error_among_successful_calls(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        foreach (['Gdp Italia SRL', 'Corrispettivi', 'Agora Park Hotel'] as $i => $name) {
            Customer::create([
                'tenant_id' => $tenant->id,
                'company_name' => $name,
                'gestionale_code' => $i + 1,
            ]);
        }

        $calls = 0;

        Http::fake([
            '*anagrafica/cerca*' => function () use (&$calls) {
                $calls++;

                return $calls === 1
                    ? Http::response('Internal Server Error', 500)
                    : Http::response([], 200);
            },
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    /**
     * Le note Eureka sono uno specchio di sola lettura: si ricopiano sul
     * cliente collegato, ripulite dal "\r\u0000" che la conversione da RTF
     * lascia in coda a ognuna.
     */
    public function test_mirrors_eureka_notes_onto_linked_customers(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $conNota = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Bortolo', 'gestionale_code' => 3311,
        ]);
        // Nota composta solo da caratteri di scarto: deve restare vuota
        // (caso reale, anagrafica 3045).
        $soloScarto = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Marina del Cavallino', 'gestionale_code' => 3045,
        ]);

        Http::fake([
            '*anagrafica/f15?note=1*' => Http::response([
                // NUL vero: nel JSON dell'API arriva come \u0000 e il decoder
                // lo trasforma in questo carattere (in PHP "\u0000" sarebbe
                // invece il testo letterale, PHP vuole \x00 o \u{0000}).
                ['id_eureka' => 3311, 'note' => "PAGA RIVER CAFFE' TREVISO\r\x00"],
                ['id_eureka' => 3045, 'note' => "\r\x00"],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame("PAGA RIVER CAFFE' TREVISO", $conNota->fresh()->eureka_note);
        $this->assertNull($soloScarto->fresh()->eureka_note);
    }

    /**
     * Una risposta vuota non deve cancellare le note gia' salvate: puo'
     * voler dire "chiamata fallita" tanto quanto "nessuna nota".
     */
    public function test_does_not_wipe_eureka_notes_when_the_call_returns_nothing(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $customer = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Bortolo',
            'gestionale_code' => 3311, 'eureka_note' => 'PAGA RIVER CAFFE\' TREVISO',
        ]);

        Http::fake(['*' => Http::response([], 200)]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame("PAGA RIVER CAFFE' TREVISO", $customer->fresh()->eureka_note);
    }

    /**
     * Casi reali che hanno guidato il parsing dei telefoni in
     * GestionaleSyncRunner::mergeEurekaPhones() — vedi il commento sul
     * metodo per il ragionamento completo.
     */
    public function test_merges_new_phones_from_eureka_handling_ambiguous_dash_separator(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        // Trattino INTERNO a un numero solo (nessuno spazio nel blocco):
        // non va spezzato, e' un solo numero.
        $singleWithDash = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Single Dash', 'gestionale_code' => 30,
        ]);

        // Trattino che separa due numeri gia' completi (ognuno col proprio
        // prefisso, riconoscibile dallo spazio).
        $twoComplete = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Two Complete', 'gestionale_code' => 31,
        ]);

        // Trattino che separa un numero completo da un frammento in forma
        // abbreviata (eredita il prefisso del numero precedente).
        $sharedPrefix = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Shared Prefix', 'gestionale_code' => 32,
        ]);

        // Secondo numero troncato (senza prefisso, troppo corto anche dopo
        // la normalizzazione): va scartato, non salvato incompleto.
        $truncated = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Truncated', 'gestionale_code' => 33,
        ]);

        Http::fake([
            '*anagrafica/cerca*nome=Single+Dash*' => Http::response([[
                'id' => 30, 'rag_sociale_1' => 'SINGLE DASH', 'nr_telefono' => '0424-514008',
            ]], 200),
            '*anagrafica/cerca*nome=Two+Complete*' => Http::response([[
                'id' => 31, 'rag_sociale_1' => 'TWO COMPLETE', 'nr_telefono' => '041 5381522-041 921603',
            ]], 200),
            '*anagrafica/cerca*nome=Shared+Prefix*' => Http::response([[
                'id' => 32, 'rag_sociale_1' => 'SHARED PREFIX', 'nr_telefono' => '0722 629300-629355',
            ]], 200),
            '*anagrafica/cerca*nome=Truncated*' => Http::response([[
                'id' => 33, 'rag_sociale_1' => 'TRUNCATED', 'nr_telefono' => '0421 659329/0421 658',
            ]], 200),
            '*art_installati*' => Http::response([], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(['+390424514008'], $singleWithDash->fresh()->phones);
        $this->assertSame(['+390415381522', '+39041921603'], $twoComplete->fresh()->phones);
        $this->assertSame(['+390722629300', '+390722629355'], $sharedPrefix->fresh()->phones);
        $this->assertSame(
            ['+390421659329'],
            $truncated->fresh()->phones,
            'il secondo numero ("0421 658") e\' troppo corto/troncato: va scartato, non salvato incompleto'
        );
    }

    /**
     * Casi che guidano GestionaleSyncRunner::importInstalledMachines() —
     * dati modellati su quelli reali trovati in
     * docs/gestionale-eureka/macchinari.md (cliente id_codice_f15=238 con
     * addolcitore + fabbricatore ghiaccio). A differenza delle altre fasi di
     * questa classe, qui la creazione e' diretta (mai una proposta): dati a
     * basso rischio (articoli/matricole, non fatturazione).
     */
    public function test_creates_machine_unit_directly_when_installed_on_eureka_not_yet_in_crm(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Da Franco',
            'gestionale_code' => 238,
        ]);

        $product = Product::create([
            'sku' => 'FABBRGHIACCIO', 'type' => Product::TYPE_MACHINE, 'name' => 'Fabbricatore ghiaccio', 'gestionale_code' => 2662,
        ]);

        Http::fake([
            '*anagrafica/cerca*' => Http::response([], 200),
            '*art_installati*q=238*' => Http::response([
                ['id_codice_f15' => 238, 'id' => 2662, 'matricola' => 'CMD1012043', 'articolo' => 'FABBRGHIACCIO', 'desc_articolo_1' => 'FABBRICATORE DI GHIACCIO'],
            ], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $machineUnit = MachineUnit::first();
        $this->assertNotNull($machineUnit, 'la macchina va creata direttamente, non lasciata in coda');
        $this->assertSame('CMD1012043', $machineUnit->serial_number);
        $this->assertSame($product->id, $machineUnit->product_id, 'il prodotto va trovato per gestionale_code, gia\' esistente');
        $this->assertSame(MachineUnit::SOURCE_EUREKA, $machineUnit->source);
        $this->assertSame(MachineUnit::STATUS_INSTALLATA, $machineUnit->status);
        $this->assertSame($customer->id, $machineUnit->currentCustomer->id);
    }

    public function test_installed_machine_has_no_product_when_none_matches_by_gestionale_code(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Senza Prodotto', 'gestionale_code' => 70,
        ]);

        Http::fake([
            '*anagrafica/cerca*' => Http::response([], 200),
            '*art_installati*' => Http::response([
                ['id_codice_f15' => 70, 'id' => 4242, 'matricola' => 'NUOVA-01', 'articolo' => 'SCONOSCIUTO', 'desc_articolo_1' => 'ARTICOLO MAI VISTO'],
            ], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $machineUnit = MachineUnit::first();
        $this->assertNotNull($machineUnit);
        $this->assertNull($machineUnit->product_id, 'nessun prodotto locale con questo gestionale_code: non se ne crea uno da soli, per non inquinare il catalogo preventivi');
    }

    public function test_does_not_duplicate_installed_machine_when_serial_already_exists_in_crm(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Gia Censito', 'gestionale_code' => 50,
        ]);

        $product = Product::create(['sku' => 'BAV5', 'type' => Product::TYPE_MACHINE, 'name' => 'Addolcitore BAV5']);

        MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'B36414',
        ]);

        Http::fake([
            '*anagrafica/cerca*' => Http::response([], 200),
            '*art_installati*' => Http::response([
                ['id_codice_f15' => 50, 'id' => 999, 'matricola' => 'B36414', 'articolo' => 'BAV5', 'desc_articolo_1' => 'ADDOLCITORE BAV 5'],
            ], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(1, MachineUnit::count(), 'matricola gia\' presente nel CRM: nessun duplicato');
    }

    public function test_does_not_recreate_installed_machine_on_next_sync(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Ripetuto', 'gestionale_code' => 60,
        ]);

        Http::fake([
            '*anagrafica/cerca*' => Http::response([], 200),
            '*art_installati*' => Http::response([
                ['id_codice_f15' => 60, 'id' => 111, 'matricola' => 'RIPETUTA-01', 'articolo' => 'BAV5', 'desc_articolo_1' => 'ADDOLCITORE'],
            ], 200),
            // Catch-all esplicito: senza, con preventStrayRequests() attivo
            // le chiamate non pertinenti a questo test fallirebbero.
            '*' => Http::response([], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);
        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(1, MachineUnit::count(), 'stessa matricola trovata due sync di fila: nessun duplicato');
    }
}
