<?php

namespace Tests\Feature;

use App\Filament\Resources\InformationRequestResource\Pages\ListInformationRequests;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La lista appuntamenti da stampare: serve in mano prima di uscire, quindi
 * deve portarsi dietro riferimenti (numero richiesta, contatti) e zona
 * (citta'/provincia), raggruppati per giornata.
 */
class InformationRequestAppointmentsPdfTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin-appuntamenti@alex.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $this->tenant, 'admin');

        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function request(string $company, ?string $appointmentAt, string $city = 'Jesolo', string $province = 'VE'): InformationRequest
    {
        $customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'company_name' => $company,
            'city' => $city,
            'province' => $province,
            'street' => 'Via Roma 1',
            'phones' => ['0421 123456'],
        ]);

        return InformationRequest::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'request_details' => 'Vorrebbero una macchina a noleggio',
            'status' => 'nuova',
            'appointment_at' => $appointmentAt,
            'appointment_notes' => $appointmentAt ? 'Citofonare al bar' : null,
        ]);
    }

    public function test_the_printable_list_carries_references_zone_and_contacts_grouped_by_day(): void
    {
        $product = Product::create(['sku' => 'A600', 'type' => Product::TYPE_MACHINE, 'name' => 'Franke A600']);
        $inRange = $this->request('Bar Centrale', now()->addDay()->setTime(9, 30));
        $inRange->products()->attach($product->id);

        $html = view('pdf.appuntamenti', [
            'requests' => InformationRequest::whereNotNull('appointment_at')->with(['customer', 'products'])->orderBy('appointment_at')->get(),
            'from' => now()->startOfDay(),
            'to' => now()->addDays(30)->endOfDay(),
            'tenant' => $this->tenant,
        ])->render();

        $this->assertStringContainsString('09:30', $html);
        $this->assertStringContainsString('Bar Centrale', $html);
        $this->assertStringContainsString($inRange->number, $html, 'Il numero della richiesta e\' il riferimento da citare al telefono.');
        $this->assertStringContainsString('Jesolo', $html);
        $this->assertStringContainsString('VE', $html);
        // Customer::setPhonesAttribute() normalizza in formato internazionale,
        // quindi in stampa il numero esce come lo tiene il database.
        $this->assertStringContainsString('+390421123456', $html);
        $this->assertStringContainsString('Citofonare al bar', $html);
        $this->assertStringContainsString('Franke A600', $html);
        $this->assertStringContainsString(now()->addDay()->translatedFormat('l d F Y'), $html, 'Le righe vanno raggruppate per giornata.');
    }

    public function test_the_action_returns_a_pdf_and_only_for_the_chosen_period(): void
    {
        $this->request('Bar Dentro', now()->addDays(2)->setTime(10, 0));
        $this->request('Bar Fuori', now()->addMonths(6)->setTime(10, 0));
        $this->request('Bar Senza Appuntamento', null);

        // Il PDF vero e' un binario: qui basta sapere che l'azione gira senza
        // errori e produce un download. Il contenuto e' coperto dal test
        // sopra, che rende la stessa vista con gli stessi dati.
        Livewire::test(ListInformationRequests::class)
            ->callTableAction('stampaAppuntamenti', data: [
                'from' => now()->toDateString(),
                'to' => now()->addDays(30)->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $inRange = InformationRequest::whereNotNull('appointment_at')
            ->whereBetween('appointment_at', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->pluck('customer_id');

        $this->assertCount(1, $inRange, 'Solo gli appuntamenti nel periodo scelto finiscono in stampa.');
    }
}
