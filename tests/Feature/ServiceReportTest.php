<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\CreateServiceReport;
use App\Filament\Resources\ServiceReportResource\Pages\EditServiceReport;
use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Mail\ServiceReportMail;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportEmail;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TariffeIntervento;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class ServiceReportTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_technician_can_create_report_with_signature_and_parts_then_send_it(): void
    {
        Storage::fake('public');
        Mail::fake();

        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Uno', 'email' => 'tech@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale', 'email' => 'bar@centrale.it']);
        $part = Product::create(['sku' => 'GUARNIZIONE', 'type' => Product::TYPE_OPTION, 'name' => 'Gruppo guarnizioni']);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Sostituzione guarnizioni gruppo erogazione',
        ]);
        $report->partsUsed()->create(['product_id' => $part->id, 'quantity' => 2]);

        $this->assertStringStartsWith('RT-', $report->number);
        $this->assertCount(1, $report->partsUsed);

        // firma: simulo un PNG 1x1 come data URL, come farebbe il canvas
        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        Livewire::test(EditServiceReport::class, ['record' => $report->getRouteKey()])
            ->fillForm(['customer_signature_path' => $tinyPng, 'status' => 'firmato'])
            ->call('save')
            ->assertHasNoFormErrors();

        $report->refresh();
        $this->assertNotNull($report->customer_signature_path);
        $this->assertStringStartsWith('signatures/', $report->customer_signature_path);
        Storage::disk('public')->assertExists($report->customer_signature_path);
        $this->assertSame('firmato', $report->status);

        // PDF scaricabile
        $this->get(route('service-reports.pdf', $report))->assertOk();

        // invio email dall'azione della tabella
        Livewire::test(ListServiceReports::class)
            ->callTableAction('send', $report, data: ['recipient_emails' => ['cliente@test.it']]);

        Mail::assertSent(ServiceReportMail::class, fn ($mail) => $mail->hasTo('cliente@test.it'));
        $this->assertSame(1, ServiceReportEmail::where('service_report_id', $report->id)->count());
        $this->assertSame('inviato', $report->fresh()->status);
    }

    /**
     * Bug segnalato 2026-08-20: l'anteprima email (Placeholder reattivo,
     * ->live() su custom_message) e l'invio vero renderizzano
     * ServiceReportMail da DENTRO un'azione/render Livewire — il compilatore
     * Blade avvolge allora ogni @if di footer-tenant.blade.php in commenti
     * di tracciamento <!--[if BLOCK]><![endif]--> (usati da Livewire per il
     * DOM diffing), che il convertitore testo di Laravel Mail non riconosce
     * come commenti e lascia visibili come testo grezzo. Vedi
     * App\Support\OutsideLivewireRender.
     */
    public function test_email_preview_does_not_leak_livewire_tracking_comments(): void
    {
        $tenant = Tenant::create([
            'name' => 'Gifar', 'slug' => 'gifar',
            'street' => 'Via Test 1', 'postal_code' => '30100', 'city' => 'Venezia',
            'tax_code' => '12345678901', 'iban' => 'IT00X0000000000000000000000',
            'phone' => '0421000000', 'email' => 'test@gifar.it',
        ]);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Sei', 'email' => 'tech6@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Sostituzione guarnizioni',
        ]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        $html = Livewire::test(ListServiceReports::class)
            ->mountTableAction('send', $report)
            ->html();

        // Il resto della pagina (bottoni, altri campi) e' Livewire vero e
        // ha LEGITTIMAMENTE questi marcatori nel proprio HTML grezzo — normali
        // li', invisibili in un DOM reale. Qui si controlla solo il contenuto
        // dell'iframe (l'email), che e' HTML-escaped dentro l'attributo
        // srcdoc: un marcatore leaked ci comparirebbe come "&lt;!--[if
        // BLOCK]&gt;", una stringa che non puo' comparire per nessun altro
        // motivo sulla pagina.
        $this->assertStringNotContainsString('&lt;!--[if BLOCK]&gt;', $html);
        $this->assertStringNotContainsString('&lt;!--[if ENDBLOCK]&gt;', $html);
    }

    /**
     * La seconda scheda del modale "Invia" mostra il rapportino allegato.
     * Deve puntare alla copia SENZA prezzi: e' l'unica che il cliente
     * riceve, e un'anteprima con i prezzi farebbe credere il contrario a
     * chi sta per spedire.
     */
    public function test_send_modal_previews_the_attached_report_without_prices(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Sette', 'email' => 'tech7@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Sostituzione guarnizioni',
        ]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        $html = Livewire::test(ListServiceReports::class)
            ->mountTableAction('send', $report)
            ->html();

        $this->assertStringContainsString(
            e(route('service-reports.pdf', [$report, 'prezzi' => 0])),
            $html,
        );
        $this->assertStringNotContainsString(
            e(route('service-reports.pdf', [$report, 'prezzi' => 1])),
            $html,
        );
    }

    /**
     * I ricambi/materiali del rapportino pescano da Material, non piu' da
     * Product (quest'ultimo resta per il "Modello macchina" e per il
     * catalogo preventivi) — vedi ServiceReportResource, sezione
     * "Ricambi/materiali utilizzati".
     */
    public function test_technician_can_add_a_material_as_ricambio_via_the_form(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Due', 'email' => 'tech2@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $material = Material::create(['tenant_id' => $tenant->id, 'code' => 'GUARN-01', 'category' => 'Ricambi', 'type' => 'Guarnizione gruppo']);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Sostituzione guarnizioni',
        ]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        Livewire::test(EditServiceReport::class, ['record' => $report->getRouteKey()])
            ->fillForm([
                'materialsUsed' => [
                    ['material_id' => $material->id, 'quantity' => 3],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $report->refresh();
        $this->assertCount(1, $report->materialsUsed);
        $this->assertSame($material->id, $report->materialsUsed->first()->material_id);
        $this->assertSame('3.00', $report->materialsUsed->first()->quantity);
    }

    public function test_create_page_prefills_from_lavaggio_context(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Tre', 'email' => 'tech3@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'serial_number' => 'ABC123',
            'model_name' => 'Macchina 1',
        ]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        Livewire::withQueryParams([
            'customer_id' => $customer->id,
            'machine_unit_id' => $machine->id,
            'intervention_date' => '2026-08-05',
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'problem_description' => 'Lavaggio impianto',
            'work_performed' => '5 vie + apertura',
            'notes' => 'Filtro sostituito',
        ])
            ->test(CreateServiceReport::class)
            ->assertFormSet(function (array $state) use ($customer, $machine): array {
                return [
                    'customer_id' => $customer->id,
                    'machine_unit_id' => $machine->id,
                    'intervention_date' => fn ($value) => str_starts_with((string) $value, '2026-08-05'),
                    'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
                    'problem_description' => 'Lavaggio impianto',
                    'work_performed' => '5 vie + apertura',
                    'notes' => 'Filtro sostituito',
                ];
            });
    }

    /**
     * Il campo "Impianti e vie lavate" (Repeater, non ->relationship(): vedi
     * ServiceReportResource::syncLavaggioImpianti()) deve collegare
     * esplicitamente i piani scelti e scrivere le vie indicate riga per riga
     * direttamente sulle righe Lavaggio generate da
     * ServiceReport::syncGeneratedLavaggi() per ogni impianto — niente
     * colonna pivot dedicata.
     */
    public function test_create_page_saves_lavaggio_impianti_lines_washed_on_generated_lavaggi(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Cinque', 'email' => 'tech5@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $birra = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_BIRRA,
            'lines_count' => 2,
        ]);
        $vino = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_VINO,
            'lines_count' => 5,
        ]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'technician_id' => $tech->id,
                'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
                'intervention_date' => '2026-08-20',
                'problem_description' => 'Lavaggio impianto',
                'work_performed' => 'Lavaggio impianto',
                'lavaggio_impianti' => [
                    ['maintenance_schedule_id' => $birra->id, 'lines_washed' => 2],
                    ['maintenance_schedule_id' => $vino->id, 'lines_washed' => 3],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = ServiceReport::where('customer_id', $customer->id)->latest()->first();
        $rows = Lavaggio::where('service_report_id', $report->id)->get()->keyBy('maintenance_schedule_id');

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows->get($birra->id)->lines_washed);
        $this->assertSame(3, $rows->get($vino->id)->lines_washed);
    }

    /**
     * Le vie si scrivono in cima al rapportino, in "Impianti e vie lavate":
     * arrivati in fondo, il lavaggio deve gia' risultare eseguito e le sue
     * voci da fatturare gia' in elenco (qui 2 + 3 = 5 vie => LAV2 qty 1 +
     * ULTERIORE VIA LAVATA qty 3), senza ridigitare lo stesso numero nel
     * riquadro dei ricambi. Vedi LavaggioFields::syncVieDaImpianti().
     */
    public function test_vie_degli_impianti_accendono_lavaggio_e_righe_tariffa(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Otto', 'email' => 'tech8@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $lav2 = Material::create(['tenant_id' => $tenant->id, 'code' => 'LAV2', 'category' => 'Eureka', 'type' => 'LAVAGGIO 2 VIE']);
        $ultVia = Material::create(['tenant_id' => $tenant->id, 'code' => 'ULTVIA', 'category' => 'Eureka', 'type' => 'ULTERIORE VIA LAVATA']);

        $birra = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_BIRRA,
            'lines_count' => 2,
        ]);
        $vino = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_VINO,
            'lines_count' => 3,
        ]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        $livewire = Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'technician_id' => $tech->id,
                'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE,
                'intervention_date' => now(),
                'work_performed' => 'Lavaggio impianti birra e vino',
            ])
            // Le righe si idratano con fillForm() (equivale ad averle gia'
            // aggiunte), poi le vie si scrivono una alla volta sul path
            // annidato del singolo campo: e' esattamente quello che manda il
            // browser quando il tecnico digita dentro una riga, e cosi' si
            // verifica che il rimbalzo dal campo figlio al repeater ci sia
            // davvero (con ->set() sull'intero array non si vedrebbe).
            ->fillForm([
                'lavaggio_impianti' => [
                    'riga-birra' => ['maintenance_schedule_id' => $birra->id, 'lines_washed' => null],
                    'riga-vino' => ['maintenance_schedule_id' => $vino->id, 'lines_washed' => null],
                ],
            ])
            ->set('data.lavaggio_impianti.riga-birra.lines_washed', 2)
            ->set('data.lavaggio_impianti.riga-vino.lines_washed', 3);

        $this->assertTrue((bool) $livewire->get('data._lavaggio_vie_eseguito'));
        $this->assertSame(5, (int) $livewire->get('data.lavaggio_vie_count'));

        $materialsUsed = collect($livewire->get('data.materialsUsed'));
        $this->assertCount(2, $materialsUsed);
        $this->assertSame(1, (int) $materialsUsed->firstWhere('material_id', $lav2->id)['quantity']);
        $this->assertSame(3, (int) $materialsUsed->firstWhere('material_id', $ultVia->id)['quantity']);
    }

    /**
     * "LAVAGGIO 2 VIE" e' la tariffa minima gia' agevolata per i tecnici,
     * dovuta anche lavando una sola via: il widget "Lavaggio vie" del form
     * deve aggiungere sempre quella riga con quantita' 1, piu' "ULTERIORE VIA
     * LAVATA" solo per le vie oltre la seconda (qui 4 vie => qty 2). Vedi
     * ServiceReportResource::syncLavaggioViaMaterials().
     */
    public function test_lavaggio_vie_widget_generates_base_and_ulteriore_via_rows(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Quattro', 'email' => 'tech4@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $lav2 = Material::create(['tenant_id' => $tenant->id, 'code' => 'LAV2', 'category' => 'Eureka', 'type' => 'LAVAGGIO 2 VIE']);
        $ultVia = Material::create(['tenant_id' => $tenant->id, 'code' => 'ULTVIA', 'category' => 'Eureka', 'type' => 'ULTERIORE VIA LAVATA']);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        $livewire = Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'technician_id' => $tech->id,
                'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
                'intervention_date' => now(),
                'work_performed' => 'Lavaggio impianto 4 vie',
            ])
            ->set('data._lavaggio_vie_eseguito', true)
            ->set('data.lavaggio_vie_count', 4);

        $materialsUsed = collect($livewire->get('data.materialsUsed'));

        $this->assertCount(2, $materialsUsed);
        $this->assertSame(1, (int) $materialsUsed->firstWhere('material_id', $lav2->id)['quantity']);
        $this->assertSame(2, (int) $materialsUsed->firstWhere('material_id', $ultVia->id)['quantity']);

        // Riducendo a 2 vie, la riga "ulteriore via" deve sparire da sola.
        $livewire->set('data.lavaggio_vie_count', 2);
        $materialsUsed = collect($livewire->get('data.materialsUsed'));
        $this->assertCount(1, $materialsUsed);
        $this->assertSame(1, (int) $materialsUsed->firstWhere('material_id', $lav2->id)['quantity']);
    }

    /**
     * Lavando una sola via il rapportino genera esattamente le stesse righe
     * di due vie (solo LAV2, nessun ULTVIA: la tariffa minima e' comunque
     * quella): riaprendolo, "Numero vie lavate" deve pero' rileggere 1, il
     * numero che il tecnico ha digitato, non 2 ricavato all'indietro dalle
     * righe. Per questo il campo e' una colonna vera
     * (service_reports.lavaggio_vie_count) e non un campo di comodo come i
     * toggle accanto.
     */
    public function test_lavaggio_vie_count_di_una_via_sopravvive_al_salvataggio(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Sette', 'email' => 'tech7@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $lav2 = Material::create(['tenant_id' => $tenant->id, 'code' => 'LAV2', 'category' => 'Eureka', 'type' => 'LAVAGGIO 2 VIE']);
        Material::create(['tenant_id' => $tenant->id, 'code' => 'ULTVIA', 'category' => 'Eureka', 'type' => 'ULTERIORE VIA LAVATA']);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'technician_id' => $tech->id,
                'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
                'intervention_date' => now(),
                'work_performed' => 'Lavaggio impianto 1 via',
            ])
            ->set('data._lavaggio_vie_eseguito', true)
            ->set('data.lavaggio_vie_count', 1)
            ->call('create')
            ->assertHasNoFormErrors();

        $report = ServiceReport::where('customer_id', $customer->id)->latest()->first();

        $this->assertSame(1, $report->lavaggio_vie_count);

        // Le righe materiali (e quindi il prezzo) restano quelle di sempre:
        // un solo LAVAGGIO 2 VIE, niente ULTERIORE VIA LAVATA.
        $righe = $report->materialsUsed()->get();
        $this->assertCount(1, $righe);
        $this->assertSame($lav2->id, $righe->first()->material_id);

        Livewire::test(EditServiceReport::class, ['record' => $report->getRouteKey()])
            ->assertFormSet([
                '_lavaggio_vie_eseguito' => true,
                'lavaggio_vie_count' => 1,
            ]);
    }

    /**
     * Riaprendo in modifica un rapportino che ha gia' CHIORD (chiamata) e
     * LAV2 (lavaggio, senza ULTVIA) in elenco — es. importato da Eureka — i
     * due toggle "Chiamata"/"Lavaggio eseguito" devono partire gia' accesi,
     * non spenti: altrimenti sembrano "rotti" pur essendo le righe li'.
     * Qui service_reports.lavaggio_vie_count e' vuota (rapportino nato prima
     * di quella colonna), quindi scatta il ripiego calcolato dalle righe:
     * "Numero vie lavate" legge 2 (il valore nominale della tariffa LAV2),
     * non 1, perche' da LAV2 da solo non si puo' distinguere "1 via con
     * tariffa minima agevolata" da "2 vie" e 2 e' quello che il codice
     * materiale dichiara letteralmente. Su un rapportino salvato dal form,
     * invece, vince sempre il numero digitato — vedi il test
     * test_lavaggio_vie_count_di_una_via_sopravvive_al_salvataggio. Vedi
     * ServiceReportResource::resolveLavaggioShortcutDefaults().
     */
    public function test_edit_page_prefills_lavaggio_and_chiamata_toggles_from_existing_materials(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Cinque', 'email' => 'tech5@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale', 'city' => 'Mestre']);
        $chiord = Material::create(['tenant_id' => $tenant->id, 'code' => 'CHIORD', 'category' => 'Eureka', 'type' => 'CHIAMATA']);
        $lav2 = Material::create(['tenant_id' => $tenant->id, 'code' => 'LAV2', 'category' => 'Eureka', 'type' => 'LAVAGGIO 2 VIE']);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Lavaggio impianto',
        ]);
        $report->materialsUsed()->create(['material_id' => $chiord->id, 'quantity' => 1]);
        $report->materialsUsed()->create(['material_id' => $lav2->id, 'quantity' => 1]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        Livewire::test(EditServiceReport::class, ['record' => $report->getRouteKey()])
            ->assertFormSet([
                'add_chiamata_material' => true,
                '_lavaggio_vie_eseguito' => true,
                'lavaggio_vie_count' => 2,
            ]);
    }

    /**
     * Stesso test di sopra ma con ULTVIA gia' presente (qty 2, es. 4 vie
     * lavate): "Numero vie lavate" deve leggere 4, non 3 — la formula
     * inversa e' vie = 2 (LAV2) + qty ULTVIA, l'esatto contrario di
     * syncLavaggioViaMaterials() che scrive qty ULTVIA = vie - 2.
     */
    public function test_edit_page_prefills_vie_count_from_existing_ulteriore_via_quantity(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Sei', 'email' => 'tech6@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $lav2 = Material::create(['tenant_id' => $tenant->id, 'code' => 'LAV2', 'category' => 'Eureka', 'type' => 'LAVAGGIO 2 VIE']);
        $ultVia = Material::create(['tenant_id' => $tenant->id, 'code' => 'ULTVIA', 'category' => 'Eureka', 'type' => 'ULTERIORE VIA LAVATA']);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Lavaggio impianto 4 vie',
        ]);
        $report->materialsUsed()->create(['material_id' => $lav2->id, 'quantity' => 1]);
        $report->materialsUsed()->create(['material_id' => $ultVia->id, 'quantity' => 2]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        Livewire::test(EditServiceReport::class, ['record' => $report->getRouteKey()])
            ->assertFormSet([
                '_lavaggio_vie_eseguito' => true,
                'lavaggio_vie_count' => 4,
            ]);
    }

    /**
     * I paganti con listino proprio (Martellozzo, Spigola, Goppion…) hanno
     * codici dedicati per chiamata, manodopera e lavaggio: prima le
     * scorciatoie inserivano sempre quelli standard e chi compilava doveva
     * ricordarsi di sostituirli, cosa che nei fatti non succedeva.
     */
    public function test_tariff_codes_follow_the_paying_customer(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $martellozzo = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Martellozzo Lorenzo & C. SAS',
            'gestionale_code' => 1178,
        ]);
        $spigola = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Spigola SRL', 'gestionale_code' => 1629,
        ]);

        // cliente di Martellozzo, fuori Venezia: prima prendeva CHIORD
        $bar = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar del Lido', 'city' => 'Jesolo',
            'billing_customer_id' => $martellozzo->id,
        ]);
        $tariffe = TariffeIntervento::per($bar);
        $this->assertSame('CHIMART', $tariffe['chiamata']);
        $this->assertSame('OREMART', $tariffe['manodopera']);
        $this->assertSame('LAVMART', $tariffe['lavaggio']);
        $this->assertSame('ULTVIAMART', $tariffe['lavaggio_ulteriore_via']);
        $this->assertSame('CHIFEMART', TariffeIntervento::per($bar, true)['chiamata']);

        // Spigola: chiamata CHIVE ovunque, anche senza citta' in anagrafica
        $senzaCitta = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Hotel Senza Citta',
            'billing_customer_id' => $spigola->id,
        ]);
        $this->assertSame('CHIVE', TariffeIntervento::per($senzaCitta)['chiamata']);
        $this->assertSame('ORESPIGOLA', TariffeIntervento::per($senzaCitta)['manodopera']);

        // nessun pagante: restano i codici di sempre, legati alla citta'
        $diretto = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Caffe Diretto', 'city' => 'Venezia',
        ]);
        $this->assertSame('CHIVE', TariffeIntervento::per($diretto)['chiamata']);
        $this->assertSame('ORE', TariffeIntervento::per($diretto)['manodopera']);
        $this->assertSame('LAV2', TariffeIntervento::per($diretto)['lavaggio']);

        $fuori = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Caffe Mestre', 'city' => 'Mestre-Venezia',
        ]);
        $this->assertSame('CHIORD', TariffeIntervento::per($fuori)['chiamata']);
        $this->assertSame('OREFEST', TariffeIntervento::per($fuori, true)['manodopera']);
    }
}
