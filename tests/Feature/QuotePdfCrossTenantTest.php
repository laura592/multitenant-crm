<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\QuoteResource\Pages\EditQuote;
use App\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Copertura cross-tenant per i preventivi (dominio esplicitamente escluso da
 * SensitiveDownloadsAuthorizationTest quando quel ticket e' stato chiuso, in
 * quanto QuoteResource::streamPdf()/sendQuoteEmail() risolvono il record
 * tramite la stessa query Eloquent scoped-per-tenant di Filament gia'
 * verificata sicura per MaterialOrderResource — qui la si verifica
 * esplicitamente anche per Quote, il dato commerciale piu' sensibile
 * dell'app (prezzi, sconti, dati cliente).
 */
class QuotePdfCrossTenantTest extends TestCase
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

    private function makeQuote(Tenant $tenant, string $customerLabel = 'Bar'): Quote
    {
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => $customerLabel.' '.$tenant->name]);

        return Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'date' => now(),
            'status' => 'bozza',
        ]);
    }

    public function test_admin_can_generate_pdf_of_a_quote_in_their_own_tenant(): void
    {
        $quote = $this->makeQuote($this->tenant);

        $response = QuoteResource::streamPdf($quote->fresh());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("preventivo-{$quote->number}.pdf", $response->headers->get('content-disposition'));
    }

    public function test_edit_page_of_a_quote_from_another_tenant_is_not_found(): void
    {
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner']);
        $foreignQuote = $this->makeQuote($otherTenant);

        $this->actingAs($this->admin)
            ->get(QuoteResource::getUrl('edit', ['record' => $foreignQuote], panel: 'admin', tenant: $this->tenant))
            ->assertNotFound();
    }

    public function test_edit_record_component_cannot_bind_a_quote_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner-2']);
        $foreignQuote = $this->makeQuote($otherTenant);

        // Stesso confine bypassando l'HTTP layer: costruendo direttamente il
        // componente Livewire della edit page con l'ID di un preventivo di
        // un altro tenant, il binding fallisce perche' la query di
        // QuoteResource resta scoped al tenant corrente (BelongsToTenant).
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditQuote::class, ['record' => $foreignQuote->getRouteKey()]);
    }

    public function test_quote_list_does_not_expose_rows_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner-3']);
        $ownQuote = $this->makeQuote($this->tenant, 'Bar Proprio');
        $foreignQuote = $this->makeQuote($otherTenant, 'Bar Estraneo');

        Livewire::test(ListQuotes::class)
            ->assertCanSeeTableRecords([$ownQuote])
            ->assertCanNotSeeTableRecords([$foreignQuote]);
    }
}
