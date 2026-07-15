<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il form di creazione preventivo deve restare minimale: righe prodotto e
 * provvigione sono dati da compilare DOPO (righe via wizard "Configura
 * macchina", provvigione da back-office), non campi da vedere/riempire mentre
 * si sta ancora creando il preventivo.
 */
class QuoteFormTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_create_page_hides_line_items_and_commission_sections(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar', 'default_commission_scenario' => 'A']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'partner');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $response = $this->get(QuoteResource::getUrl('create', tenant: $tenant));

        $response->assertOk();
        $response->assertDontSee('Righe preventivo');
        $response->assertDontSee('Provvigione partner');
    }

    public function test_partner_cannot_edit_commission_fields_but_can_see_them(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar', 'default_commission_scenario' => 'A']);
        $partner = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($partner, $tenant, 'partner');

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $quote = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => now(),
            'commission_status' => 'da_fatturare',
        ]);

        $this->actingAs($partner);
        Filament::setTenant($tenant);

        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])
            ->assertSee('Provvigione partner')
            ->fillForm(['commission_status' => 'pagata', 'commission_invoice_number' => 'FALSIFICATA-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $quote->refresh();
        $this->assertSame('da_fatturare', $quote->commission_status, 'Il partner non deve poter auto-certificarsi il pagamento della provvigione');
        $this->assertNull($quote->commission_invoice_number);
    }

    public function test_super_admin_can_edit_commission_fields(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar', 'default_commission_scenario' => 'A']);
        $staff = User::create([
            'tenant_id' => null, 'is_super_admin' => true, 'name' => 'Staff Alex', 'email' => 'staff@alex.it', 'password' => bcrypt('password'),
        ]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $quote = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => now(),
            'commission_status' => 'da_fatturare',
        ]);

        $this->actingAs($staff);
        Filament::setTenant($tenant);

        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])
            ->fillForm(['commission_status' => 'pagata', 'commission_invoice_number' => 'FT-2026-001'])
            ->call('save')
            ->assertHasNoFormErrors();

        $quote->refresh();
        $this->assertSame('pagata', $quote->commission_status);
        $this->assertSame('FT-2026-001', $quote->commission_invoice_number);
    }
}
