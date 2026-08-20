<?php

namespace Tests\Feature;

use App\Filament\Resources\MaintenanceScheduleResource\Pages\CreateMaintenanceSchedule;
use App\Filament\Resources\MaintenanceScheduleResource\Pages\EditMaintenanceSchedule;
use App\Filament\Resources\MaintenanceScheduleResource\RelationManagers\LavaggiRelationManager;
use App\Filament\Resources\ServiceReportResource\Pages\CreateServiceReport;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class MaintenanceScheduleLavaggioTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_saving_a_lavaggio_recalculates_the_linked_schedule_next_due_date(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => 20,
            'next_due_date' => now()->addMonth(),
        ]);

        $first = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(5),
            'descrizione' => '5 vie + apertura',
        ]);

        $schedule->refresh();
        $this->assertSame($first->id, $schedule->last_lavaggio_id);
        $this->assertTrue($schedule->next_due_date->isSameDay($first->data->copy()->addDays(20)));

        $second = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now(),
            'descrizione' => 'Chiusura stagionale',
        ]);

        $schedule->refresh();
        $this->assertSame($second->id, $schedule->last_lavaggio_id);
        $this->assertTrue($schedule->next_due_date->isSameDay($second->data->copy()->addDays(20)));

        $second->delete();

        $schedule->refresh();
        $this->assertSame($first->id, $schedule->last_lavaggio_id);
        $this->assertTrue($schedule->next_due_date->isSameDay($first->data->copy()->addDays(20)));
    }

    public function test_a_chiamata_schedule_without_frequency_still_tracks_last_lavaggio(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => null,
        ]);

        $lavaggio = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(5),
            'descrizione' => 'Lavaggio su chiamata',
        ]);

        $schedule->refresh();
        $this->assertSame($lavaggio->id, $schedule->last_lavaggio_id);
        $this->assertNull($schedule->next_due_date);
    }

    public function test_moving_a_lavaggio_to_another_schedule_recalculates_both(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customerA = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $customerB = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Stazione']);

        $scheduleA = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerA->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => 20,
            'next_due_date' => now()->addMonth(),
        ]);

        $scheduleB = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerB->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'frequency_days' => 30,
            'next_due_date' => now()->addMonth(),
        ]);

        $olderOnA = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerA->id,
            'maintenance_schedule_id' => $scheduleA->id,
            'data' => now()->subDays(15),
            'descrizione' => 'Lavaggio precedente su A',
        ]);

        $moved = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customerA->id,
            'maintenance_schedule_id' => $scheduleA->id,
            'data' => now(),
            'descrizione' => 'Lavaggio da spostare',
        ]);

        $scheduleA->refresh();
        $this->assertSame($moved->id, $scheduleA->last_lavaggio_id);

        // Sposta il lavaggio piu' recente sul piano del cliente B (es. errore
        // di inserimento corretto in edit).
        $moved->update([
            'customer_id' => $customerB->id,
            'maintenance_schedule_id' => $scheduleB->id,
        ]);

        $scheduleB->refresh();
        $this->assertSame($moved->id, $scheduleB->last_lavaggio_id);
        $this->assertTrue($scheduleB->next_due_date->isSameDay($moved->data->copy()->addDays(30)));

        // Il piano A non deve restare agganciato al lavaggio che non gli
        // appartiene piu': deve tornare a puntare all'unico lavaggio rimasto.
        $scheduleA->refresh();
        $this->assertSame($olderOnA->id, $scheduleA->last_lavaggio_id);
        $this->assertTrue($scheduleA->next_due_date->isSameDay($olderOnA->data->copy()->addDays(20)));
    }

    public function test_vino_has_no_standard_frequency_and_stays_a_chiamata(): void
    {
        $this->assertArrayNotHasKey(MaintenanceSchedule::BEVERAGE_VINO, MaintenanceSchedule::STANDARD_FREQUENCY_DAYS);

        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_VINO,
            'frequency_days' => null,
        ]);

        $lavaggio = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(5),
            'descrizione' => 'Lavaggio vino su chiamata',
        ]);

        $schedule->refresh();
        $this->assertSame($lavaggio->id, $schedule->last_lavaggio_id);
        $this->assertNull($schedule->next_due_date);
    }

    public function test_switching_beverage_type_to_vino_on_the_form_clears_frequency_days(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_BIRRA,
            'frequency_days' => 30,
            'next_due_date' => now()->addMonth(),
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        // Simula esattamente l'interazione utente sul form: cambiare
        // l'impianto da birra a vino deve azzerare la cadenza standard
        // ereditata, non lasciarla a 30 giorni.
        Livewire::test(EditMaintenanceSchedule::class, ['record' => $schedule->id])
            ->fillForm(['beverage_type' => MaintenanceSchedule::BEVERAGE_VINO])
            ->assertFormSet(['frequency_days' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($schedule->fresh()->frequency_days);
    }

    public function test_acqua_schedule_next_due_date_follows_frequency_days_like_other_beverages(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_ACQUA,
            'frequency_days' => 120,
        ]);

        $filterChange = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(10),
            'descrizione' => 'Sanificazione + cambio filtro',
            'filtro_sostituito' => true,
        ]);

        $schedule->refresh();
        // last_filter_change_id resta tracciato come informazione, ma non
        // guida piu' la scadenza: quella segue sempre frequency_days come
        // per gli altri beverage_type (vedi MaintenanceSchedule::recalculateLavaggioNextDue()).
        $this->assertSame($filterChange->id, $schedule->last_filter_change_id);
        $this->assertTrue($schedule->next_due_date->isSameDay($filterChange->data->copy()->addDays(120)));
    }

    public function test_acqua_schedule_without_frequency_days_has_no_next_due_date(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_ACQUA,
        ]);

        Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now()->subDays(10),
            'descrizione' => 'Sanificazione + cambio filtro',
            'filtro_sostituito' => true,
        ]);

        $schedule->refresh();
        $this->assertNull($schedule->next_due_date);
    }

    public function test_manutenzione_plan_can_be_created_on_a_machine_not_in_comodato(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        // Macchina di proprieta' del cliente, non in comodato: prima non era
        // selezionabile qui perche' il campo pescava solo da ComodatoMacchina.
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'status' => MachineUnit::STATUS_INSTALLATA,
            'serial_number' => 'SN-001',
            'model_name' => 'ICON 2GR',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(CreateMaintenanceSchedule::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'type' => MaintenanceSchedule::TYPE_MANUTENZIONE,
                'status' => MaintenanceSchedule::STATUS_ATTIVO,
                'machine_unit_id' => $machine->id,
                'frequency' => 'trimestrale',
                'next_due_date' => now()->addMonth()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $schedule = MaintenanceSchedule::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($machine->id, $schedule->machine_unit_id);
    }

    public function test_lavaggio_row_can_open_a_prefilled_rapportino_creation_url(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $lavaggio = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'data' => '2026-08-05',
            'descrizione' => '5 vie + apertura',
        ]);

        $url = LavaggiRelationManager::serviceReportCreateUrl($lavaggio);

        $this->assertStringContainsString('/service-reports/create?', $url);

        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        $this->assertSame($customer->id, $query['customer_id']);
        $this->assertSame('2026-08-05', $query['intervention_date']);
        $this->assertSame(ServiceReport::TYPE_SANIFICAZIONE, $query['intervention_type']);
        $this->assertSame('Lavaggio impianto', $query['problem_description']);
        $this->assertSame('5 Vie + Apertura', $query['work_performed']);
        $this->assertArrayNotHasKey('notes', $query);
    }

    /**
     * Bug segnalato dall'utente 2026-08-20: "Crea rapportino" da una riga
     * Lavaggio non la collegava al rapportino appena creato -
     * ServiceReport::syncGeneratedLavaggi() cercava per service_report_id
     * (ancora nullo sulla riga originale) e ne creava sempre una seconda,
     * lasciando due righe per la stessa visita. Vedi
     * CreateServiceReport::linkSourceLavaggio().
     */
    public function test_creating_a_rapportino_from_a_lavaggio_row_links_it_instead_of_duplicating(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $tech = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico Sei', 'email' => 'tech6@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($tech, $tenant, 'dipendente');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $schedule = MaintenanceSchedule::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => MaintenanceSchedule::BEVERAGE_BIRRA,
            'frequency_days' => 30,
        ]);

        $lavaggio = Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'maintenance_schedule_id' => $schedule->id,
            'data' => now(),
            'descrizione' => '5 vie + apertura',
            'lines_washed' => 5,
        ]);

        $this->actingAs($tech);
        Filament::setTenant($tenant);

        $url = LavaggiRelationManager::serviceReportCreateUrl($lavaggio);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $this->assertSame((string) $lavaggio->id, $query['lavaggio_id']);

        Livewire::withQueryParams($query)
            ->test(CreateServiceReport::class)
            ->fillForm(['technician_id' => $tech->id])
            ->call('create')
            ->assertHasNoFormErrors();

        // Una sola riga lavaggio per questo piano, non due: quella originale
        // (con note/vie lavate inserite a mano) va collegata al rapportino
        // appena creato invece di restare orfana accanto a un duplicato
        // generato automaticamente.
        $this->assertSame(1, Lavaggio::where('maintenance_schedule_id', $schedule->id)->count());

        $report = ServiceReport::firstOrFail();
        $lavaggio->refresh();
        $this->assertSame($report->id, $lavaggio->service_report_id);
        // Title Case: stessa normalizzazione automatica del modello vista
        // nell'altro test di questo file (query['work_performed'] sopra).
        $this->assertSame('5 Vie + Apertura', $lavaggio->descrizione);
        $this->assertSame(5, $lavaggio->lines_washed);
    }
}