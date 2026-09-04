<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource;
use App\Mail\QuoteMail;
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

class QuoteDesignAndEmailTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function makeQuote(): Quote
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale', 'email' => 'bar@example.it']);
        $machine = Product::create(['sku' => 'A400-TEST', 'type' => Product::TYPE_MACHINE, 'name' => 'A400 Test']);

        $quote = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);
        QuoteProduct::create(['quote_id' => $quote->id, 'product_id' => $machine->id, 'quantity' => 1, 'price' => 6900, 'discount' => 0, 'tax' => 22]);
        $quote->updateTotal();

        return $quote->fresh();
    }

    private function loginAdmin(Tenant $tenant): User
    {
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'D', 'email' => 'd@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $user;
    }

    public function test_sending_quote_email_attaches_pdf_logs_and_marks_as_inviato(): void
    {
        Mail::fake();

        $quote = $this->makeQuote();
        $this->loginAdmin($quote->tenant);

        QuoteResource::sendQuoteEmail($quote, [
            'recipient_email' => 'cliente@example.it',
            'cc_email' => null,
            'custom_message' => 'Buongiorno,',
        ]);

        Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->hasTo('cliente@example.it') && $mail->quote->is($quote));

        $this->assertDatabaseHas('quote_emails', [
            'quote_id' => $quote->id,
            'recipient_email' => 'cliente@example.it',
            'status' => 'sent',
        ]);

        $this->assertSame('inviato', $quote->fresh()->status);
    }

    public function test_quote_mail_renders_rich_text_custom_message_and_tenant_footer(): void
    {
        $quote = $this->makeQuote();
        $quote->tenant->update([
            'legal_name' => 'Alex S.r.l. società unipersonale',
            'street' => 'Via dell\'Artigianato, 14/G',
            'postal_code' => '30020',
            'city' => 'Fossalta di Piave',
            'province' => 'VE',
            'phone' => '04211634773',
            'fax' => '04211634783',
            'email' => 'alexcaffe@pec.it',
            'vat_number' => '04412140271',
            'sdi' => 'EUVZNZV',
        ]);

        // Il RichEditor produce gia' HTML (grassetto via <strong>, non
        // sintassi Markdown **..**): deve arrivare intatto nella mail.
        $customMessage = '<p>Gentile cliente,</p><p><strong>€ 100,00 + IVA</strong></p>';

        $html = (new QuoteMail($quote, 'fake-pdf', $customMessage))->render();

        $this->assertStringContainsString('>Gentile cliente,</p>', $html);
        $this->assertStringContainsString('>€ 100,00 + IVA</strong>', $html);

        // Footer aziendale del tenant al posto del copyright generico.
        $this->assertStringContainsString('Alex S.r.l. società unipersonale', $html);
        $this->assertStringContainsString('Fossalta di Piave', $html);
        $this->assertStringContainsString('04412140271', $html);
        $this->assertStringContainsString('EUVZNZV', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function test_sending_twice_does_not_downgrade_an_already_accepted_quote(): void
    {
        Mail::fake();

        $quote = $this->makeQuote();
        $quote->update(['status' => 'accettato']);
        $this->loginAdmin($quote->tenant);

        QuoteResource::sendQuoteEmail($quote, ['recipient_email' => 'cliente@example.it']);

        // Solo una "bozza" diventa "inviato" automaticamente: uno stato gia'
        // avanzato (accettato) non deve regredire solo perche' si rimanda una copia.
        $this->assertSame('accettato', $quote->fresh()->status);
    }

    public function test_view_page_shows_line_items_once_totals_and_send_action_without_duplicate_relation_manager(): void
    {
        $quote = $this->makeQuote();
        $this->loginAdmin($quote->tenant);

        $response = $this->get(QuoteResource::getUrl('view', ['record' => $quote]));
        $response->assertOk();
        $response->assertSee('Preventivo');
        $response->assertSee('Crea offerta globale');
        $response->assertSee('Righe preventivo');
        $response->assertSee('A400 Test');
        $response->assertSee('Invia');
        $response->assertSee('PDF');

        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, 'Righe preventivo'), 'La sezione "Righe preventivo" non deve comparire due volte (infolist + RelationManager) sulla pagina di sola visualizzazione');
    }

    public function test_view_page_shows_global_offer_block_when_quote_is_grouped(): void
    {
        $quote = $this->makeQuote();
        $group = QuoteGroup::create([
            'tenant_id' => $quote->tenant_id,
            'customer_id' => $quote->customer_id,
            'status' => 'inviato',
        ]);
        $quote->update(['quote_group_id' => $group->id]);

        $this->loginAdmin($quote->tenant);

        $this->get(QuoteResource::getUrl('view', ['record' => $quote]))
            ->assertOk()
            ->assertSee('offerta globale')
            ->assertSee($group->number)
            ->assertSee('soluzioni alternative');
    }

    public function test_edit_page_shows_full_totals_breakdown(): void
    {
        $quote = $this->makeQuote();
        $this->loginAdmin($quote->tenant);

        $this->get(QuoteResource::getUrl('edit', ['record' => $quote]))
            ->assertOk()
            ->assertSee('Panoramica rapida')
            ->assertSee('Totali')
            ->assertSee('Imponibile')
            ->assertSee('Sconto generale')
            ->assertSee('IVA')
            ->assertSee('Ricalcola totali');
    }
}
