<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource;
use App\Filament\Resources\MachineUnitResource\Pages\ListMachineUnits;
use App\Filament\Resources\MachineUnitResource\Pages\ViewMachineUnit;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    /**
     * Solo avere la pagina "view" non basta: la tabella deve registrare
     * anche l'azione ViewAction, altrimenti Filament::recordUrl() ripiega
     * su "edit" (guarda getAction('view') prima di getAction('edit') - vedi
     * ListRecords::makeTable()) e cliccare una riga apre comunque il form
     * modificabile invece della vista di sola lettura.
     */
    public function test_product_row_click_opens_view_not_edit(): void
    {
        $tenant = $this->loginAdmin();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'sku' => 'GIFAR-ROW-CLICK',
            'type' => Product::TYPE_MACHINE,
            'name' => 'ICON 2GR',
        ]);

        Livewire::test(ListProducts::class)
            ->assertTableActionHasUrl('view', ProductResource::getUrl('view', ['record' => $product]), $product);
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

    /**
     * "Crea rapportino" era disponibile solo dal menu azioni della tabella:
     * ora che il click riga apre la view invece dell'edit, da li' non si
     * passa piu' dalla tabella, quindi l'azione va replicata anche
     * nell'header della view (ViewMachineUnit::getHeaderActions()).
     */
    public function test_machine_unit_view_page_exposes_crea_rapportino_when_assigned_to_a_customer(): void
    {
        $tenant = $this->loginAdmin();
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'status' => MachineUnit::STATUS_INSTALLATA,
            'serial_number' => 'SN-RAPPORTINO-001',
            'model_name' => 'ICON 2GR',
        ]);

        $this->get(MachineUnitResource::getUrl('view', ['record' => $machine]))
            ->assertOk()
            ->assertSee('Crea rapportino')
            ->assertSee("machine_unit_id={$machine->id}", escape: false)
            ->assertSee("customer_id={$customer->id}", escape: false);
    }

    public function test_machine_unit_view_page_hides_crea_rapportino_when_not_assigned_to_a_customer(): void
    {
        $tenant = $this->loginAdmin();
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => null,
            'status' => MachineUnit::STATUS_IN_MAGAZZINO,
            'serial_number' => 'SN-RAPPORTINO-002',
            'model_name' => 'ICON 2GR',
        ]);

        $this->get(MachineUnitResource::getUrl('view', ['record' => $machine]))
            ->assertOk()
            ->assertDontSee('Crea rapportino');
    }

    /**
     * Non basta che "Sposta" sia visibile nell'header della view: verifica
     * che l'azione condivisa (MachineUnitResource::spostaAction(), la stessa
     * usata dal menu di riga della tabella) funzioni davvero anche montata
     * su una pagina record invece che su un'azione di tabella.
     */
    public function test_sposta_action_works_from_the_view_page_header(): void
    {
        $tenant = $this->loginAdmin();
        $oldCustomer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Vecchio']);
        $newCustomer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Nuovo']);
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $oldCustomer->id,
            'status' => MachineUnit::STATUS_INSTALLATA,
            'serial_number' => 'SN-SPOSTA-001',
            'model_name' => 'ICON 2GR',
        ]);

        Livewire::test(ViewMachineUnit::class, ['record' => $machine->getRouteKey()])
            ->mountAction('sposta')
            ->setActionData(['customer_id' => $newCustomer->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame($newCustomer->id, $machine->fresh()->current_customer_id);
    }

    /**
     * Il ViewRecord senza infolist() esplicito ripiegava sul form()
     * disabilitato: per una Select con ->relationship('product', 'name')
     * la label giusta veniva risolta solo via una chiamata Livewire lato
     * client dopo il caricamento, quindi l'HTML iniziale mostrava lo uuid
     * grezzo di product_id invece del nome del modello a catalogo.
     * MachineUnitResource::infolist() lo risolve lato server: qui si
     * verifica che il nome del prodotto (non il suo id) sia gia' nell'HTML
     * di risposta, non solo raggiungibile dopo un giro di JS.
     */
    public function test_machine_unit_view_page_shows_catalog_product_name_not_its_id(): void
    {
        $tenant = $this->loginAdmin();
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'sku' => 'GIFAR-CATALOGO-001',
            'type' => Product::TYPE_MACHINE,
            'name' => 'ICON 2 GR TOTAL MATT BLACK',
        ]);
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'status' => MachineUnit::STATUS_IN_MAGAZZINO,
            'serial_number' => 'SN-CATALOGO-001',
        ]);

        $this->get(MachineUnitResource::getUrl('view', ['record' => $machine]))
            ->assertOk()
            ->assertSee('ICON 2 GR TOTAL MATT BLACK')
            ->assertDontSee($product->id);
    }

    public function test_machine_unit_row_click_opens_view_not_edit(): void
    {
        $tenant = $this->loginAdmin();
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $machine = MachineUnit::create([
            'tenant_id' => $tenant->id,
            'current_customer_id' => $customer->id,
            'status' => MachineUnit::STATUS_INSTALLATA,
            'serial_number' => 'SN-ROW-CLICK',
            'model_name' => 'ICON 2GR',
        ]);

        Livewire::test(ListMachineUnits::class)
            ->assertTableActionHasUrl('view', MachineUnitResource::getUrl('view', ['record' => $machine]), $machine);
    }
}
