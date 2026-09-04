<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialOrderResource;
use App\Filament\Resources\MaterialOrderResource\Pages\ListMaterialOrders;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class MaterialOrderWorkflowTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $this->admin = User::create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'email' => 'a@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->admin, $this->tenant, 'admin');

        $this->actingAs($this->admin);
        Filament::setTenant($this->tenant);
    }

    public function test_duplicate_copies_supplier_and_items_but_not_notes(): void
    {
        $supplier = Supplier::create(['name' => 'John Guest']);
        $order = MaterialOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'notes' => 'Consegna urgente',
        ]);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto']);
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 3]);

        Livewire::test(ListMaterialOrders::class)
            ->callTableAction('duplicate', $order)
            ->assertHasNoTableActionErrors();

        $duplicate = MaterialOrder::where('id', '!=', $order->id)->firstOrFail();

        $this->assertNotSame($order->id, $duplicate->id);
        $this->assertNotSame($order->number, $duplicate->number);
        $this->assertNull($duplicate->notes);
        $this->assertSame($supplier->id, $duplicate->supplier_id);
        $this->assertSame(1, $duplicate->items()->count());
        $this->assertSame(3, $duplicate->items()->first()->quantity);
    }
}
