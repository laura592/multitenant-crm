<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La richiesta informazioni segue da sola lo stato dei preventivi collegati:
 * prima andava cambiata a mano e restava "Nuova" (e contata tra quelle da
 * gestire) anche a preventivo gia' inviato.
 */
class InformationRequestFollowsQuoteTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $this->customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
    }

    private function request(string $status = 'nuova'): InformationRequest
    {
        return InformationRequest::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => $status]);
    }

    private function quote(InformationRequest $request, string $status = 'bozza', array $attributes = []): Quote
    {
        return Quote::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'information_request_id' => $request->id,
            'date' => now(),
            'status' => $status,
            ...$attributes,
        ]);
    }

    public function test_the_request_moves_along_with_its_quote(): void
    {
        $request = $this->request();

        $quote = $this->quote($request);
        $this->assertSame('in_lavorazione', $request->fresh()->status);

        $quote->update(['status' => 'inviato']);
        $this->assertSame('preventivo_inviato', $request->fresh()->status);

        $quote->update(['status' => 'rifiutato']);
        $this->assertSame('preventivo_rifiutato', $request->fresh()->status);

        $quote->update(['status' => 'accettato']);
        $this->assertSame('preventivo_accettato', $request->fresh()->status);
    }

    public function test_sending_the_quote_from_the_panel_updates_the_request(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'D', 'email' => 'd@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $this->tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($this->tenant);

        $request = $this->request();
        $quote = $this->quote($request);

        QuoteResource::sendQuoteEmail($quote, ['recipient_email' => 'bar@example.it']);

        $this->assertSame('preventivo_inviato', $request->fresh()->status);
    }

    public function test_an_accepted_alternative_wins_and_a_pending_one_beats_the_rejected(): void
    {
        $request = $this->request();
        $this->quote($request, 'rifiutato');
        $pending = $this->quote($request, 'inviato');
        $this->assertSame('preventivo_inviato', $request->fresh()->status);

        $pending->update(['status' => 'accettato']);
        $this->assertSame('preventivo_accettato', $request->fresh()->status);
    }

    public function test_requests_closed_by_hand_are_left_alone(): void
    {
        foreach (['gestita', 'chiusa'] as $status) {
            $request = $this->request($status);
            $this->quote($request, 'inviato');

            $this->assertSame($status, $request->fresh()->status);
        }
    }

    public function test_moving_or_deleting_the_quote_updates_both_requests(): void
    {
        $first = $this->request();
        $second = $this->request();
        $quote = $this->quote($first, 'inviato');
        $this->assertSame('preventivo_inviato', $first->fresh()->status);

        $quote->update(['information_request_id' => $second->id]);
        $this->assertSame('in_lavorazione', $first->fresh()->status);
        $this->assertSame('preventivo_inviato', $second->fresh()->status);

        $quote->delete();
        $this->assertSame('in_lavorazione', $second->fresh()->status);

        $quote->restore();
        $this->assertSame('preventivo_inviato', $second->fresh()->status);
    }

    public function test_a_request_with_a_sent_quote_leaves_the_to_do_count(): void
    {
        $request = $this->request();
        $this->quote($request, 'inviato');

        $this->assertSame(0, InformationRequest::whereIn('status', ['nuova', 'in_lavorazione'])->count());
    }

    public function test_attaching_a_whole_offer_updates_the_request(): void
    {
        $group = QuoteGroup::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'inviato']);
        Quote::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'quote_group_id' => $group->id, 'date' => now(), 'status' => 'inviato']);
        $request = $this->request();

        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'D', 'email' => 'd@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $this->tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($this->tenant);

        \Livewire\Livewire::test(\App\Filament\Resources\InformationRequestResource\RelationManagers\QuotesRelationManager::class, [
            'ownerRecord' => $request,
            'pageClass' => \App\Filament\Resources\InformationRequestResource\Pages\EditInformationRequest::class,
        ])
            ->callTableAction('collegaOfferta', data: ['quote_group_id' => $group->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame('preventivo_inviato', $request->fresh()->status);
    }

    public function test_quote_page_shows_where_it_comes_from(): void
    {
        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'D', 'email' => 'd@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $this->tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($this->tenant);

        $request = InformationRequest::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'status' => 'nuova', 'request_details' => 'Vorrebbero una A600 a noleggio']);
        $quote = $this->quote($request);

        $this->get(QuoteResource::getUrl('view', ['record' => $quote]))
            ->assertOk()
            ->assertSee('Richiesta informazioni')
            ->assertSee($request->number)
            ->assertSee('Vorrebbero una A600 a noleggio');
    }
}
