<?php

namespace Tests\Feature;

use App\Filament\Resources\InformationRequestResource\Pages\EditInformationRequest;
use App\Filament\Resources\InformationRequestResource\RelationManagers\QuotesRelationManager;
use App\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Una richiesta informazioni puo' generare piu' preventivi (varianti,
 * rilanci) ed eventualmente un'offerta che li raggruppa: il collegamento sta
 * sul preventivo, e lo stato "preventivato" si legge da li' invece di essere
 * un secondo stato della richiesta da aggiornare a mano.
 */
class InformationRequestQuoteLinkTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    private InformationRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin-richieste@alex.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($this->user, $this->tenant, 'admin');

        $this->customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
        $this->request = InformationRequest::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'request_details' => 'Vorrebbero una macchina a noleggio',
            'status' => 'nuova',
        ]);

        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function product(string $sku, float $price): Product
    {
        $product = Product::create([
            'sku' => $sku,
            'type' => Product::TYPE_MACHINE,
            'name' => "Macchina {$sku}",
        ]);

        ProductPrice::create(['product_id' => $product->id, 'price' => $price]);

        return $product;
    }

    public function test_a_quote_created_from_a_request_stays_linked_and_inherits_the_products(): void
    {
        $machine = $this->product('A600', 6900);
        $this->request->products()->attach($machine->id);

        Livewire::withQueryParams([
            'customer_id' => $this->customer->id,
            'information_request_id' => $this->request->id,
        ])
            ->test(CreateQuote::class)
            ->fillForm(['date' => now()->toDateString(), 'status' => 'bozza'])
            ->call('create')
            ->assertHasNoFormErrors();

        $quote = Quote::where('customer_id', $this->customer->id)->firstOrFail();

        $this->assertSame($this->request->id, $quote->information_request_id);
        $this->assertSame($this->customer->id, $quote->customer_id, 'Il cliente arriva dalla richiesta, non va riscelto.');

        $line = $quote->quoteProducts()->firstOrFail();
        $this->assertSame($machine->id, $line->product_id);
        $this->assertSame('6900.00', $line->price);
        $this->assertSame(22, $line->tax);
        // updateTotal() somma imponibile + IVA: 6900 + 22%.
        $this->assertSame('8418.00', $quote->fresh()->total, 'Il totale va ricalcolato dopo le righe precompilate.');
    }

    public function test_a_request_can_carry_more_than_one_quote_and_the_offer_that_groups_them(): void
    {
        $group = QuoteGroup::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'status' => 'bozza',
        ]);

        $first = Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'information_request_id' => $this->request->id,
            'quote_group_id' => $group->id,
            'date' => now(),
            'status' => 'inviato',
            'discount' => 0,
        ]);
        $second = Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'information_request_id' => $this->request->id,
            'quote_group_id' => $group->id,
            'date' => now(),
            'status' => 'bozza',
            'discount' => 0,
        ]);

        $this->assertCount(2, $this->request->fresh()->quotes);

        Livewire::test(QuotesRelationManager::class, [
            'ownerRecord' => $this->request,
            'pageClass' => EditInformationRequest::class,
        ])
            ->assertOk()
            ->assertSee($first->number)
            ->assertSee($second->number)
            // L'offerta si legge dal gruppo dei preventivi, non e' collegata
            // a parte alla richiesta.
            ->assertSee($group->number)
            ->assertSee('Inviato');
    }

    public function test_an_existing_quote_can_be_attached_and_detached_afterwards(): void
    {
        $existing = Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'date' => now(),
            'status' => 'inviato',
            'discount' => 0,
        ]);

        // Preventivo di un altro cliente: non deve nemmeno comparire fra le
        // scelte, agganciarlo sarebbe sempre un errore.
        $other = Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Altro Bar'])->id,
            'date' => now(),
            'status' => 'bozza',
            'discount' => 0,
        ]);

        $component = Livewire::test(QuotesRelationManager::class, [
            'ownerRecord' => $this->request,
            'pageClass' => EditInformationRequest::class,
        ]);

        $component->callTableAction('associate', data: ['recordId' => $existing->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame($this->request->id, $existing->fresh()->information_request_id);

        // La select e' ristretta ai preventivi dello stesso cliente: provare a
        // forzarne uno di un altro cliente non deve passare.
        $component->callTableAction('associate', data: ['recordId' => $other->id]);
        $this->assertNull($other->fresh()->information_request_id);

        $component->callTableAction('dissociate', $existing)
            ->assertHasNoTableActionErrors();

        $this->assertNull($existing->fresh()->information_request_id, 'Scollegare non deve cancellare il preventivo.');
        $this->assertNotNull(Quote::find($existing->id));
    }

    public function test_the_select_proposes_the_customer_quotes_that_are_still_free(): void
    {
        $free = Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'date' => now(), 'status' => 'inviato', 'discount' => 0, 'total' => 6900,
        ]);
        // Gia' collegato a un'altra richiesta: proporlo significherebbe
        // staccarlo da quella senza dirlo a nessuno.
        Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'information_request_id' => InformationRequest::create([
                'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
                'request_details' => 'Altra richiesta', 'status' => 'nuova',
            ])->id,
            'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);
        // Di un altro cliente.
        Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Altro Bar'])->id,
            'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);

        $proposti = QuotesRelationManager::collegabili(Quote::query(), $this->request)->get();

        $this->assertCount(1, $proposti);
        $this->assertSame($free->id, $proposti->first()->id);

        // L'etichetta e' quella che si legge nella select: il solo numero non
        // basta a riconoscere il preventivo giusto.
        $etichetta = QuotesRelationManager::etichetta($free->fresh());
        $this->assertStringContainsString($free->number, $etichetta);
        $this->assertStringContainsString('Inviato', $etichetta);
        $this->assertStringContainsString('6.900,00 €', $etichetta);
    }

    public function test_an_offer_can_be_attached_in_one_go(): void
    {
        $group = QuoteGroup::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'status' => 'inviato',
            'sent_at' => now(),
        ]);

        $primo = Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'quote_group_id' => $group->id, 'date' => now(), 'status' => 'inviato', 'discount' => 0,
        ]);
        $secondo = Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'quote_group_id' => $group->id, 'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);
        // Terzo preventivo della stessa offerta, ma gia' legato a un'altra
        // richiesta: non deve essere strappato via.
        $altraRichiesta = InformationRequest::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'request_details' => 'Altra richiesta', 'status' => 'nuova',
        ]);
        $giaLegato = Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'quote_group_id' => $group->id, 'information_request_id' => $altraRichiesta->id,
            'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);

        Livewire::test(QuotesRelationManager::class, [
            'ownerRecord' => $this->request,
            'pageClass' => EditInformationRequest::class,
        ])
            ->callTableAction('collegaOfferta', data: ['quote_group_id' => $group->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame($this->request->id, $primo->fresh()->information_request_id);
        $this->assertSame($this->request->id, $secondo->fresh()->information_request_id);
        $this->assertSame($altraRichiesta->id, $giaLegato->fresh()->information_request_id);
    }

    public function test_an_offer_with_nothing_left_to_attach_is_not_proposed(): void
    {
        $group = QuoteGroup::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'inviato',
        ]);
        Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'quote_group_id' => $group->id, 'information_request_id' => $this->request->id,
            'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);

        $this->assertCount(0, QuotesRelationManager::offerteCollegabili($this->request)->get());

        // Con un preventivo libero invece si propone, e l'etichetta dice
        // quanti ne verrebbero collegati davvero.
        $libero = Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'quote_group_id' => $group->id, 'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);

        $proposte = QuotesRelationManager::offerteCollegabili($this->request)->get();
        $this->assertCount(1, $proposte);
        $this->assertStringContainsString('1 preventivo', QuotesRelationManager::etichettaOfferta($proposte->first()));
        $this->assertNotNull($libero->id);
    }

    public function test_a_quote_created_on_its_own_has_no_request_attached(): void
    {
        Livewire::test(CreateQuote::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'date' => now()->toDateString(),
                'status' => 'bozza',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Quote::where('customer_id', $this->customer->id)->firstOrFail()->information_request_id);
    }
}
