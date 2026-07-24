<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteGroupResource;
use App\Models\Customer;
use App\Models\QuoteGroup;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class QuoteGroupPageTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_offer_edit_page_exposes_action_to_add_a_new_quote(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $group = QuoteGroup::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => 'bozza',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Admin',
            'email' => 'admin@example.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->get(QuoteGroupResource::getUrl('edit', ['record' => $group]))
            ->assertOk()
            ->assertSee('Panoramica offerta')
            ->assertSee('Aggiungi preventivo');
    }
}
