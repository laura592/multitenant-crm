<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deadline;
use App\Models\LeaveRequest;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Ticket 4.1 (docs/roadmap-tickets.md, Epic 4): verifica per ciascuno dei
 * ruoli applicativi (dipendente, amministrazione, partner, admin) che le
 * capacita' concesse/negate su risorse diverse da Quote/preventivi
 * corrispondano esattamente a App\Support\RolePermissions::for(), che resta
 * l'unica fonte di verita' sia per il seeder che per i test. Copre sia
 * accessi consentiti sia operazioni vietate, cosi' le differenze tra ruoli
 * non dipendono solo da verifica manuale.
 *
 * Non tocca QuoteResource/Quote/EditQuote (di dominio di un'altra sessione
 * di lavoro in corso).
 */
class RolePermissionsTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
    }

    private function makeUser(string $role, string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => ucfirst($role),
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        $this->giveRole($user, $this->tenant, $role);

        return $user;
    }

    public function test_partner_can_manage_customers_but_not_delete_and_has_no_access_to_operational_resources(): void
    {
        $partner = $this->makeUser('partner', 'partner@gifar.it');
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Rossi']);

        // Consentito: catalogo clienti in view/create/update, ma niente delete.
        $this->assertTrue($partner->can('viewAny', Customer::class));
        $this->assertTrue($partner->can('view', $customer));
        $this->assertTrue($partner->can('create', Customer::class));
        $this->assertTrue($partner->can('update', $customer));
        $this->assertFalse($partner->can('delete', $customer));

        // Vietato: nessuna delle risorse "operative" e' assegnata al partner.
        $this->assertFalse($partner->can('viewAny', Material::class));
        $this->assertFalse($partner->can('viewAny', MaterialOrder::class));
        $this->assertFalse($partner->can('viewAny', TimeEntry::class));
        $this->assertFalse($partner->can('viewAny', LeaveRequest::class));
        $this->assertFalse($partner->can('viewAny', ServiceReport::class));
        $this->assertFalse($partner->can('viewAny', Deadline::class));
        $this->assertFalse($partner->can('viewAny', Vehicle::class));
    }

    public function test_dipendente_can_operate_day_to_day_but_not_manage_shared_catalog_or_delete_customers(): void
    {
        $dipendente = $this->makeUser('dipendente', 'dipendente@gifar.it');
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Rossi']);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto']);
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);
        $vehicle = Vehicle::create(['tenant_id' => $this->tenant->id, 'plate' => 'AB123CD']);
        $deadline = Deadline::create([
            'tenant_id' => $this->tenant->id,
            'deadlinable_type' => Vehicle::class,
            'deadlinable_id' => $vehicle->id,
            'type' => Deadline::TYPE_REVISIONE,
            'due_date' => now()->addMonth(),
        ]);

        // Consentito: puo' censire un cliente sul campo, ma non correggerlo/cancellarlo.
        $this->assertTrue($dipendente->can('create', Customer::class));
        $this->assertFalse($dipendente->can('update', $customer));
        $this->assertFalse($dipendente->can('delete', $customer));

        // Consentito: gestisce ordini materiali ma NON il catalogo condiviso.
        $this->assertTrue($dipendente->can('viewAny', Material::class));
        $this->assertFalse($dipendente->can('create', Material::class));
        $this->assertFalse($dipendente->can('update', $material));
        $this->assertTrue($dipendente->can('create', MaterialOrder::class));
        $this->assertTrue($dipendente->can('update', $order));
        $this->assertTrue($dipendente->can('delete', $order));

        // Consentito: gestisce ore/ferie proprie in view/create/update, ma non delete.
        $timeEntry = TimeEntry::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $dipendente->id,
            'clock_in' => now()->subHours(2), 'clock_out' => now(),
        ]);
        $leaveRequest = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $dipendente->id, 'type' => 'ferie',
            'date_from' => now()->addDays(10), 'date_to' => now()->addDays(14),
        ]);
        $this->assertTrue($dipendente->can('create', TimeEntry::class));
        $this->assertTrue($dipendente->can('create', LeaveRequest::class));
        $this->assertFalse($dipendente->can('delete', $timeEntry));
        $this->assertFalse($dipendente->can('delete', $leaveRequest));

        // Consentito: gestisce interamente i rapportini di intervento.
        $report = ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $dipendente->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Intervento di prova',
        ]);
        $this->assertTrue($dipendente->can('create', ServiceReport::class));
        $this->assertTrue($dipendente->can('delete', $report));

        // Vietato: scadenzario e parco veicoli sono roba da amministrazione, non da tecnici.
        $this->assertFalse($dipendente->can('viewAny', Deadline::class));
        $this->assertFalse($dipendente->can('create', Deadline::class));
        $this->assertFalse($dipendente->can('update', $deadline));
        $this->assertFalse($dipendente->can('viewAny', Vehicle::class));
        $this->assertFalse($dipendente->can('create', Vehicle::class));
        $this->assertFalse($dipendente->can('update', $vehicle));
    }

    public function test_amministrazione_sees_hr_data_and_can_correct_service_reports_but_not_create_or_manage_catalog(): void
    {
        $amministrazione = $this->makeUser('amministrazione', 'amministrazione@gifar.it');
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Rossi']);

        $technician = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico@gifar.it', 'password' => bcrypt('x'),
        ]);
        $report = ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $technician->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Intervento di prova',
        ]);

        // Consentito: profilo HR, vede clienti ma non li tocca.
        $this->assertTrue($amministrazione->can('viewAny', Customer::class));
        $this->assertFalse($amministrazione->can('create', Customer::class));
        $this->assertFalse($amministrazione->can('update', $customer));
        $this->assertFalse($amministrazione->can('delete', $customer));

        // Consentito: puo' correggere un rapportino esistente, ma non crearne uno nuovo
        // (li fanno solo i tecnici) ne' cancellarlo.
        $this->assertTrue($amministrazione->can('viewAny', ServiceReport::class));
        $this->assertTrue($amministrazione->can('update', $report));
        $this->assertFalse($amministrazione->can('create', ServiceReport::class));
        $this->assertFalse($amministrazione->can('delete', $report));

        // Consentito: vede/corregge ore e ferie di tutto il personale per il commercialista,
        // ma niente delete (resta a "responsabile"/admin).
        $timeEntry = TimeEntry::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $technician->id,
            'clock_in' => now()->subHours(2), 'clock_out' => now(),
        ]);
        $leaveRequest = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $technician->id, 'type' => 'ferie',
            'date_from' => now()->addDays(10), 'date_to' => now()->addDays(14),
        ]);
        $this->assertTrue($amministrazione->can('viewAny', TimeEntry::class));
        $this->assertTrue($amministrazione->can('create', TimeEntry::class));
        $this->assertFalse($amministrazione->can('delete', $timeEntry));
        $this->assertTrue($amministrazione->can('viewAny', LeaveRequest::class));
        $this->assertFalse($amministrazione->can('delete', $leaveRequest));

        // Vietato: nessun accesso a catalogo/magazzino.
        $this->assertFalse($amministrazione->can('viewAny', Material::class));
        $this->assertFalse($amministrazione->can('viewAny', MaterialOrder::class));

        // Consentito: gestisce scadenzario e parco veicoli (assicurazioni, revisioni, rinnovi).
        $vehicle = Vehicle::create(['tenant_id' => $this->tenant->id, 'plate' => 'AB123CD']);
        $deadline = Deadline::create([
            'tenant_id' => $this->tenant->id,
            'deadlinable_type' => Vehicle::class,
            'deadlinable_id' => $vehicle->id,
            'type' => Deadline::TYPE_REVISIONE,
            'due_date' => now()->addMonth(),
        ]);
        $this->assertTrue($amministrazione->can('viewAny', Deadline::class));
        $this->assertTrue($amministrazione->can('update', $deadline));
        $this->assertTrue($amministrazione->can('viewAny', Vehicle::class));
        $this->assertTrue($amministrazione->can('update', $vehicle));
    }

    public function test_admin_role_can_fully_manage_every_operational_resource(): void
    {
        $admin = $this->makeUser('admin', 'admin@gifar.it');
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Rossi']);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto']);
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);
        $vehicle = Vehicle::create(['tenant_id' => $this->tenant->id, 'plate' => 'AB123CD']);
        $deadline = Deadline::create([
            'tenant_id' => $this->tenant->id,
            'deadlinable_type' => Vehicle::class,
            'deadlinable_id' => $vehicle->id,
            'type' => Deadline::TYPE_REVISIONE,
            'due_date' => now()->addMonth(),
        ]);

        foreach ([
            [Customer::class, $customer],
            [Material::class, $material],
            [MaterialOrder::class, $order],
            [Deadline::class, $deadline],
            [Vehicle::class, $vehicle],
        ] as [$class, $record]) {
            $this->assertTrue($admin->can('viewAny', $class), "viewAny {$class}");
            $this->assertTrue($admin->can('create', $class), "create {$class}");
            $this->assertTrue($admin->can('update', $record), "update {$class}");
            $this->assertTrue($admin->can('delete', $record), "delete {$class}");
        }

        $timeEntry = TimeEntry::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $admin->id,
            'clock_in' => now()->subHours(2), 'clock_out' => now(),
        ]);
        $leaveRequest = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $admin->id, 'type' => 'ferie',
            'date_from' => now()->addDays(10), 'date_to' => now()->addDays(14),
        ]);
        $report = ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $admin->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
            'intervention_date' => now(),
            'work_performed' => 'Intervento di prova',
        ]);

        $this->assertTrue($admin->can('create', TimeEntry::class));
        $this->assertTrue($admin->can('delete', $timeEntry));
        $this->assertTrue($admin->can('create', LeaveRequest::class));
        $this->assertTrue($admin->can('delete', $leaveRequest));
        $this->assertTrue($admin->can('create', ServiceReport::class));
        $this->assertTrue($admin->can('delete', $report));

        // Unico ruolo (oltre a is_super_admin) che gestisce gli utenti.
        $this->assertTrue($admin->can('viewAny', User::class));
        $this->assertTrue($admin->can('create', User::class));
    }

    /**
     * Il ruolo "amministrazione" non era coperto da AllResourcesSmokeTest
     * (che copre solo admin/dipendente/partner/master admin):
     * verifica qui a livello HTTP che veda solo cio' che gli serve
     * (clienti in sola lettura, rapportini, presenze/ferie) e non il resto.
     */
    public function test_amministrazione_role_http_access_matches_its_permission_set(): void
    {
        $user = $this->makeUser('amministrazione', 'http-amministrazione@gifar.it');

        foreach (['customers', 'service-reports', 'time-entries', 'leave-requests', 'riepilogo-ore', 'deadlines', 'vehicles'] as $path) {
            $this->actingAs($user)->get("/admin/{$this->tenant->slug}/{$path}")->assertOk();
        }

        foreach ([
            'quotes', 'products', 'categories', 'brands', 'product-families',
            'materials', 'material-orders',
            'maintenance-schedules', 'payment-methods', 'information-requests', 'tenants',
        ] as $path) {
            $this->actingAs($user)->get("/admin/{$this->tenant->slug}/{$path}")->assertForbidden();
        }
    }
}
