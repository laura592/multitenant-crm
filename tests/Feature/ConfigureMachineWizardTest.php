<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCompatibility;
use App\Models\ProductFamily;
use App\Models\ProductOptionGroup;
use App\Models\ProductRequirement;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class ConfigureMachineWizardTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    protected Tenant $tenant;

    protected User $user;

    protected Quote $quote;

    protected Product $machine;

    protected Product $requiredAux;

    protected ProductOptionGroup $steamGroup;

    protected Product $steamOption1;

    protected Product $steamOption2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Gifar',
            'email' => 'test@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($this->user, $this->tenant, 'dipendente');

        $family = ProductFamily::create(['name' => 'A300']);
        $this->machine = Product::create([
            'product_family_id' => $family->id,
            'sku' => 'A300-FM-1G-H1-W3',
            'type' => Product::TYPE_MACHINE,
            'name' => 'A300 FM EC 1G H1 W3',
        ]);
        $this->machine->prices()->create(['price' => 6400]);

        $this->requiredAux = Product::create(['sku' => 'SU03-EC', 'type' => Product::TYPE_AUXILIARY_UNIT, 'name' => 'SU03 EC']);
        $this->requiredAux->prices()->create(['price' => 1170]);
        $auxGroup = ProductOptionGroup::create(['name' => 'raffreddamento', 'label' => 'Raffreddamento', 'selection_type' => 'single']);
        ProductCompatibility::create([
            'base_product_id' => $this->machine->id,
            'option_product_id' => $this->requiredAux->id,
            'option_group_id' => $auxGroup->id,
            'constraint_type' => 'required',
        ]);

        $this->steamGroup = ProductOptionGroup::create(['name' => 'lancia_vapore', 'label' => 'Lancia vapore', 'selection_type' => 'single']);
        $this->steamOption1 = Product::create(['sku' => 'S1', 'type' => Product::TYPE_OPTION, 'name' => 'Lancia vapore S1']);
        $this->steamOption1->prices()->create(['price' => 500]);
        $this->steamOption2 = Product::create(['sku' => 'S2', 'type' => Product::TYPE_OPTION, 'name' => 'Autosteam S2']);
        $this->steamOption2->prices()->create(['price' => 765]);

        foreach ([$this->steamOption1, $this->steamOption2] as $option) {
            ProductCompatibility::create([
                'base_product_id' => $this->machine->id,
                'option_product_id' => $option->id,
                'option_group_id' => $this->steamGroup->id,
                'constraint_type' => 'compatible',
            ]);
        }

        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
        $this->quote = Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'date' => now(),
        ]);

        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    public function test_wizard_adds_machine_with_required_unit_and_selected_option(): void
    {
        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
                "group_{$this->steamGroup->id}" => $this->steamOption2->id,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->quote->refresh();

        $productIds = $this->quote->quoteProducts()->pluck('product_id');

        $this->assertTrue($productIds->contains($this->machine->id), 'La macchina base deve essere in preventivo');
        $this->assertTrue($productIds->contains($this->requiredAux->id), 'L\'unità obbligatoria deve essere aggiunta automaticamente');
        $this->assertTrue($productIds->contains($this->steamOption2->id), 'L\'opzione scelta (Autosteam S2) deve essere aggiunta');
        $this->assertFalse($productIds->contains($this->steamOption1->id), 'L\'opzione non scelta (S1) non deve comparire');

        // 6400 (macchina) + 1170 (unità obbligatoria) + 765 (S2) = 8335, + 22% iva = 10168.70
        $this->assertEquals(10168.70, (float) $this->quote->total);
    }

    public function test_wizard_blocks_when_a_required_dependency_is_missing(): void
    {
        // Un'opzione fittizia che richiede un prodotto MAI selezionabile in questa configurazione
        $dualMilk = Product::create(['sku' => 'DMI', 'type' => Product::TYPE_OPTION, 'name' => 'DualMilk']);
        $selfServe = Product::create(['sku' => 'SSP', 'type' => Product::TYPE_OPTION, 'name' => 'Self-Serve Package']);
        ProductRequirement::create(['product_id' => $dualMilk->id, 'requires_product_id' => $selfServe->id]);

        ProductCompatibility::create([
            'base_product_id' => $this->machine->id,
            'option_product_id' => $dualMilk->id,
            'option_group_id' => $this->steamGroup->id,
            'constraint_type' => 'compatible',
        ]);

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
                "group_{$this->steamGroup->id}" => $dualMilk->id,
            ])
            ->callMountedAction();

        $this->quote->refresh();

        $this->assertSame(0, $this->quote->quoteProducts()->count(), 'Nessuna riga deve essere creata se un vincolo requires non è soddisfatto');
    }

    public function test_creating_a_quote_redirects_to_edit_with_the_wizard_already_open(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Nuovo Cliente']);

        Livewire::test(CreateQuote::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'date' => now()->toDateString(),
                'status' => 'bozza',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirectContains('openWizard=1');

        $newQuote = Quote::where('customer_id', $customer->id)->firstOrFail();

        $response = $this->get(route('filament.admin.resources.quotes.edit', [
            'tenant' => $this->tenant->slug,
            'record' => $newQuote->getRouteKey(),
        ]).'?openWizard=1');

        $response->assertOk();
        // Testo visibile solo se il wizard "configureMachine" è davvero montato/aperto
        // (nessuna macchina ancora scelta a questo punto: solo Macchina + Riepilogo)
        $response->assertSee('Riepilogo');
    }

    public function test_wizard_shows_a_dedicated_step_per_known_category_with_prices_visible(): void
    {
        $machineWithPrice = Product::create([
            'product_family_id' => $this->machine->product_family_id,
            'sku' => 'A300-PRICED',
            'type' => Product::TYPE_MACHINE,
            'name' => 'A300 Priced',
        ]);
        $machineWithPrice->prices()->create(['price' => 6400]);

        $steamGroupKnown = ProductOptionGroup::create(['name' => 'steam', 'label' => 'Steam', 'selection_type' => 'single']);
        $steamOption = Product::create(['sku' => 'S1-PRICED', 'type' => Product::TYPE_OPTION, 'name' => 'Lancia vapore S1']);
        $steamOption->prices()->create(['price' => 500]);
        ProductCompatibility::create([
            'base_product_id' => $machineWithPrice->id,
            'option_product_id' => $steamOption->id,
            'option_group_id' => $steamGroupKnown->id,
            'constraint_type' => 'compatible',
        ]);

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $machineWithPrice->product_family_id,
                'machine_product_id' => $machineWithPrice->id,
            ])
            ->assertSee('A300 Priced — 6.400,00 €')
            ->assertSee('Lancia vapore') // etichetta step, mappata dal gruppo "steam"
            ->assertSee('Lancia vapore S1 — 500,00 €');
    }
}
