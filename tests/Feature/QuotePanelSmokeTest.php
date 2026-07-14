<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotePanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_user_can_see_own_tenant_quote_in_panel(): void
    {
        $master = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $gifar = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar', 'default_commission_scenario' => 'A']);

        $user = User::create([
            'tenant_id' => $gifar->id,
            'name' => 'Test Gifar',
            'email' => 'test@gifar.it',
            'password' => bcrypt('password'),
        ]);

        $family = ProductFamily::create(['name' => 'A300']);
        $machine = Product::create([
            'product_family_id' => $family->id,
            'sku' => 'A300-NM-1G-H1-W3',
            'type' => Product::TYPE_MACHINE,
            'name' => 'A300 NM 1G H1 W3',
            'source' => Product::SOURCE_FRANKE,
        ]);
        $machine->prices()->create(['price' => 4815]);

        $customer = Customer::create(['tenant_id' => $gifar->id, 'company_name' => 'Bar Centrale']);

        $quote = Quote::create([
            'tenant_id' => $gifar->id,
            'customer_id' => $customer->id,
            'date' => now(),
            'commission_scenario' => 'A',
        ]);
        $quote->quoteProducts()->create([
            'product_id' => $machine->id, 'quantity' => 1, 'price' => 4815, 'discount' => 0, 'tax' => 22,
        ]);
        $quote->updateTotal();
        $quote->refresh();

        $response = $this->actingAs($user)->get("/admin/{$gifar->slug}/quotes");

        $response->assertOk();
        $response->assertSee($quote->number);
        $response->assertSee('Bar Centrale');

        // isolamento: lo stesso utente NON deve poter aprire il tenant master
        // (Filament risponde 404, non 403: non conferma l'esistenza del tenant)
        $this->actingAs($user)->get("/admin/{$master->slug}/quotes")->assertNotFound();
    }
}
