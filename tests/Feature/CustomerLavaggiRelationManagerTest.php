<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\CustomerResource\RelationManagers\LavaggiRelationManager;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class CustomerLavaggiRelationManagerTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_customer_view_page_loads_with_the_relation_manager_registered(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@alex.it', 'password' => bcrypt('password')]);
        $this->giveRole($user, $tenant, 'admin');

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Test Lavaggi']);
        MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'serial_number' => 'LAV-001',
            'model_name' => 'Impianto acqua',
        ]);

        $response = $this->actingAs($user)->get("/admin/{$tenant->slug}/customers/{$customer->id}");

        $response->assertOk();
        // I relation manager sono lazy-loaded via x-intersect: il markup della
        // tabella non e' nell'HTML iniziale (arriva via AJAX), ma la tab e'
        // gia' registrata e visibile (solo il contenuto arriva dopo).
        $response->assertSee('Storico lavaggi');
    }

    public function test_relation_manager_lists_lavaggi_with_billing_target(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@alex.it', 'password' => bcrypt('password')]);
        $this->giveRole($user, $tenant, 'admin');

        $payer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Pagante SRL']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Test Lavaggi', 'billing_customer_id' => $payer->id]);
        Lavaggio::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'data' => '2025-04-20',
            'descrizione' => 'Lavaggio impianto',
            'note' => '5VIE+AP',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(LavaggiRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
            ->assertSee('5VIE+AP')
            ->assertSee('Pagante SRL');
    }
}
