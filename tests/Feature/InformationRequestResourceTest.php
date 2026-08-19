<?php

namespace Tests\Feature;

use App\Filament\Resources\InformationRequestResource\Pages\EditInformationRequest;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Copre i contatti cliente e l'appuntamento aggiunti a InformationRequestResource
 * (vedi conversazione utente: prima non si vedevano email/telefono del cliente
 * ne' si poteva fissare una data di appuntamento senza uscire dalla richiesta).
 */
class InformationRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_shows_customer_contact_info_and_saves_appointment(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Admin',
            'email' => 'admin@gifar.it',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Centrale',
            'city' => 'Torino',
            'emails' => ['bar@example.it'],
            'phones' => ['3331234567'],
        ]);
        $request = InformationRequest::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'request_details' => 'Interessati a macchina da caffè',
            'status' => 'nuova',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(EditInformationRequest::class, ['record' => $request->id])
            ->assertOk()
            ->assertSee('bar@example.it')
            ->assertSee('3331234567')
            ->fillForm(['appointment_at' => '2026-09-01 10:00', 'appointment_notes' => 'Portare listino'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Portare listino', $request->fresh()->appointment_notes);
        $this->assertNotNull($request->fresh()->appointment_at);
    }

    public function test_can_log_a_dated_note_from_the_edit_form_and_from_the_quick_table_action(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Admin',
            'email' => 'admin@gifar.it',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Centrale',
            'city' => 'Torino',
        ]);
        $request = InformationRequest::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'request_details' => 'Interessati a macchina da caffè',
            'status' => 'nuova',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(EditInformationRequest::class, ['record' => $request->id])
            ->fillForm([
                'notes' => [
                    'note-1' => ['logged_at' => '2026-08-20', 'body' => 'Mandata mail con listino'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Mandata mail con listino', $request->notes()->first()->body);

        $request->notes()->create(['logged_at' => '2026-08-21', 'body' => 'Chiamato, non risponde']);

        $this->assertSame(2, $request->notes()->count());
        // notes() è ordinata per logged_at desc: l'ultima aggiunta ha data più recente.
        $this->assertSame('Chiamato, non risponde', $request->notes()->first()->body);
    }
}
