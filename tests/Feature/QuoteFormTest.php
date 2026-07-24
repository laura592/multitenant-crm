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

    /**
     * "Provvigione partner" e' nascosta ovunque su richiesta (poco chiara/
     * prematura cosi' com'e' gestita oggi) - vedi ->hidden() su
     * QuoteResource (form, infolist, colonna tabella). Il calcolo/i campi
     * restano nel modello per quando verra' riattivata, ma nessun ruolo
     * (partner o super admin) la vede piu' nell'interfaccia per ora.
     */
    public function test_commission_section_is_hidden_for_everyone_for_now(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar', 'default_commission_scenario' => 'A']);
        $partner = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($partner, $tenant, 'partner');

        $staff = User::create([
            'tenant_id' => null, 'is_super_admin' => true, 'name' => 'Staff Alex', 'email' => 'staff@alex.it', 'password' => bcrypt('password'),
        ]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $quote = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => now(),
            'commission_status' => 'da_fatturare',
        ]);

        $this->actingAs($partner);
        Filament::setTenant($tenant);
        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])->assertDontSee('Provvigione partner');

        $this->actingAs($staff);
        Filament::setTenant($tenant);
        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])->assertDontSee('Provvigione partner');

        $this->get(QuoteResource::getUrl('view', ['record' => $quote], tenant: $tenant))
            ->assertOk()
            ->assertDontSee('Provvigione partner');
    }
}
