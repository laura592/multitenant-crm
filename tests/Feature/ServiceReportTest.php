<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\EditServiceReport;
use App\Mail\ServiceReportMail;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\ServiceReportEmail;
use App\Models\Tenant;
use App\Models\User;
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
}
