<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource;
use App\Filament\Resources\ProductResource;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Prodotti e macchinari avevano solo la pagina "edit" (a differenza di
 * Preventivi/Clienti, che hanno anche una vista di sola lettura): chi apriva
 * un record finiva sempre sul form completo modificabile. Aggiunta la
 * pagina "view" sul modello di QuoteResource/CustomerResource - qui si
 * verifica solo che le nuove rotte siano raggiungibili e che "edit" resti
 * accessibile direttamente (l'azione "Modifica" nell'header della view ci
 * porta).
 */
class ProductAndMachineUnitViewPageTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private function loginAdmin(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $tenant;
    }

    public function test_product_view_page_is_reachable_and_edit_stays_reachable(): void
    {
        $tenant = $this->loginAdmin();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'sku' => 'GIFAR-ICON-2GR',
            'type' => Product::TYPE_MACHINE,
            'name' => 'ICON 2GR',
            'gestionale_code' => 12345,
        ]);

        $this->get(ProductResource::getUrl('view', ['record' => $product]))
            ->assertOk()
            ->assertSee('ICON 2GR')
            ->assertSee('Modifica');

        $this->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk()
            ->assertSee('ICON 2GR');
    }

    public function test_machine_unit_view_page_is_reachable_and_edit_stays_reachable(): void
    {
        $tenant = $this->loginAdmin();
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'status' => MachineUnit::STATUS_INSTALLATA,
            'serial_number' => 'SN-VIEW-001',
            'model_name' => 'ICON 2GR',
        ]);

        $this->get(MachineUnitResource::getUrl('view', ['record' => $machine]))
            ->assertOk()
            ->assertSee('SN-VIEW-001')
            ->assertSee('Modifica');

        $this->get(MachineUnitResource::getUrl('edit', ['record' => $machine]))
            ->assertOk()
            ->assertSee('SN-VIEW-001');
    }
}
