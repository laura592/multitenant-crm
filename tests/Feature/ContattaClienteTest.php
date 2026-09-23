<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\InformationRequestResource\Pages\EditInformationRequest;
use App\Filament\Resources\InformationRequestResource\Pages\ListInformationRequests;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PhoneNumber;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Il pulsante "Contatta" (21/09/2026): telefono, WhatsApp ed email del
 * cliente a un clic dall'elenco clienti e dalle richieste informazioni.
 * Nei preventivi no: li' non serve (23/09/2026).
 */
class ContattaClienteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $this->actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@alex.it',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]));
        Filament::setTenant($this->tenant);

        $this->cliente = Customer::create([
            'tenant_id' => $this->tenant->id,
            'company_name' => 'Bar Centrale',
            'emails' => ['bar@example.it'],
            'phones' => ['0421 123456', '347 1234567'],
        ]);
    }

    public function test_i_numeri_si_leggono_e_whatsapp_solo_sui_cellulari(): void
    {
        $this->assertSame('347 123 4567', PhoneNumber::display('+393471234567'));
        $this->assertSame('0421123456', PhoneNumber::display('+390421123456'));
        $this->assertSame('+41791234567', PhoneNumber::display('+41791234567'));
        $this->assertSame('393471234567', PhoneNumber::whatsapp('347 1234567'));
        $this->assertNull(PhoneNumber::whatsapp('0421 123456'));
    }

    public function test_nell_elenco_clienti_il_telefono_si_vede_e_si_chiama(): void
    {
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$this->cliente])
            ->assertSee('347 123 4567')
            ->assertTableActionVisible('chiama_0', $this->cliente)
            ->assertTableActionHasUrl('chiama_0', 'tel:+390421123456', $this->cliente)
            ->assertTableActionHasUrl('whatsapp', 'https://wa.me/393471234567', $this->cliente)
            ->assertTableActionHasUrl('email_0', 'mailto:bar@example.it', $this->cliente)
            ->assertTableActionHidden('email_1', $this->cliente);
    }

    public function test_senza_contatti_il_pulsante_non_c_e(): void
    {
        $muto = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Senza recapiti']);

        Livewire::test(ListCustomers::class)
            ->assertTableActionHidden('chiama_0', $muto)
            ->assertTableActionHidden('email_0', $muto);
    }

    public function test_dalla_richiesta_e_dal_cliente_si_contatta(): void
    {
        $richiesta = InformationRequest::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'request_details' => 'Macchina a noleggio', 'status' => 'nuova',
        ]);

        Livewire::test(ListInformationRequests::class)
            ->assertTableActionHasUrl('chiama_1', 'tel:+393471234567', $richiesta);

        Livewire::test(EditInformationRequest::class, ['record' => $richiesta->id])
            ->assertActionVisible('chiama_0')
            ->assertActionHasUrl('email_0', 'mailto:bar@example.it')
            ->assertSeeHtml('href="tel:+393471234567"');

        Livewire::test(ViewCustomer::class, ['record' => $this->cliente->id])
            ->assertActionHasUrl('chiama_0', 'tel:+390421123456');
    }
}
