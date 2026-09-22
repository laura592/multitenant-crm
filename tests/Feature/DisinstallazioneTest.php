<?php

namespace Tests\Feature;

use App\Console\Commands\ImportEurekaServiceReports;
use App\Filament\Resources\ServiceReportResource;
use App\Filament\Resources\ServiceReportResource\Pages\CreateServiceReport;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Tipo intervento "Disinstallazione" (22/09/2026).
 */
class DisinstallazioneTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_si_crea_un_rapportino_di_disinstallazione(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $tecnico = User::create(['tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($tecnico, $tenant, 'admin');
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Chiuso']);

        $this->actingAs($tecnico);
        Filament::setTenant($tenant);

        Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $cliente->id,
                'technician_id' => $tecnico->id,
                'intervention_type' => ServiceReport::TYPE_DISINSTALLAZIONE,
                'intervention_date' => today()->toDateString(),
                'work_performed' => 'Ritirata la macchina',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(ServiceReport::TYPE_DISINSTALLAZIONE, ServiceReport::sole()->intervention_type);
        $this->assertSame('Disinstallazione', ServiceReportResource::interventionTypeLabels()[ServiceReport::TYPE_DISINSTALLAZIONE]);
    }

    public function test_dall_import_eureka_una_disinstallazione_non_diventa_installazione(): void
    {
        $import = app(ImportEurekaServiceReports::class);
        $mappa = (new \ReflectionMethod($import, 'mapInterventionType'))->getClosure($import);

        $this->assertSame(ServiceReport::TYPE_DISINSTALLAZIONE, $mappa(['sl_lavorazione' => 'Disinstallazione macchina caffè']));
        $this->assertSame(ServiceReport::TYPE_INSTALLAZIONE, $mappa(['sl_lavorazione' => 'Installazione macchina']));
    }
}
