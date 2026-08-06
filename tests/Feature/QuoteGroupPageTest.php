<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteGroupResource;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\QuoteProduct;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class QuoteGroupPageTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_offer_edit_page_exposes_action_to_add_a_new_quote(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);
        $group = QuoteGroup::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'status' => 'bozza',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Admin',
            'email' => 'admin@example.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->get(QuoteGroupResource::getUrl('edit', ['record' => $group]))
            ->assertOk()
            ->assertSee('Riepilogo offerta')
            ->assertSee('Aggiungi preventivo');
    }

    public function test_sending_a_global_offer_also_marks_its_draft_quotes_as_sent(): void
    {
        Mail::fake();

        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale', 'email' => 'bar@example.it']);
        $machine = Product::create(['sku' => 'A400-TEST', 'type' => Product::TYPE_MACHINE, 'name' => 'A400 Test']);
        $group = QuoteGroup::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'status' => 'bozza']);

        $draftQuote = Quote::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'quote_group_id' => $group->id, 'date' => now(), 'status' => 'bozza', 'discount' => 0]);
        QuoteProduct::create(['quote_id' => $draftQuote->id, 'product_id' => $machine->id, 'quantity' => 1, 'price' => 6900, 'discount' => 0, 'tax' => 22]);
        $draftQuote->updateTotal();

        $acceptedQuote = Quote::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'quote_group_id' => $group->id, 'date' => now(), 'status' => 'accettato', 'discount' => 0]);
        QuoteProduct::create(['quote_id' => $acceptedQuote->id, 'product_id' => $machine->id, 'quantity' => 1, 'price' => 6900, 'discount' => 0, 'tax' => 22]);
        $acceptedQuote->updateTotal();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Admin',
            'email' => 'admin2@example.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        QuoteGroupResource::sendGroupEmail($group, ['recipient_email' => 'cliente@example.it']);

        // Ogni preventivo in bozza dentro l'offerta appena inviata deve
        // risultare "inviato" a sua volta, senza dover rimandare ciascuno a
        // mano - uno gia' avanzato (accettato) non deve invece regredire.
        $this->assertSame('inviato', $draftQuote->fresh()->status);
        $this->assertSame('accettato', $acceptedQuote->fresh()->status);
        $this->assertSame('inviato', $group->fresh()->status);
    }
}
