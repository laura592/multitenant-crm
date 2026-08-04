<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Mail\CustomerGestionaleReviewMail;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class CustomerGestionaleReviewNotificationTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function makeTenantAndAdmin(): array
    {
        $tenant = Tenant::create([
            'name' => 'Alex',
            'slug' => 'alex',
            'notify_customer_gestionale_emails' => ['ufficio@alexcaffe.it'],
        ]);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@alex.it', 'password' => bcrypt('password')]);
        $this->giveRole($user, $tenant, 'admin');

        return [$tenant, $user];
    }

    public function test_accepting_a_quote_notifies_administration_once(): void
    {
        Mail::fake();
        [$tenant, $user] = $this->makeTenantAndAdmin();

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Nuovo', 'source' => Customer::SOURCE_APP]);
        $quote = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);

        $quote->update(['status' => 'accettato']);

        Mail::assertSent(CustomerGestionaleReviewMail::class, fn ($mail) => $mail->hasTo('ufficio@alexcaffe.it')
            && $mail->customer->is($customer));
        Mail::assertSentCount(1);
        $this->assertNotNull($customer->fresh()->approved_for_gestionale_at);

        // Riaccettare (es. doppio salvataggio) non deve rimandare l'email:
        // markApprovedForGestionale() e' idempotente e il nostro guard
        // sulla transizione null->valorizzato blocca il reinvio.
        $quote->update(['status' => 'bozza']);
        $quote->update(['status' => 'accettato']);
        Mail::assertSentCount(1);
    }

    public function test_editing_a_tracked_field_on_a_synced_customer_notifies_administration(): void
    {
        Mail::fake();
        [$tenant, $user] = $this->makeTenantAndAdmin();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gdp Italia SRL',
            'gestionale_code' => 1,
            'source' => Customer::SOURCE_GESTIONALE,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['emails' => ['item-1' => ['email' => 'nuova@gdpitalia.com']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $customer->refresh();
        $this->assertNotNull($customer->gestionale_review_flagged_at);
        $this->assertSame('Email', $customer->gestionale_review_note);

        Mail::assertSent(CustomerGestionaleReviewMail::class, fn ($mail) => $mail->hasTo('ufficio@alexcaffe.it')
            && $mail->customer->is($customer)
            && str_contains($mail->reason, 'Email'));
    }

    public function test_editing_an_untracked_field_does_not_notify(): void
    {
        Mail::fake();
        [$tenant, $user] = $this->makeTenantAndAdmin();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Gdp Italia SRL',
            'gestionale_code' => 1,
            'source' => Customer::SOURCE_GESTIONALE,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['website' => 'https://gdpitalia.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $customer->refresh();
        $this->assertNull($customer->gestionale_review_flagged_at);
        Mail::assertNothingSent();
    }

    public function test_editing_a_customer_without_gestionale_code_does_not_notify(): void
    {
        Mail::fake();
        [$tenant, $user] = $this->makeTenantAndAdmin();

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => 'Bar Senza Codice',
            'source' => Customer::SOURCE_APP,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->fillForm(['emails' => ['item-1' => ['email' => 'nuova@barsenzacodice.it']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $customer->refresh();
        $this->assertNull($customer->gestionale_review_flagged_at);
        Mail::assertNothingSent();
    }
}
