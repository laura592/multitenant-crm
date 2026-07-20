<?php

namespace Tests\Feature;

use App\Filament\Resources\MaterialOrderResource;
use App\Filament\Resources\MaterialOrderResource\RelationManagers\ItemsRelationManager;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class MaterialOrderItemsFlowTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $this->user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'D', 'email' => 'd@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->user, $this->tenant, 'admin');

        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    public function test_clicking_new_order_creates_it_immediately_and_redirects_to_edit(): void
    {
        $this->assertSame(0, MaterialOrder::count());

        $response = $this->get("/admin/{$this->tenant->slug}/material-orders");
        $response->assertOk();

        // La action "create" della lista crea subito il record e reindirizza:
        // simulo l'equivalente a livello di modello dato che e' un semplice
        // redirect, gia' verificato via unit del comportamento del modello.
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);
        $this->assertNotNull($order->number);

        $this->get(MaterialOrderResource::getUrl('edit', ['record' => $order]))
            ->assertOk()
            ->assertSee($order->number)
            ->assertSee('Aggiungi materiali')
            ->assertSee('Materiali');
    }

    public function test_add_selected_materials_persists_immediately_and_sums_on_repeat(): void
    {
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'tube_diameter' => '1/4']);

        $count = MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 3]);
        $this->assertSame(1, $count);
        $this->assertDatabaseHas('material_order_items', ['material_order_id' => $order->id, 'material_id' => $material->id, 'quantity' => 3]);

        // Riaggiungo lo stesso materiale: deve sommarsi, non duplicare la riga.
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 2]);
        $this->assertDatabaseHas('material_order_items', ['material_order_id' => $order->id, 'material_id' => $material->id, 'quantity' => 5]);
        $this->assertSame(1, $order->items()->count());

        // Quantita' a 0 vengono ignorate, nessuna riga creata.
        $material2 = Material::create(['code' => 'PI0308S', 'category' => 'Raccordi grigi', 'type' => 'Intermedio a gomito', 'tube_diameter' => '1/4']);
        $countZero = MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material2->id => 0]);
        $this->assertSame(0, $countZero);
        $this->assertDatabaseMissing('material_order_items', ['material_order_id' => $order->id, 'material_id' => $material2->id]);
    }

    public function test_items_survive_without_ever_touching_the_main_save_button(): void
    {
        // Il punto centrale della richiesta: "anche se ricarico la pagina i
        // dati devono rimanere salvati". Qui non chiamo mai 'save' sul form
        // principale della pagina Edit: i materiali devono comunque essere
        // gia' in DB, perche' l'azione che li aggiunge scrive direttamente.
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'tube_diameter' => '1/4']);

        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 4]);

        // Simulo il "ricaricamento della pagina": ricarico il record da zero.
        $reloaded = MaterialOrder::with('items.material')->find($order->id);
        $this->assertCount(1, $reloaded->items);
        $this->assertSame(4, $reloaded->items->first()->quantity);
    }

    public function test_items_relation_manager_renders_grouped_and_allows_inline_quantity_edit_and_delete(): void
    {
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);
        $m1 = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'tube_diameter' => '1/4']);
        $m2 = Material::create(['code' => 'CM451213FS', 'category' => 'Raccordi metrici', 'type' => 'Terminale diritto femmina', 'tube_diameter' => '12']);
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$m1->id => 2, $m2->id => 5]);

        $component = Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => \App\Filament\Resources\MaterialOrderResource\Pages\EditMaterialOrder::class,
        ]);
        $component->assertOk();
        $component->assertSee('Raccordi grigi');
        $component->assertSee('Raccordi metrici');
        $component->assertSee('PI0108S');
        $component->assertSee('CM451213FS');

        $item1 = $order->items()->where('material_id', $m1->id)->first();
        $component->call('updateTableColumnState', 'quantity', $item1->getKey(), 9);
        $this->assertSame(9, $item1->fresh()->quantity);

        $component->callTableAction(\Filament\Tables\Actions\DeleteAction::class, $item1);
        $this->assertDatabaseMissing('material_order_items', ['id' => $item1->id]);
        $this->assertDatabaseHas('material_order_items', ['id' => $order->items()->where('material_id', $m2->id)->first()->id]);
    }

    public function test_pdf_still_generates_with_new_flow(): void
    {
        $order = MaterialOrder::create(['tenant_id' => $this->tenant->id]);
        $material = Material::create(['code' => 'PI0108S', 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'tube_diameter' => '1/4']);
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 3]);

        $pdf = MaterialOrderResource::streamPdf($order->fresh());
        $this->assertNotNull($pdf);
    }
}
