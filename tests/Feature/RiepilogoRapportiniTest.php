<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Http\Controllers\RiepilogoRapportiniController;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il riepilogo di un periodo, da stampare: cliente, chi paga, macchina e
 * articoli su una riga sola, in orizzontale e senza importi.
 */
class RiepilogoRapportiniTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function scenario(string $ruolo = 'amministrazione'): ServiceReport
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($utente, $tenant, $ruolo);

        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        // Chi paga davvero: un gestore terzo, diverso dal cliente presso cui
        // si e' intervenuti. E' la colonna che l'ufficio guarda per prima.
        $pagante = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Dersut Caffe SPA']);

        $prodotto = Product::create(['sku' => 'FAEMA-E98', 'type' => Product::TYPE_MACHINE, 'name' => 'Faema E98']);
        $macchina = MachineUnit::create([
            'tenant_id' => $tenant->id, 'product_id' => $prodotto->id, 'current_customer_id' => $cliente->id,
            'serial_number' => '1858049', 'model_name' => 'Faema E98',
        ]);
        $materiale = Material::create([
            'code' => 'CHIORD', 'source' => Material::SOURCE_EUREKA, 'tenant_id' => $tenant->id,
            'category' => 'Eureka', 'type' => 'Intervento', 'list_price' => 46.20,
        ]);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'billing_customer_id' => $pagante->id,
            'technician_id' => $utente->id, 'machine_unit_id' => $macchina->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => '2026-08-15',
        ]);
        ServiceReportMaterial::create([
            'service_report_id' => $report->id, 'material_id' => $materiale->id,
            'quantity' => 2, 'unit_cost_snapshot' => 46.20, 'line_total_snapshot' => 92.40,
        ]);

        $this->actingAs($utente);

        return $report;
    }

    private function riepilogo(ServiceReport $report): string
    {
        return view('pdf.riepilogo-rapportini', [
            'rapportini' => ServiceReport::with(['customer', 'billingCustomer', 'machineUnit', 'materialsUsed.material'])->get(),
            'da' => Carbon::parse('2026-08-01'),
            'a' => Carbon::parse('2026-08-31'),
            'tenant' => $report->tenant,
        ])->render();
    }

    public function test_il_riepilogo_copre_il_periodo_chiesto(): void
    {
        $this->scenario();

        $risposta = $this->get(route('service-reports.riepilogo', ['da' => '2026-08-01', 'a' => '2026-08-31']));

        $risposta->assertOk();
        $this->assertStringContainsString(
            'riepilogo-rapportini-2026-08-01_2026-08-31.pdf',
            $risposta->headers->get('content-disposition'),
        );
    }

    /** Le date invertite non devono dare un riepilogo vuoto. */
    public function test_le_date_invertite_si_raddrizzano(): void
    {
        $this->scenario();

        $risposta = $this->get(route('service-reports.riepilogo', ['da' => '2026-08-31', 'a' => '2026-08-01']));

        $risposta->assertOk();
        $this->assertStringContainsString('2026-08-01_2026-08-31', $risposta->headers->get('content-disposition'));
    }

    /** Una data malformata non deve dare un 500. */
    public function test_una_data_sbagliata_non_rompe_la_stampa(): void
    {
        $this->scenario();

        $this->get(route('service-reports.riepilogo', ['da' => 'pippo', 'a' => '2026-08-31']))->assertOk();
    }

    public function test_mostra_cliente_pagante_macchina_e_articoli(): void
    {
        $html = $this->riepilogo($this->scenario());

        $this->assertStringContainsString('Bar Centrale', $html);
        $this->assertStringContainsString('Dersut', $html, 'chi paga deve comparire');
        $this->assertStringContainsString('1858049', $html);
        // La quantita' si scrive solo quando e' diversa da 1.
        $this->assertStringContainsString('2', $html);
        $this->assertStringContainsString('CHIORD', $html);
    }

    /**
     * Senza importi per scelta dell'ufficio: il riepilogo dice COSA e' stato
     * fatto e su quale macchina, non quanto e' costato. Chi vuole i numeri li
     * ha dalle pagine contabili.
     */
    public function test_il_riepilogo_non_porta_importi(): void
    {
        $html = $this->riepilogo($this->scenario());

        $this->assertStringNotContainsString('92,40', $html);
        $this->assertStringNotContainsString('Importo', $html);
        $this->assertStringNotContainsString('Totale', $html);
    }

    /**
     * Lo staff master ha tenant_id nullo sull'utente e accede ai tenant dal
     * prefisso del pannello (/admin/alex/...). Filtrando sul tenant
     * dell'utente il riepilogo usciva VUOTO senza dire perche' — segnalato
     * dal vivo il 02/09/2026.
     */
    public function test_lo_staff_master_stampa_il_tenant_scelto(): void
    {
        $report = $this->scenario();
        $master = User::create([
            'tenant_id' => null, 'name' => 'Staff', 'email' => 'staff@alex.it',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);

        $this->actingAs($master)
            ->get(route('service-reports.riepilogo', [
                'da' => '2026-08-01', 'a' => '2026-08-31', 'tenant' => $report->tenant_id,
            ]))
            ->assertOk();

        // Cio' che conta e' QUALE tenant risolve: il peso del PDF non lo
        // direbbe, perche' anche un riepilogo vuoto pesa.
        $metodo = new \ReflectionMethod(RiepilogoRapportiniController::class, 'tenant');
        $metodo->setAccessible(true);
        $risolto = $metodo->invoke(new RiepilogoRapportiniController, Request::create('/', 'GET', [
            'tenant' => $report->tenant_id,
        ]));

        $this->assertSame($report->tenant_id, $risolto->id);
    }

    /** Un tenant a cui non si ha accesso non si stampa. */
    public function test_non_si_stampa_il_riepilogo_di_un_altro_tenant(): void
    {
        $this->scenario('dipendente');
        $altro = Tenant::create(['name' => 'Altro', 'slug' => 'altro']);

        $this->get(route('service-reports.riepilogo', [
            'da' => '2026-08-01', 'a' => '2026-08-31', 'tenant' => $altro->id,
        ]))->assertForbidden();
    }

    /**
     * Si apre in una scheda nuova: l'elenco resta dov'era, con i suoi filtri
     * e la sua pagina, mentre si guarda la stampa. openUrlInNewTab() non si
     * puo' usare perche' l'URL dipende dalle date, note solo al submit.
     */
    public function test_il_riepilogo_si_apre_in_una_scheda_nuova(): void
    {
        $report = $this->scenario('admin');
        Filament::setTenant($report->tenant);

        $componente = Livewire::test(ListServiceReports::class)
            ->callAction('riepilogo', ['da' => '2026-08-01', 'a' => '2026-08-31']);

        // JSON_UNESCAPED_SLASHES: senza, l'URL nel JSON e' "service-reports\/riepilogo"
        // e il confronto fallirebbe per le barre sfuggite, non per il codice.
        $effetti = json_encode($componente->effects, JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('window.open', $effetti, 'deve aprirsi in una scheda nuova');
        $this->assertStringContainsString('service-reports/riepilogo', $effetti);
        $this->assertStringContainsString('2026-08-01', $effetti);
    }
}
