<?php

namespace Tests\Feature;

use App\Mail\GestionaleSyncDigestMail;
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

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'gestionale_eureka_base_url' => 'https://alex.api.gestionale-eureka.it',
            'gestionale_eureka_username' => 'serviziorest',
            'gestionale_eureka_password' => 'secret',
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

        Http::fake([
            '*articoli/lista/UniqueModelXYZ*' => Http::response([[
                'id_eureka' => 555, 'codice' => 'UNIQUEMODEL', 'descr1' => 'MACCHINA UNICA',
            ]], 200),
            '*articoli/lista/XTClassic*' => Http::response([
                ['id_eureka' => 111, 'codice' => 'XTCLASSIC', 'descr1' => 'MACCHINA DALLA CORTE XT CLASSIC'],
                ['id_eureka' => 222, 'codice' => 'XTCLASSIC2', 'descr1' => 'ALTRA VARIANTE XT CLASSIC'],
            ], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(555, $unique->fresh()->gestionale_suggested_code);
        $this->assertSame('UNIQUEMODEL — MACCHINA UNICA', $unique->fresh()->gestionale_suggested_label);
        $this->assertNull($ambiguous->fresh()->gestionale_suggested_code, 'piu\' di un risultato: nessuna proposta automatica');
    }

    public function test_proposes_machine_unit_link_when_matricola_matches_on_eureka(): void
    {
        Mail::fake();

        $tenant = $this->makeTenant();

        $product = Product::create([
            'sku' => 'A600FM', 'type' => Product::TYPE_MACHINE, 'name' => 'A600 FM', 'gestionale_code' => 19339,
        ]);

        $matched = MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => '00113684',
        ]);

        $notFound = MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'INESISTENTE-001',
        ]);

        Http::fake([
            '*crm_api/m14/search*q=00113684*' => Http::response([
                'items' => [['id' => 1157, 'matricola' => '00113684', 'id_articolo_m10' => 19339, 'note' => null]],
                'total' => 1,
            ], 200),
            '*crm_api/m14/search*q=INESISTENTE-001*' => Http::response(['items' => [], 'total' => 0], 200),
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(1157, $matched->fresh()->gestionale_suggested_code);
        $this->assertSame('00113684', $matched->fresh()->gestionale_suggested_label);
        $this->assertNull($matched->fresh()->gestionale_code, 'la proposta non deve mai auto-assegnarsi');
        $this->assertNull($notFound->fresh()->gestionale_suggested_code);
    }

    public function test_does_not_propose_machine_unit_link_when_product_is_not_linked_to_eureka(): void
    {
        Mail::fake();
        Http::fake(); // il prodotto senza gestionale_code fara' comunque una ricerca articoli: risposta vuota di default

        $tenant = $this->makeTenant();

        $product = Product::create(['sku' => 'NOLINK', 'type' => Product::TYPE_MACHINE, 'name' => 'Senza collegamento']);

        $machineUnit = MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-001',
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'crm_api/m14/search'));
        $this->assertNull($machineUnit->fresh()->gestionale_suggested_code);
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
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);

        Mail::assertNothingSent();
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
        ]);

        $this->artisan('gestionale:sync')->assertExitCode(0);
        $this->artisan('gestionale:sync')->assertExitCode(0);

        $this->assertSame(1, MachineUnit::count(), 'stessa matricola trovata due sync di fila: nessun duplicato');
    }
}
