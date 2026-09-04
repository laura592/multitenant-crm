<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteProduct;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class QuotePdfRouteTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function makeQuote(?Tenant $tenant = null): Quote
    {
        $tenant ??= Tenant::create(['name' => 'Alex', 'slug' => 'alex-'.uniqid()]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale', 'email' => 'bar@example.it']);
        $machine = Product::create(['sku' => 'A400-TEST-'.uniqid(), 'type' => Product::TYPE_MACHINE, 'name' => 'A400 Test']);

        $quote = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => now(), 'status' => 'bozza', 'discount' => 10,
        ]);
        QuoteProduct::create(['quote_id' => $quote->id, 'product_id' => $machine->id, 'quantity' => 1, 'price' => 6900, 'discount' => 20, 'tax' => 22]);
        $quote->updateTotal();

        return $quote->fresh();
    }

    public function test_quote_pdf_route_serves_inline_pdf_to_authorized_user(): void
    {
        $quote = $this->makeQuote();
        $user = User::create(['tenant_id' => $quote->tenant_id, 'name' => 'D', 'email' => 'd@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $quote->tenant, 'admin');

        $response = $this->actingAs($user)->get(route('quotes.pdf', $quote));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
    }

    public function test_quote_pdf_route_requires_authentication(): void
    {
        $quote = $this->makeQuote();

        $response = $this->get(route('quotes.pdf', $quote));

        $response->assertRedirect(route('login'));
    }

    public function test_quote_pdf_route_denies_user_without_view_permission(): void
    {
        $quote = $this->makeQuote();
        $user = User::create(['tenant_id' => $quote->tenant_id, 'name' => 'E', 'email' => 'e@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $quote->tenant, 'dipendente');

        $response = $this->actingAs($user)->get(route('quotes.pdf', $quote));

        $response->assertForbidden();
    }

    public function test_quote_pdf_route_denies_user_from_another_tenant(): void
    {
        $ownTenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex-'.uniqid()]);
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-'.uniqid()]);

        $user = User::create(['tenant_id' => $ownTenant->id, 'name' => 'D', 'email' => 'd@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $ownTenant, 'admin');

        $foreignQuote = $this->makeQuote($otherTenant);

        $response = $this->actingAs($user)->get(route('quotes.pdf', $foreignQuote));

        $response->assertForbidden();
    }
}
