<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Regressione per il ticket di sicurezza 1.1: la route
 * /service-reports/{id}/pdf vive fuori dal pannello Filament, quindi lo
 * scope automatico tenant di BelongsToTenant non si applica (Filament::
 * getTenant() torna null in questo contesto). Senza un controllo esplicito
 * in ServiceReportPolicy::view(), qualunque utente autenticato con il
 * permesso di ruolo generico poteva scaricare il rapportino di un tenant
 * diverso dal proprio.
 */
class ServiceReportPdfCrossTenantTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private function makeReport(Tenant $tenant): ServiceReport
    {
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar '.$tenant->name]);
        $technician = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico-'.$tenant->slug.'@example.com', 'password' => bcrypt('x'),
        ]);

        return ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $technician->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Intervento di prova',
        ]);
    }

    public function test_user_can_download_pdf_of_a_report_belonging_to_their_own_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'U', 'email' => 'u@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $tenant, 'admin');
        $report = $this->makeReport($tenant);

        $this->actingAs($user)
            ->get(route('service-reports.pdf', $report))
            ->assertOk();
    }

    public function test_user_cannot_download_pdf_of_a_report_belonging_to_another_tenant(): void
    {
        $ownTenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner']);

        $user = User::create(['tenant_id' => $ownTenant->id, 'name' => 'U', 'email' => 'u@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $ownTenant, 'admin');

        $foreignReport = $this->makeReport($otherTenant);

        $this->actingAs($user)
            ->get(route('service-reports.pdf', $foreignReport))
            ->assertForbidden();
    }

    public function test_super_admin_can_download_pdf_of_any_tenant(): void
    {
        $masterTenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex-test']);
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner-2']);

        $staff = User::create([
            'tenant_id' => $masterTenant->id, 'name' => 'Staff', 'email' => 'staff@alex.it',
            'password' => bcrypt('x'), 'is_super_admin' => true,
        ]);
        $this->giveRole($staff, $masterTenant, 'admin');

        $foreignReport = $this->makeReport($otherTenant);

        $this->actingAs($staff)
            ->get(route('service-reports.pdf', $foreignReport))
            ->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $report = $this->makeReport($tenant);

        $this->get(route('service-reports.pdf', $report))
            ->assertRedirect(route('login'));
    }
}
