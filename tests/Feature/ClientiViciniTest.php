<?php

namespace Tests\Feature;

use App\Filament\Pages\ClientiVicini;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class ClientiViciniTest extends TestCase
{
    use AssignsPermissionRoles;
    use RefreshDatabase;

    public function test_shows_nearby_customers_within_the_selected_radius(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tecnico Alex',
            'email' => 'tecnico@alex.test',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Cliente Vicino',
            'city' => 'Milano',
            'latitude' => 45.4643000,
            'longitude' => 9.1900000,
        ]);

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Cliente Lontano',
            'city' => 'Bergamo',
            'latitude' => 45.6983000,
            'longitude' => 9.6773000,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ClientiVicini::class)
            ->set('latitude', 45.4642000)
            ->set('longitude', 9.1901000)
            ->set('maxDistanceKm', 5)
            ->assertSee('Cliente Vicino')
            ->assertDontSee('Cliente Lontano');
    }

    public function test_nearby_markers_include_action_links_for_the_map_popup(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tecnico Alex',
            'email' => 'tecnico3@alex.test',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Cliente Popup',
            'street' => 'Via Torino 1',
            'city' => 'Milano',
            'latitude' => 45.4642500,
            'longitude' => 9.1900500,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        $component = Livewire::test(ClientiVicini::class)
            ->set('latitude', 45.4642000)
            ->set('longitude', 9.1901000);

        $markers = $component->instance()->nearbyCustomerMarkers();

        $this->assertCount(1, $markers);
        $this->assertSame("https://www.google.com/maps/search/?api=1&query={$customer->latitude},{$customer->longitude}", $markers[0]['mapsUrl']);
        $this->assertStringContainsString('/service-reports/create', $markers[0]['serviceReportUrl']);
    }

    public function test_falls_back_to_closest_customers_when_none_are_within_selected_radius(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tecnico Alex',
            'email' => 'tecnico4@alex.test',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Cliente A',
            'city' => 'Bologna',
            'latitude' => 44.4948870,
            'longitude' => 11.3426163,
        ]);

        Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Cliente B',
            'city' => 'Firenze',
            'latitude' => 43.7695604,
            'longitude' => 11.2558136,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        $component = Livewire::test(ClientiVicini::class)
            ->set('latitude', 45.4642000)
            ->set('longitude', 9.1901000)
            ->set('maxDistanceKm', 5);

        $rows = $component->instance()->getNearbyCustomers();

        $this->assertTrue($component->instance()->isUsingDistanceFallback());
        $this->assertCount(2, $rows);
        $this->assertGreaterThan(5, $rows->first()['distance']);
    }

    public function test_page_renders_the_new_hero_controls(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tecnico Alex',
            'email' => 'tecnico5@alex.test',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'dipendente');

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ClientiVicini::class)
            ->assertSee('Clienti vicini')
            ->assertSee('Trova la mia posizione')
            ->assertSee('Predefinito 5 km');
    }
}
