<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialOrderResource;
use App\Filament\Resources\MaterialOrderResource\RelationManagers\ItemsRelationManager;
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

class MaterialOrderSupplierTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private Tenant $tenant;

    private User $admin;

    private User $dipendente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        $this->admin = User::create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'email' => 'a@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->admin, $this->tenant, 'admin');

        $this->dipendente = User::create(['tenant_id' => $this->tenant->id, 'name' => 'D', 'email' => 'd@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->dipendente, $this->tenant, 'dipendente');

        $this->actingAs($this->admin);
        Filament::setTenant($this->tenant);
    }

    public function test_material_can_be_assigned_to_a_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'John Guest']);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'supplier_id' => $supplier->id]);

        $this->assertTrue($material->fresh()->supplier->is($supplier));
        $this->assertTrue($supplier->fresh()->materials->contains($material));
    }

    public function test_dipendente_can_manage_orders_but_not_the_material_catalog(): void
    {
        // Root cause del problema segnalato dall'utente: material::order e'
        // MANAGE per dipendente, ma material resta VIEW-only (deciso cosi'
        // esplicitamente: solo l'admin gestisce il catalogo condiviso).
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto']);

        $this->assertTrue($this->dipendente->can('create', MaterialOrder::class));
        $this->assertFalse($this->dipendente->can('create', Material::class));
        $this->assertFalse($this->dipendente->can('update', $material));
        $this->assertFalse($this->dipendente->can('delete', $material));

        $this->assertTrue($this->admin->can('create', Material::class));
        $this->assertTrue($this->admin->can('viewAny', Supplier::class));
        $this->assertTrue($this->dipendente->can('viewAny', Supplier::class));
    }

    public function test_pdf_shows_supplier_block_only_when_assigned(): void
    {
        $supplier = Supplier::create(['name' => 'John Guest', 'city' => 'Milano', 'phone' => '02 1234567']);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'supplier_id' => $supplier->id]);

        $orderWithSupplier = MaterialOrder::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id]);
        MaterialOrderResource::addSelectedMaterialsToOrder($orderWithSupplier, [$material->id => 2]);

        $html = view('pdf.ordine-materiali', [
            'rows' => $orderWithSupplier->fresh()->items->load('material')->map(fn ($item) => ['material' => $item->material, 'quantity' => $item->quantity]),
            'notes' => null,
            'tenant' => $this->tenant,
            'supplier' => $orderWithSupplier->supplier,
            'number' => $orderWithSupplier->number,
            'date' => $orderWithSupplier->created_at,
        ])->render();

        $this->assertStringContainsString('Spett.le', $html);
        $this->assertStringContainsString('John Guest', $html);

        $orderWithoutSupplier = MaterialOrder::create(['tenant_id' => $this->tenant->id]);

        $htmlNoSupplier = view('pdf.ordine-materiali', [
            'rows' => collect(),
            'notes' => null,
            'tenant' => $this->tenant,
            'supplier' => $orderWithoutSupplier->supplier,
            'number' => $orderWithoutSupplier->number,
            'date' => $orderWithoutSupplier->created_at,
        ])->render();

        $this->assertStringNotContainsString('Spett.le', $htmlNoSupplier);
    }

    public function test_relation_manager_flags_items_from_a_different_supplier(): void
    {
        $johnGuest = Supplier::create(['name' => 'John Guest']);
        $altro = Supplier::create(['name' => 'Altro Fornitore']);

        $matching = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'supplier_id' => $johnGuest->id]);
        $mismatched = Material::create(['code' => 'XX9999', 'category' => 'Raccordi grigi', 'type' => 'Gomito', 'supplier_id' => $altro->id]);

        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $johnGuest->id]);
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$matching->id => 1, $mismatched->id => 1]);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => \App\Filament\Resources\MaterialOrderResource\Pages\EditMaterialOrder::class,
        ]);

        $component->assertOk();
        $component->assertSee('Altro fornitore');
    }
}
