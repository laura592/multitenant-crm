<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\ViewCustomer;
use App\Filament\Resources\OffertaCaffeResource;
use App\Filament\Resources\OffertaCaffeResource\Pages\CreateOffertaCaffe;
use App\Filament\Resources\OffertaCaffeResource\Pages\EditOffertaCaffe;
use App\Filament\Resources\QuoteGroupResource;
use App\Filament\Resources\QuoteGroupResource\Pages\EditQuoteGroup;
use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\QuoteResource\Pages\ViewQuote;
use App\Mail\OffertaCaffeMail;
use App\Mail\QuoteGroupMail;
use App\Mail\QuoteMail;
use App\Models\Customer;
use App\Models\OffertaCaffe;
use App\Models\ProdottoCaffe;
use App\Models\Quote;
use App\Models\QuoteGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Pdf\OffertaCaffePdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * L'offerta caffe' (21/09/2026): un documento a parte dal preventivo, coi
 * prezzi del listino caffe' ritoccabili per il singolo cliente e senza
 * quantita', perche' quanti chili prendera' non si sa.
 */
class OffertaCaffeTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create([
            'tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale', 'city' => 'Jesolo',
        ]);
    }

    private function entraCome(string $ruolo): void
    {
        $utente = User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($ruolo),
            'email' => "{$ruolo}@alex.it", 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $this->tenant, $ruolo);

        $this->actingAs($utente);
        Filament::setTenant($this->tenant);
    }

    /** Il listino dell'ufficio entra con la migrazione, cosi' arriva col deploy. */
    public function test_il_listino_iniziale_c_e(): void
    {
        $this->assertSame(9, ProdottoCaffe::count());
        $this->assertEquals(21.00, ProdottoCaffe::where('nome', 'Caffè Lyrae Bucintoro')->value('prezzo'));
        $this->assertSame(
            ['caffe' => 6, 'liofilizzati' => 3],
            ProdottoCaffe::query()->get()->countBy('gruppo')->sortKeys()->all(),
        );
    }

    /** Un prodotto spento resta in archivio ma non entra nelle nuove offerte. */
    public function test_un_prodotto_spento_non_si_offre(): void
    {
        ProdottoCaffe::where('nome', 'Cioccolato')->update(['attivo' => false]);

        $nomi = array_column(OffertaCaffePdf::righeDaListino(), 'nome');

        $this->assertNotContains('Cioccolato', $nomi);
        $this->assertCount(8, $nomi);
    }

    /**
     * I prezzi arrivano nel form gia' in formato italiano. Senza, la maschera
     * dei soldi legge il punto come migliaia: 17.80 diventerebbe 1.780.
     */
    public function test_nel_form_i_prezzi_arrivano_giusti(): void
    {
        $this->entraCome('admin');

        $pagina = Livewire::test(CreateOffertaCaffe::class);
        $riga = array_key_first($pagina->get('data.righe'));
        $pagina->set("data.righe.{$riga}.prodotto_caffe_id", ProdottoCaffe::where('nome', 'Cioccolato')->value('id'));

        $this->assertSame('17,80', $pagina->get("data.righe.{$riga}.prezzo"));
        $this->assertSame('Cioccolato', $pagina->get("data.righe.{$riga}.nome"));
    }

    /**
     * Il prezzo ritoccato per il cliente finisce sul foglio, e il listino non
     * cambia: e' un prezzo per questa offerta, non un nuovo prezzo di listino.
     */
    public function test_il_prezzo_ritoccato_va_sul_foglio_e_non_nel_listino(): void
    {
        $righe = OffertaCaffePdf::righeDaListino();
        $righe[0]['prezzo'] = 13.50; // Marco Polo, 15 a listino

        $html = $this->foglio($righe, 'Prezzi IVA esclusa.');

        $this->assertStringContainsString('€ 13,50', $html);
        $this->assertStringNotContainsString('€ 15,00', $html);
        $this->assertEquals(15.00, ProdottoCaffe::where('nome', 'Caffè Lyrae Marco Polo')->value('prezzo'));
    }

    /** Due gruppi, nell'ordine del listino: prima il caffe', poi i liofilizzati. */
    public function test_il_foglio_divide_caffe_e_liofilizzati(): void
    {
        $html = $this->foglio(OffertaCaffePdf::righeDaListino());

        $this->assertStringContainsString('Caffè Lyrae Bucintoro', $html);
        $this->assertStringContainsString('Orzo in polvere', $html);
        $this->assertLessThan(
            strpos($html, 'Prodotti liofilizzati'),
            strpos($html, '>Caffè<'),
            'Il caffè viene prima dei liofilizzati.',
        );
    }

    /** Il prezzo si legge giusto comunque sia scritto. */
    public function test_i_prezzi_si_leggono_in_ogni_formato(): void
    {
        $this->assertSame(17.8, OffertaCaffePdf::prezzo('17,80'));
        $this->assertSame(17.8, OffertaCaffePdf::prezzo('17.80'));
        $this->assertSame(17.8, OffertaCaffePdf::prezzo(17.8));
        $this->assertSame(1234.5, OffertaCaffePdf::prezzo('1.234,50'));
    }

    /** Niente quantita' ne' totale: e' un listino per il cliente, non una fornitura. */
    public function test_il_foglio_non_ha_quantita_ne_totale(): void
    {
        $html = $this->foglio(OffertaCaffePdf::righeDaListino());

        $this->assertStringNotContainsString('Quantità', $html);
        $this->assertStringNotContainsString('Totale', $html);
    }

    /** L'offerta la fa chi legge il listino, i tecnici no. */
    public function test_i_tecnici_non_fanno_offerte(): void
    {
        $this->entraCome('dipendente');

        $this->assertFalse(auth()->user()->can('viewAny', OffertaCaffe::class));
    }

    /** Dalla scheda cliente l'offerta caffe' non si fa piu'. */
    public function test_la_scheda_cliente_non_ha_il_pulsante(): void
    {
        $this->entraCome('admin');

        Livewire::test(ViewCustomer::class, ['record' => $this->cliente->getRouteKey()])
            ->assertActionDoesNotExist('offerta_caffe');
    }

    public function test_l_amministrazione_stampa_ma_non_cambia_il_listino(): void
    {
        $this->entraCome('amministrazione');

        $this->assertTrue(auth()->user()->can('create', OffertaCaffe::class));
        $this->assertFalse(auth()->user()->can('update', ProdottoCaffe::first()));
    }

    /**
     * Si parte vuoti e si scelgono i caffe' uno per uno dal listino, non da
     * tutto il listino da sfoltire. L'offerta si salva col suo numero.
     */
    public function test_si_crea_un_offerta_scegliendo_i_caffe(): void
    {
        $this->entraCome('admin');

        $pagina = Livewire::test(CreateOffertaCaffe::class);
        $righe = $pagina->get('data.righe');
        $this->assertCount(1, $righe, 'Si parte da una riga vuota, non da tutto il listino.');

        $riga = array_key_first($righe);
        $pagina->set('data.customer_id', $this->cliente->getKey())
            ->set("data.righe.{$riga}.prodotto_caffe_id", ProdottoCaffe::where('nome', 'Caffè Lyrae Bucintoro')->value('id'))
            ->set("data.righe.{$riga}.prezzo", '19,50')
            ->call('create')
            ->assertHasNoFormErrors();

        $offerta = OffertaCaffe::sole();
        $this->assertSame('OC-'.date('Y').'-0001', $offerta->number);
        $this->assertSame('bozza', $offerta->status);
        $this->assertSame('Caffè Lyrae Bucintoro', $offerta->righe[0]['nome']);
        $this->assertSame('1 kg', $offerta->righe[0]['formato']);
        $this->assertEquals(19.5, $offerta->righe[0]['prezzo']);
    }

    /** Il listino che cambia non tocca un'offerta gia' fatta. */
    public function test_il_listino_che_cambia_non_tocca_l_offerta(): void
    {
        $this->entraCome('admin');
        $offerta = $this->offerta();

        ProdottoCaffe::where('nome', 'Caffè Lyrae Marco Polo')->update(['prezzo' => 16.00]);

        $html = view('pdf.offerta-caffe', OffertaCaffePdf::datiVista(
            $offerta->customer, $offerta->righe, $offerta->valida_fino, $offerta->note, $offerta->number, $offerta->date,
        ))->render();

        $this->assertStringContainsString('€ 15,00', $html);
        $this->assertStringContainsString($offerta->number, $html);
    }

    public function test_dall_offerta_si_apre_il_pdf(): void
    {
        $this->entraCome('admin');

        $componente = Livewire::test(EditOffertaCaffe::class, ['record' => $this->offerta()->getRouteKey()])
            ->callAction('pdf');

        $this->assertStringContainsString('window.open', json_encode($componente->effects, JSON_UNESCAPED_SLASHES));
    }

    /** Ogni invio resta sull'offerta: a chi, quando, chi l'ha mandata. */
    public function test_l_offerta_si_invia_e_resta_nello_storico(): void
    {
        Mail::fake();
        $this->entraCome('admin');
        $offerta = $this->offerta();

        Livewire::test(EditOffertaCaffe::class, ['record' => $offerta->getRouteKey()])
            ->callAction('invia', ['recipient_email' => 'bar@example.it'])
            ->assertHasNoActionErrors();

        Mail::assertSent(OffertaCaffeMail::class, fn (OffertaCaffeMail $mail) => $mail->hasTo('bar@example.it')
            && $mail->offerta->is($offerta)
            && count($mail->attachments()) === 1);

        $invio = $offerta->emails()->sole();
        $this->assertSame('bar@example.it', $invio->recipient_email);
        $this->assertSame(auth()->id(), $invio->user_id);
        $this->assertNull($invio->inviata_con);
        $this->assertSame('inviata', $offerta->fresh()->status);
    }

    /**
     * Inviando un preventivo si allega una delle offerte caffe' del cliente
     * (l'ultima e' gia' proposta), e l'invio finisce anche nel suo storico.
     */
    public function test_il_preventivo_parte_con_l_offerta_caffe_allegata(): void
    {
        Mail::fake();
        $this->entraCome('admin');
        $offerta = $this->offerta();
        $preventivo = $this->preventivo();

        $componente = Livewire::test(ViewQuote::class, ['record' => $preventivo->getRouteKey()])
            ->mountAction('send')
            ->setActionData(['recipient_email' => 'bar@example.it', 'allega_offerta_caffe' => true]);

        $this->assertSame($offerta->getKey(), $componente->get('mountedActionsData.0.offerta_caffe_id'));

        $componente->callMountedAction()->assertHasNoActionErrors();

        Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->offertaCaffePdf !== null
            && count($mail->attachments()) === 2);
        $this->assertSame("Preventivo {$preventivo->number}", $offerta->emails()->sole()->inviata_con);
    }

    /** Dall'invio del preventivo si puo' preparare un'offerta caffe' nuova, col +. */
    public function test_dall_invio_del_preventivo_si_crea_un_offerta_caffe(): void
    {
        $this->entraCome('admin');
        $preventivo = $this->preventivo();
        $cioccolato = ProdottoCaffe::where('nome', 'Cioccolato')->value('id');

        $componente = Livewire::test(ViewQuote::class, ['record' => $preventivo->getRouteKey()])
            ->mountAction('send')
            ->setActionData(['allega_offerta_caffe' => true])
            ->mountFormComponentAction('offerta_caffe_id', 'createOption', formName: 'mountedActionForm');

        $riga = array_key_first($componente->get('mountedFormComponentActionsData.0.righe'));
        $componente->set("mountedFormComponentActionsData.0.righe.{$riga}.prodotto_caffe_id", $cioccolato)
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors();

        $offerta = OffertaCaffe::sole();
        $this->assertTrue($offerta->customer->is($this->cliente));
        $this->assertSame($offerta->getKey(), $componente->get('mountedActionsData.0.offerta_caffe_id'));
    }

    /** Accesa l'offerta caffe', la mail del preventivo la nomina; spenta, la frase sparisce. */
    public function test_il_testo_della_mail_nomina_l_offerta_caffe(): void
    {
        $this->entraCome('admin');
        $this->offerta();

        $componente = Livewire::test(ViewQuote::class, ['record' => $this->preventivo()->getRouteKey()])
            ->mountAction('send')
            ->set('mountedActionsData.0.allega_offerta_caffe', true);

        $testo = $componente->get('mountedActionsData.0.custom_message');
        $this->assertStringContainsString('offerta per il caffè', $testo);
        $this->assertLessThan(strpos($testo, 'Restiamo a disposizione'), strpos($testo, 'offerta per il caffè'), 'La frase va prima dei saluti.');

        $componente->set('mountedActionsData.0.allega_offerta_caffe', false);
        $this->assertStringNotContainsString('offerta per il caffè', $componente->get('mountedActionsData.0.custom_message'));
    }

    public function test_anche_la_mail_del_gruppo_nomina_l_offerta_caffe(): void
    {
        $this->entraCome('admin');
        $this->offerta();
        $gruppo = QuoteGroup::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id, 'status' => 'bozza']);
        $this->preventivo()->update(['quote_group_id' => $gruppo->id]);

        $testo = Livewire::test(EditQuoteGroup::class, ['record' => $gruppo->getRouteKey()])
            ->mountAction('send')
            ->set('mountedActionsData.0.allega_offerta_caffe', true)
            ->get('mountedActionsData.0.email_body');

        $this->assertStringContainsString('offerta per il caffè', $testo);
    }

    public function test_senza_interruttore_il_preventivo_parte_da_solo(): void
    {
        Mail::fake();
        $this->entraCome('admin');
        $offerta = $this->offerta();

        QuoteResource::sendQuoteEmail($this->preventivo(), ['recipient_email' => 'bar@example.it']);

        Mail::assertSent(QuoteMail::class, fn (QuoteMail $mail) => $mail->offertaCaffePdf === null
            && count($mail->attachments()) === 1);
        $this->assertSame(0, $offerta->emails()->count());
    }

    public function test_anche_il_gruppo_parte_con_l_offerta_caffe_allegata(): void
    {
        Mail::fake();
        $this->entraCome('admin');
        $offerta = $this->offerta();
        $gruppo = QuoteGroup::create(['tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id, 'status' => 'bozza']);
        $this->preventivo()->update(['quote_group_id' => $gruppo->id]);

        QuoteGroupResource::sendGroupEmail($gruppo, [
            'recipient_email' => 'bar@example.it',
            'allega_offerta_caffe' => true,
            'offerta_caffe_id' => $offerta->getKey(),
        ]);

        Mail::assertSent(QuoteGroupMail::class, fn (QuoteGroupMail $mail) => count($mail->attachments()) === 2);
        $this->assertSame("Offerta {$gruppo->fresh()->number}", $offerta->emails()->sole()->inviata_con);
    }

    public function test_l_elenco_delle_offerte_si_apre(): void
    {
        $this->entraCome('admin');
        $offerta = $this->offerta();

        $this->get(OffertaCaffeResource::getUrl('index'))
            ->assertOk()
            ->assertSee($offerta->number)
            ->assertSee('Caffè Lyrae Marco Polo');
    }

    /** Nel listino iniziale ogni prodotto ha ormai il suo formato. */
    public function test_nessun_formato_da_completare(): void
    {
        $this->assertSame('500 g', ProdottoCaffe::where('nome', 'Cioccolato')->value('formato'));
        $this->assertSame(0, ProdottoCaffe::whereNull('formato')->count());
    }

    /** Il caffe' Lyrae e' tutto da 1 kg. */
    public function test_il_lyrae_e_da_un_chilo(): void
    {
        $this->assertSame(
            ['1 kg'],
            ProdottoCaffe::where('nome', 'like', '%Lyrae%')->pluck('formato')->unique()->values()->all(),
        );
    }

    private function offerta(): OffertaCaffe
    {
        return OffertaCaffe::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->cliente->id,
            'valida_fino' => now()->addDays(30),
            'note' => 'Prezzi IVA esclusa.',
            'righe' => [
                ['gruppo' => 'caffe', 'nome' => 'Caffè Lyrae Marco Polo', 'formato' => '1 kg', 'prezzo' => 15.0],
            ],
        ]);
    }

    private function preventivo(): Quote
    {
        return Quote::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'date' => now(), 'status' => 'bozza', 'discount' => 0,
        ]);
    }

    private function foglio(array $righe, ?string $note = null): string
    {
        $this->entraCome('admin');

        // Il contenuto si guarda sulla vista: dompdf comprime lo stream e
        // cercarci dentro un prezzo non direbbe niente.
        return view('pdf.offerta-caffe', OffertaCaffePdf::datiVista(
            $this->cliente, $righe, now()->addDays(30), $note,
        ))->render();
    }
}
