<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RolePermissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chi stampa sceglie se il rapportino mostra i prezzi: la copia da lasciare
 * al cliente in cantiere spesso non deve dire quanto costa l'intervento,
 * quella per l'ufficio si'.
 *
 * Non riguarda il PDF allegato alla mail, che non ha prezzi mai e non passa
 * da questa route.
 */
class RapportinoPdfPrezziTest extends TestCase
{
    use RefreshDatabase;

    private function rapportino(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@alex.it',
            'password' => bcrypt('x'), 'is_super_admin' => true,
        ]);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale', 'type' => 'business',
        ]);
        $materiale = Material::create([
            'code' => 'CHIORD', 'source' => Material::SOURCE_EUREKA, 'tenant_id' => $tenant->id,
            'category' => 'Eureka', 'type' => 'INTERVENTO ORDINARIO', 'list_price' => 46.20,
        ]);
        $report = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'technician_id' => $utente->id,
            'number' => 'RT-2026-0001', 'intervention_date' => '2026-08-06',
            'source' => ServiceReport::SOURCE_MANUALE, 'status' => 'completato', 'intervention_type' => 'riparazione',
        ]);
        ServiceReportMaterial::create([
            'service_report_id' => $report->id, 'material_id' => $materiale->id,
            'quantity' => 1, 'unit_cost_snapshot' => 46.20, 'line_total_snapshot' => 46.20,
        ]);

        return [$utente, $report];
    }

    public function test_il_pdf_predefinito_mostra_i_prezzi(): void
    {
        [$utente, $report] = $this->rapportino();

        $risposta = $this->actingAs($utente)->get(route('service-reports.pdf', $report));

        $risposta->assertOk();
        $this->assertStringContainsString('rapportino-RT-2026-0001.pdf', $risposta->headers->get('content-disposition'));
    }

    public function test_si_puo_chiedere_la_copia_senza_prezzi(): void
    {
        [$utente, $report] = $this->rapportino();

        $risposta = $this->actingAs($utente)->get(route('service-reports.pdf', [$report, 'prezzi' => 0]));

        $risposta->assertOk();
        // Il nome del file distingue le due copie: con due stampe sulla
        // scrivania non si riconoscerebbero.
        $this->assertStringContainsString('rapportino-RT-2026-0001-senza-prezzi.pdf', $risposta->headers->get('content-disposition'));
    }
    /** Il nome del file non basta: i prezzi devono sparire dal documento. */
    public function test_la_copia_senza_prezzi_non_contiene_importi(): void
    {
        [, $report] = $this->rapportino();
        $report->load(['customer', 'technician', 'machineUnit.product', 'materialsUsed.material', 'tenant']);

        $conPrezzi = view('pdf.service-report', ['report' => $report, 'showPrices' => true])->render();
        $senzaPrezzi = view('pdf.service-report', ['report' => $report, 'showPrices' => false])->render();

        $this->assertStringContainsString('46,20', $conPrezzi);
        $this->assertStringNotContainsString('46,20', $senzaPrezzi);
        $this->assertStringNotContainsString('Prezzo unit.', $senzaPrezzi);

        // Il materiale resta: si toglie il costo, non l'intervento. Il PDF
        // stampa display_label, non il codice nudo.
        $this->assertStringContainsString('Ricambi/materiali utilizzati', $senzaPrezzi);
        $this->assertStringContainsString(
            e($report->materialsUsed->first()->material->display_label),
            $senzaPrezzi,
        );
    }

    /**
     * La regola dell'ufficio: i dipendenti non devono MAI far uscire un
     * rapportino con i prezzi. Nascondere il bottone non basta — la route sta
     * fuori dal pannello e chiunque puo' digitare ?prezzi=1 — quindi il
     * blocco si verifica sulla richiesta, non sull'interfaccia.
     */
    public function test_un_dipendente_non_ottiene_i_prezzi_nemmeno_chiedendoli(): void
    {
        [$utente, $report] = $this->rapportino();

        $utente->update(['is_super_admin' => false]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($utente->tenant_id);

        foreach (['view_service::report', 'view_any_service::report'] as $nome) {
            Permission::findOrCreate($nome, 'web');
        }

        $dipendente = Role::findOrCreate('dipendente', 'web');
        $dipendente->syncPermissions(['view_service::report', 'view_any_service::report']);
        $utente->assignRole($dipendente);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $risposta = $this->actingAs($utente)->get(route('service-reports.pdf', [$report, 'prezzi' => 1]));

        $risposta->assertOk();
        // Non gli si nega il rapportino: gli si nega il prezzo.
        $this->assertStringContainsString('-senza-prezzi.pdf', $risposta->headers->get('content-disposition'));
    }

    /** Il permesso non deve finire per sbaglio ai ruoli sbagliati. */
    public function test_solo_amministrazione_e_admin_possono_vedere_i_prezzi(): void
    {
        foreach (['dipendente', 'partner'] as $ruolo) {
            $this->assertNotContains('view_prices_service::report', RolePermissions::for($ruolo), $ruolo);
        }

        foreach (['amministrazione', 'admin', 'amministratore'] as $ruolo) {
            $this->assertContains('view_prices_service::report', RolePermissions::for($ruolo), $ruolo);
        }
    }

}
