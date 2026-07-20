<?php

namespace Tests\Feature;

use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Models\MunicipalityPostalCode;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantAddressLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_selecting_a_municipality_fills_city_province_and_postal_code(): void
    {
        $master = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $staff = User::create([
            'tenant_id' => null, 'is_super_admin' => true, 'name' => 'Staff Alex', 'email' => 'staff@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->actingAs($staff);
        Filament::setTenant($master);

        $roma = MunicipalityPostalCode::create([
            'municipality_name' => 'Roma',
            'province_name' => 'Roma',
            'province_code' => 'RM',
            'postal_code' => '00100',
        ]);

        Livewire::test(CreateTenant::class)
            ->fillForm(['municipality_lookup' => $roma->id])
            ->assertFormSet([
                'city' => 'Roma',
                'province' => 'RM',
                'postal_code' => '00100',
            ]);
    }
}
