<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il riepilogo di un periodo, da stampare: cliente, chi paga, macchina e
 * articoli su una riga sola, in orizzontale.
 */
class RiepilogoRapportiniTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function scenario(string $ruolo = 'amministrazione'): array
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

        return [$report, $utente];
    }

    public function test_il_riepilogo_copre_il_periodo_chiesto(): void
    {
        [$report] = $this->scenario();

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

    public function test_la_vista_mostra_cliente_pagante_macchina_e_articoli(): void
    {
        [$report] = $this->scenario();

        $html = view('pdf.riepilogo-rapportini', [
            'rapportini' => ServiceReport::with(['customer', 'billingCustomer', 'machineUnit', 'materialsUsed.material'])->get(),
            'da' => \Illuminate\Support\Carbon::parse('2026-08-01'),
            'a' => \Illuminate\Support\Carbon::parse('2026-08-31'),
            'showPrices' => true,
            'tenant' => $report->tenant,
        ])->render();

        $this->assertStringContainsString('Bar Centrale', $html);
        $this->assertStringContainsString('Dersut', $html, 'chi paga deve comparire');
        $this->assertStringContainsString('1858049', $html);
        $this->assertStringContainsString('2× CHIORD', $html, 'la quantita si scrive solo se diversa da 1');
        $this->assertStringContainsString('92,40', $html);
    }

    /** Senza permesso sui prezzi il riepilogo non porta importi. */
    public function test_al_dipendente_il_riepilogo_esce_senza_importi(): void
    {
        [$report] = $this->scenario('dipendente');

        $html = view('pdf.riepilogo-rapportini', [
            'rapportini' => ServiceReport::with(['customer', 'billingCustomer', 'machineUnit', 'materialsUsed.material'])->get(),
            'da' => \Illuminate\Support\Carbon::parse('2026-08-01'),
            'a' => \Illuminate\Support\Carbon::parse('2026-08-31'),
            'showPrices' => false,
            'tenant' => $report->tenant,
        ])->render();

        $this->assertStringNotContainsString('92,40', $html);
        $this->assertStringContainsString('CHIORD', $html, 'gli articoli restano, sparisce il costo');
    }
}
