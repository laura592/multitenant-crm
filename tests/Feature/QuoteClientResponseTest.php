<?php

namespace Tests\Feature;

use App\Mail\QuoteClientConfirmationMail;
use App\Mail\QuoteClientResponseMail;
use App\Mail\QuoteMail;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\QuoteProduct;
use App\Models\QuoteResponse;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Il link nella mail del preventivo: il cliente conferma firmando, rifiuta,
 * fa una domanda o chiede di essere richiamato (QuoteClientController).
 */
class QuoteClientResponseTest extends TestCase
{
    use RefreshDatabase;

    /** PNG 1x1 valido: basta a superare il controllo sui byte della firma. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');

        $this->tenant = Tenant::create([
            'name' => 'Alex', 'slug' => 'alex', 'is_master' => true,
            'client_contact_name' => 'Alessandro', 'client_contact_phone' => '+39 340 698 9095',
            'notify_quote_response_emails' => ['commerciale@alex.example'],
        ]);
    }

    private function quote(?QuoteGroup $group = null, string $status = 'inviato'): Quote
    {
        $customer = $group?->customer ?? Customer::create([
            'tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale', 'emails' => ['bar@example.it'],
        ]);
        $machine = Product::create(['sku' => 'WE8-'.uniqid(), 'type' => Product::TYPE_MACHINE, 'name' => 'WE8']);

        $quote = Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'quote_group_id' => $group?->id,
            'date' => now(), 'status' => $status,
        ]);
        QuoteProduct::create(['quote_id' => $quote->id, 'product_id' => $machine->id, 'quantity' => 1, 'price' => 2600, 'discount' => 0, 'tax' => 22]);
        $quote->updateTotal();

        return $quote->fresh();
    }

    public function test_la_pagina_si_apre_col_link_e_conta_la_visita(): void
    {
        $quote = $this->quote();

        $this->get($quote->clientUrl())
            ->assertOk()
            ->assertSee('Bar Centrale')
            ->assertSee('WE8')
            ->assertSee('Accetto e firmo')
            ->assertSee('Ho una domanda')
            ->assertSee('Non mi interessa')
            ->assertSee('+39 340 698 9095')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame(1, $quote->fresh()->client_view_count);
        $this->assertNotNull($quote->fresh()->client_last_viewed_at);
    }

    public function test_un_token_sbagliato_non_apre_niente(): void
    {
        $this->quote()->ensurePublicToken();

        $this->get('/preventivo/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_la_conferma_firmata_accetta_il_preventivo_e_avvisa_l_ufficio(): void
    {
        $quote = $this->quote();

        $this->post(route('client.quote.respond', ['token' => $quote->ensurePublicToken()]), [
            'type' => 'accettato',
            'quote_id' => $quote->id,
            'signer_name' => 'Mario Rossi',
            'signature' => self::SIGNATURE,
            'consent' => '1',
        ])->assertRedirect($quote->clientUrl())->assertSessionHasNoErrors();

        $this->assertSame('accettato', $quote->fresh()->status);

        $response = QuoteResponse::sole();
        $this->assertSame('Mario Rossi', $response->signer_name);
        $this->assertSame('bar@example.it', $response->email, 'si risponde all\'indirizzo del cliente, senza chiederglielo');
        Storage::disk('local')->assertExists($response->signature_path);
        Storage::disk('local')->assertExists($response->accepted_pdf_path);

        Mail::assertSent(QuoteClientResponseMail::class, fn ($mail) => $mail->hasTo('commerciale@alex.example') && count($mail->attachments()) === 1);
        Mail::assertSent(QuoteClientConfirmationMail::class, fn ($mail) => $mail->hasTo('bar@example.it'));

        // La firma sta dentro il PDF del preventivo, come su carta.
        $html = view('pdf.quote', [
            'quote' => $quote->fresh(), 'tenant' => $this->tenant,
            'acceptance' => \App\Filament\Resources\QuoteResource::onlineAcceptance($quote->fresh()),
        ])->render();
        $this->assertStringContainsString('Per accettazione', $html);
        $this->assertStringContainsString(Storage::disk('local')->path($response->signature_path), $html);
        $this->assertStringContainsString('Mario Rossi', $html);
    }

    public function test_senza_firma_non_si_accetta(): void
    {
        $quote = $this->quote();

        $this->post(route('client.quote.respond', ['token' => $quote->ensurePublicToken()]), [
            'type' => 'accettato', 'quote_id' => $quote->id, 'signer_name' => 'Mario Rossi', 'consent' => '1',
        ])->assertSessionHasErrors('signature');

        $this->assertSame('inviato', $quote->fresh()->status);
        $this->assertSame(0, QuoteResponse::count());
    }

    public function test_un_preventivo_gia_accettato_non_si_riaccetta_ne_si_rifiuta(): void
    {
        $quote = $this->quote(status: 'accettato');
        $url = route('client.quote.respond', ['token' => $quote->ensurePublicToken()]);

        $this->post($url, ['type' => 'rifiutato'])->assertSessionHasErrors('type');
        $this->post($url, [
            'type' => 'accettato', 'quote_id' => $quote->id, 'signer_name' => 'X', 'signature' => self::SIGNATURE, 'consent' => '1',
        ])->assertSessionHasErrors('type');

        $this->assertSame('accettato', $quote->fresh()->status);
    }

    public function test_nell_offerta_globale_la_soluzione_scelta_vince_e_le_altre_si_chiudono(): void
    {
        $customer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Mare', 'emails' => ['hotel@example.it']]);
        $group = QuoteGroup::create(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'status' => 'inviato']);
        $scelta = $this->quote($group);
        $altra = $this->quote($group);

        $this->get($group->clientUrl())->assertOk()->assertSee('Soluzione 2');
        $this->assertSame(1, $scelta->fresh()->client_view_count, 'la visita conta anche sui singoli preventivi');

        $this->post(route('client.quote.respond', ['token' => $group->public_token]), [
            'type' => 'accettato', 'quote_id' => $scelta->id, 'signer_name' => 'Anna', 'signature' => self::SIGNATURE, 'consent' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('accettato', $scelta->fresh()->status);
        $this->assertSame('rifiutato', $altra->fresh()->status);
        $this->assertSame('scelto', $group->fresh()->status);
    }

    public function test_per_ora_no_senza_motivo_chiude_il_preventivo(): void
    {
        $quote = $this->quote();

        $this->post(route('client.quote.respond', ['token' => $quote->ensurePublicToken()]), ['type' => 'rifiutato'])
            ->assertSessionHasNoErrors();

        $this->assertSame('rifiutato', $quote->fresh()->status);
    }

    public function test_si_possono_fare_piu_domande_anche_dopo_aver_accettato(): void
    {
        $quote = $this->quote(status: 'accettato');
        $url = route('client.quote.respond', ['token' => $quote->ensurePublicToken()]);

        $this->post($url, ['type' => 'domanda', 'message' => 'Quando arriva?'])->assertSessionHasNoErrors();
        $this->post($url, ['type' => 'domanda', 'message' => 'Serve la corrente trifase?'])->assertSessionHasNoErrors();

        $this->get($quote->clientUrl())
            ->assertSee('Quando arriva?')
            ->assertSee('Serve la corrente trifase?')
            ->assertDontSee('Accetto e firmo');
    }

    public function test_domanda_e_richiamata_non_cambiano_lo_stato(): void
    {
        $quote = $this->quote();
        $url = route('client.quote.respond', ['token' => $quote->ensurePublicToken()]);

        $this->post($url, ['type' => 'domanda', 'message' => 'Si può avere in nero?'])->assertSessionHasNoErrors();
        $this->post($url, ['type' => 'richiamata', 'phone' => '333 1234567', 'preferred_time' => 'mattina'])->assertSessionHasNoErrors();

        $this->assertSame('inviato', $quote->fresh()->status);
        $this->assertSame(['domanda', 'richiamata'], QuoteResponse::orderBy('created_at')->orderBy('type')->pluck('type')->all());
        Mail::assertSent(QuoteClientResponseMail::class, 2);
    }

    public function test_la_mail_del_preventivo_porta_il_link(): void
    {
        $quote = $this->quote();

        $html = (new QuoteMail($quote, '', '<p>Ciao</p>', clientUrl: $quote->clientUrl()))->render();

        $this->assertStringContainsString($quote->clientUrl(), $html);
        $this->assertStringContainsString('Accetta e firma', $html);
        $this->assertStringContainsString($quote->clientUrl().'?azione=domanda', $html);
        $this->assertStringContainsString($quote->clientUrl().'?azione=rifiuta', $html);
        $this->assertStringContainsString('+39 340 698 9095', $html);
    }
}
