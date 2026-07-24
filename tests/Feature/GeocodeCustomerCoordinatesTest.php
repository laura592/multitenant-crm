<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeCustomerCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_backfills_missing_customer_coordinates_from_address(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/search*' => Http::response([
                [
                    'lat' => '45.4642000',
                    'lon' => '9.1900000',
                ],
            ]),
        ]);

        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Centrale',
            'street' => 'Via Torino 1',
            'postal_code' => '20123',
            'city' => 'Milano',
            'province' => 'MI',
        ]);

        $this->artisan('customers:geocode-coordinates')
            ->expectsOutputToContain('Coordinate aggiornate: 1')
            ->assertSuccessful();

        $customer->refresh();

        $this->assertSame('45.4642000', $customer->latitude);
        $this->assertSame('9.1900000', $customer->longitude);
    }

    public function test_command_falls_back_to_city_level_geocoding_when_full_address_fails(): void
    {
        Http::fake(function ($request) {
            $query = $request['q'];

            if (str_contains($query, 'Via Sbagliata')) {
                return Http::response([]);
            }

            return Http::response([
                [
                    'lat' => '46.0679000',
                    'lon' => '11.1211000',
                ],
            ]);
        });

        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Cliente Fallback',
            'street' => 'Via Sbagliata 999',
            'postal_code' => '38122',
            'city' => 'Trento',
            'province' => 'TN',
        ]);

        $this->artisan('customers:geocode-coordinates')
            ->expectsOutputToContain('Coordinate aggiornate: 1')
            ->assertSuccessful();

        $customer->refresh();

        $this->assertSame('46.0679000', $customer->latitude);
        $this->assertSame('11.1211000', $customer->longitude);
    }
}
