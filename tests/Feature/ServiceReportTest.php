<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\CreateServiceReport;
use App\Filament\Resources\ServiceReportResource\Pages\EditServiceReport;
use App\Mail\ServiceReportMail;
use App\Models\Customer;
use App\Models\Material;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportEmail;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class ServiceReportTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

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
        \Filament\Facades\Filament::setTenant($tenant);

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
        Livewire::test(\App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports::class)
            ->callTableAction('send', $report, data: ['recipient_email' => 'cliente@test.it']);

        Mail::assertSent(ServiceReportMail::class, fn ($mail) => $mail->hasTo('cliente@test.it'));
        $this->assertSame(1, ServiceReportEmail::where('service_report_id', $report->id)->count());
        $this->assertSame('inviato', $report->fresh()->status);
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
        \Filament\Facades\Filament::setTenant($tenant);

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
}
