<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Filament\Resources\QuoteResource\RelationManagers\QuoteProductsRelationManager;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductOptionSlot;
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
    use AssignsPermissionRoles, RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected Quote $quote;

    protected Product $machine;

    protected Product $requiredAux;

    protected ProductOptionSlot $steamSlot;

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
        $auxSlot = ProductOptionSlot::create([
            'product_id' => $this->machine->id,
            'slot_name' => 'raffreddamento',
            'label' => 'Raffreddamento',
            'required' => true,
            'min_qty' => 1,
            'max_qty' => 1,
        ]);
        $auxSlot->items()->create(['component_product_id' => $this->requiredAux->id]);

        $this->steamSlot = ProductOptionSlot::create([
            'product_id' => $this->machine->id,
            'slot_name' => 'lancia_vapore',
            'label' => 'Lancia vapore',
            'max_qty' => 1,
        ]);
        $this->steamOption1 = Product::create(['sku' => 'S1', 'type' => Product::TYPE_OPTION, 'name' => 'Lancia vapore S1']);
        $this->steamOption1->prices()->create(['price' => 500]);
        $this->steamOption2 = Product::create(['sku' => 'S2', 'type' => Product::TYPE_OPTION, 'name' => 'Autosteam S2']);
        $this->steamOption2->prices()->create(['price' => 765]);

        foreach ([$this->steamOption1, $this->steamOption2] as $option) {
            $this->steamSlot->items()->create(['component_product_id' => $option->id]);
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
                "slot_{$this->steamSlot->id}" => $this->steamOption2->id,
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

    /**
     * Bug reale segnalato: dopo aver confermato il wizard, il tab "Righe
     * preventivo" mostrava le nuove righe solo ricaricando manualmente la
     * pagina - perche' il wizard scrive le righe direttamente sul modello,
     * fuori dal ciclo tabella del RelationManager. ConfigureMachineAction
     * ora dispatcha l'evento Livewire "quoteProductsUpdated", a cui il
     * RelationManager e' abbonato (vedi
     * QuoteProductsRelationManager::refreshAfterMachineConfigured): questo
     * test verifica che il dispatch di quell'evento, sullo stesso componente
     * gia' montato, basti a far comparire righe scritte nel frattempo senza
     * un nuovo mount/reload.
     */
    public function test_relation_manager_shows_new_lines_after_the_refresh_event_without_reloading(): void
    {
        $component = Livewire::test(QuoteProductsRelationManager::class, [
            'ownerRecord' => $this->quote,
            'pageClass' => EditQuote::class,
        ]);

        $component->assertDontSee($this->machine->name);

        // Simula cio' che fa ConfigureMachineAction: scrive le righe
        // direttamente sul modello, fuori dal ciclo del componente.
        $this->quote->quoteProducts()->create([
            'product_id' => $this->machine->id, 'quantity' => 1, 'price' => 6400, 'discount' => 0, 'tax' => 22,
        ]);

        $component->dispatch('quoteProductsUpdated')
            ->assertSee($this->machine->name);
    }

    public function test_wizard_blocks_when_a_required_dependency_is_missing(): void
    {
        // Un'opzione fittizia che richiede un prodotto MAI selezionabile in questa configurazione
        $dualMilk = Product::create(['sku' => 'DMI', 'type' => Product::TYPE_OPTION, 'name' => 'DualMilk']);
        $selfServe = Product::create(['sku' => 'SSP', 'type' => Product::TYPE_OPTION, 'name' => 'Self-Serve Package']);
        ProductRequirement::create(['product_id' => $dualMilk->id, 'requires_product_id' => $selfServe->id]);

        $this->steamSlot->items()->create(['component_product_id' => $dualMilk->id]);

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
                "slot_{$this->steamSlot->id}" => $dualMilk->id,
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

        $steamSlotKnown = ProductOptionSlot::create([
            'product_id' => $machineWithPrice->id,
            'slot_name' => 'steam',
            'label' => 'Steam',
            'max_qty' => 1,
        ]);
        $steamOption = Product::create(['sku' => 'S1-PRICED', 'type' => Product::TYPE_OPTION, 'name' => 'Lancia vapore S1']);
        $steamOption->prices()->create(['price' => 500]);
        $steamSlotKnown->items()->create(['component_product_id' => $steamOption->id]);

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $machineWithPrice->product_family_id,
                'machine_product_id' => $machineWithPrice->id,
            ])
            ->assertSee('A300 Priced — 6.400,00 €')
            ->assertSee('Lancia vapore') // etichetta step, mappata dallo slot "steam"
            ->assertSee('Lancia vapore S1 — 500,00 €');
    }

    /**
     * A differenza del vecchio grafo di compatibilita' (che non aveva un
     * concetto di "massimo selezionabile"), uno slot multi-scelta puo'
     * limitare quante opzioni si possono prendere insieme.
     */
    /**
     * Bug reale segnalato: "la configurazione non avviene come desiderato".
     * "product_family_id" e' ->live() ma senza afterStateUpdated(): se
     * l'utente sceglie una famiglia/macchina, poi torna indietro e cambia
     * famiglia (correzione naturale), "machine_product_id" non veniva mai
     * azzerato. Il campo restava valorizzato con l'id della VECCHIA macchina
     * (di un'altra famiglia), passava comunque la validazione ->required()
     * e veniva salvata nel preventivo la macchina sbagliata rispetto a
     * quella dell'ultima famiglia scelta dall'utente.
     */
    public function test_changing_family_resets_stale_machine_selection(): void
    {
        $otherFamily = ProductFamily::create(['name' => 'B500']);
        $otherMachine = Product::create([
            'product_family_id' => $otherFamily->id,
            'sku' => 'B500-BASE',
            'type' => Product::TYPE_MACHINE,
            'name' => 'B500 Base',
        ]);
        $otherMachine->prices()->create(['price' => 3000]);

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
            ])
            // L'utente torna indietro e cambia famiglia, senza (ancora)
            // riselezionare la macchina per la nuova famiglia.
            ->setActionData([
                'product_family_id' => $otherFamily->id,
            ])
            ->callMountedAction()
            ->assertHasActionErrors(['machine_product_id']);

        $this->quote->refresh();

        $this->assertSame(0, $this->quote->quoteProducts()->count(), 'Nessuna riga deve essere creata: la macchina della vecchia famiglia non deve essere salvata per errore');
    }

    public function test_wizard_blocks_selecting_more_items_than_slot_max_qty(): void
    {
        $addonSlot = ProductOptionSlot::create([
            'product_id' => $this->machine->id,
            'slot_name' => 'addon',
            'label' => 'Accessori aggiuntivi',
            'max_qty' => 2,
        ]);
        $addon1 = Product::create(['sku' => 'ADD1', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Accessorio 1']);
        $addon2 = Product::create(['sku' => 'ADD2', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Accessorio 2']);
        $addon3 = Product::create(['sku' => 'ADD3', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Accessorio 3']);

        foreach ([$addon1, $addon2, $addon3] as $addon) {
            $addonSlot->items()->create(['component_product_id' => $addon->id]);
        }

        // Checkbox individuali (non piu' un CheckboxList unico, vedi
        // ConfigureMachineAction::slotStepSchema): required/min/max non sono
        // piu' validati inline dal campo ma nell'action stessa
        // (findSlotQuantityViolation), quindi qui si verifica la notifica di
        // errore invece di assertHasActionErrors.
        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
                "slot_{$addonSlot->id}__{$addon1->id}" => true,
                "slot_{$addonSlot->id}__{$addon2->id}" => true,
                "slot_{$addonSlot->id}__{$addon3->id}" => true,
            ])
            ->callMountedAction()
            ->assertNotified();

        $this->quote->refresh();
        $this->assertSame(0, $this->quote->quoteProducts()->count(), 'Nessuna riga deve essere creata se il massimo dello slot viene superato');
    }

    /**
     * Bug reale segnalato: un CheckboxList unico con wire:model condiviso su
     * piu' checkbox, dentro il wizard annidato in una modale action, si
     * comportava in modo rotto lato client - un click su UNA opzione le
     * selezionava TUTTE. Verificato dal vivo in browser (Playwright) prima
     * di correggere: sostituito con un campo booleano indipendente per
     * opzione (slot_{id}__{componentId}), che qui si verifica selezionando
     * solo un'opzione su tre e controllando che le altre due NON vengano
     * aggiunte.
     */
    public function test_selecting_only_one_checkbox_option_does_not_select_the_others(): void
    {
        $addonSlot = ProductOptionSlot::create([
            'product_id' => $this->machine->id,
            'slot_name' => 'addon',
            'label' => 'Accessori aggiuntivi',
        ]);
        $addon1 = Product::create(['sku' => 'ADD1', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Accessorio 1']);
        $addon2 = Product::create(['sku' => 'ADD2', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Accessorio 2']);
        $addon3 = Product::create(['sku' => 'ADD3', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Accessorio 3']);

        foreach ([$addon1, $addon2, $addon3] as $addon) {
            $addonSlot->items()->create(['component_product_id' => $addon->id]);
        }

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
                "slot_{$addonSlot->id}__{$addon1->id}" => true,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->quote->refresh();
        $productIds = $this->quote->quoteProducts()->pluck('product_id');

        $this->assertTrue($productIds->contains($addon1->id));
        $this->assertFalse($productIds->contains($addon2->id));
        $this->assertFalse($productIds->contains($addon3->id));
    }

    public function test_multi_choice_options_are_grouped_by_category_like_price_lists(): void
    {
        $addonSlot = ProductOptionSlot::create([
            'product_id' => $this->machine->id,
            'slot_name' => 'addon',
            'label' => 'Accessori aggiuntivi',
        ]);
        $catA = \App\Models\Category::create(['name' => 'Macinini']);
        $catB = \App\Models\Category::create(['name' => 'Dosatori']);

        $addon1 = Product::create(['sku' => 'ADD1', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Macinino 1', 'category_id' => $catA->id]);
        $addon2 = Product::create(['sku' => 'ADD2', 'type' => Product::TYPE_ACCESSORY, 'name' => 'Dosatore 1', 'category_id' => $catB->id]);

        foreach ([$addon1, $addon2] as $addon) {
            $addonSlot->items()->create(['component_product_id' => $addon->id]);
        }

        Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
            ])
            ->assertSee('Macinini')
            ->assertSee('Dosatori');
    }

    /**
     * Bug reale segnalato: il riepilogo finale era un testo fisso ("Conferma
     * per aggiungere...") senza elencare cosa si stava per aggiungere ne' un
     * totale - si confermava "alla cieca". Verifica che ora elenchi macchina
     * + unita' obbligatoria auto-inclusa + opzione scelta, col totale corretto.
     */
    public function test_summary_step_lists_every_line_with_a_correct_total(): void
    {
        $component = Livewire::test(EditQuote::class, ['record' => $this->quote->getRouteKey()])
            ->mountAction('configureMachine')
            ->setActionData([
                'product_family_id' => $this->machine->product_family_id,
                'machine_product_id' => $this->machine->id,
                "slot_{$this->steamSlot->id}" => $this->steamOption2->id,
            ]);

        // Nota: non verifico l'assenza di steamOption1 con assertDontSee - il
        // Radio dello step precedente resta nel DOM (solo nascosto via CSS,
        // il wizard non smonta gli altri step), quindi il suo testo sarebbe
        // comunque presente in pagina indipendentemente dal riepilogo.
        $component->assertSee($this->machine->name);
        $component->assertSee($this->requiredAux->name);
        $component->assertSee($this->steamOption2->name);

        // 6400 (macchina) + 1170 (unità obbligatoria) + 765 (S2) = 8335,00 €
        $component->assertSee('8.335,00');
    }
}
