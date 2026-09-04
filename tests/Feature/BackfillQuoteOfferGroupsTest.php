<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillQuoteOfferGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_groups_historical_quotes_by_customer_and_date(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $quoteOne = Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'date' => '2026-07-10',
            'status' => 'inviato',
        ]);

        $quoteTwo = Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'date' => '2026-07-10',
            'status' => 'rifiutato',
        ]);

        $otherDayQuote = Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'date' => '2026-07-11',
            'status' => 'inviato',
        ]);

        $this->artisan('quotes:backfill-offer-groups')
            ->expectsOutputToContain('Offerte create: 1')
            ->expectsOutputToContain('Preventivi collegati: 2')
            ->assertSuccessful();

        $quoteOne->refresh();
        $quoteTwo->refresh();
        $otherDayQuote->refresh();

        $this->assertNotNull($quoteOne->quote_group_id);
        $this->assertSame($quoteOne->quote_group_id, $quoteTwo->quote_group_id);
        $this->assertNull($otherDayQuote->quote_group_id);
    }

    public function test_command_dry_run_does_not_persist_groups(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $quoteOne = Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'date' => '2026-07-10',
            'status' => 'inviato',
        ]);

        $quoteTwo = Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'date' => '2026-07-10',
            'status' => 'inviato',
        ]);

        $this->artisan('quotes:backfill-offer-groups', ['--dry-run' => true])
            ->expectsOutputToContain('Offerte create: 1')
            ->expectsOutputToContain('Preventivi collegati: 2')
            ->expectsOutputToContain('Eseguito in dry-run: nessun dato scritto.')
            ->assertSuccessful();

        $quoteOne->refresh();
        $quoteTwo->refresh();

        $this->assertNull($quoteOne->quote_group_id);
        $this->assertNull($quoteTwo->quote_group_id);
    }
}