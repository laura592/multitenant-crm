<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Models\MunicipalityPostalCode;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class CustomerAddressLookupTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_selecting_a_municipality_fills_city_province_and_postal_code(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $milano = MunicipalityPostalCode::create([
            'municipality_name' => 'Milano',
            'province_name' => 'Milano',
            'province_code' => 'MI',
            'postal_code' => '20121',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['municipality_lookup' => $milano->id])
            ->assertFormSet([
                'city' => 'Milano',
                'province' => 'MI',
                'postal_code' => '20121',
            ]);
    }

    public function test_manual_address_fields_stay_editable_for_foreign_customers(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'company_name' => 'Client Estero SARL',
                'city' => 'Lugano',
                'province' => 'CH',
                'postal_code' => '6900',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }
}
