<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialOrderResource;
use App\Filament\Resources\MaterialOrderResource\Pages\EditMaterialOrder;
use App\Filament\Resources\MaterialOrderResource\Pages\ListMaterialOrders;
use App\Mail\MaterialOrderMail;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_new_order_defaults_to_bozza(): void
    {
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);

        $this->assertSame('bozza', $order->fresh()->status);
    }

    public function test_sending_to_supplier_logs_the_email_and_marks_the_order_as_sent(): void
    {
        Mail::fake();

        $supplier = Supplier::create(['name' => 'John Guest', 'email' => 'ordini@johnguest.it']);
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id]);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto']);
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 5]);

        Livewire::test(EditMaterialOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('sendToSupplier')
            ->assertActionDataSet(['recipient_email' => 'ordini@johnguest.it'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Mail::assertSent(MaterialOrderMail::class, fn ($mail) => $mail->order->is($order));

        $this->assertDatabaseHas('material_order_emails', [
            'material_order_id' => $order->id,
            'recipient_email' => 'ordini@johnguest.it',
            'status' => 'sent',
        ]);

        $this->assertSame('inviato', $order->fresh()->status);
    }

    public function test_mark_received_only_available_after_sending(): void
    {
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);

        Livewire::test(EditMaterialOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('markReceived');

        $order->update(['status' => 'inviato']);

        Livewire::test(EditMaterialOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('markReceived')
            ->callAction('markReceived');

        $this->assertSame('ricevuto', $order->fresh()->status);
    }

    public function test_duplicate_copies_supplier_and_items_but_not_notes_or_status(): void
    {
        $supplier = Supplier::create(['name' => 'John Guest']);
        $order = MaterialOrder::create([
            'tenant_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'notes' => 'Consegna urgente',
            'status' => 'ricevuto',
        ]);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto']);
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 3]);

        Livewire::test(ListMaterialOrders::class)
            ->callTableAction('duplicate', $order)
            ->assertHasNoTableActionErrors();

        $duplicate = MaterialOrder::where('id', '!=', $order->id)->firstOrFail();

        $this->assertNotSame($order->id, $duplicate->id);
        $this->assertNotSame($order->number, $duplicate->number);
        $this->assertSame('bozza', $duplicate->status);
        $this->assertNull($duplicate->notes);
        $this->assertSame($supplier->id, $duplicate->supplier_id);
        $this->assertSame(1, $duplicate->items()->count());
        $this->assertSame(3, $duplicate->items()->first()->quantity);
    }
}
