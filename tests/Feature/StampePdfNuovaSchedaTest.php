<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Pdf\StampaTemporanea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le stampe del pannello si aprono in una scheda, non si scaricano
 * (indicazione dell'ufficio, 04/09/2026).
 *
 * Un'azione Filament che ritorna una response fa sempre partire un download:
 * il PDF viene quindi parcheggiato e aperto via URL. Qui si verifica il
 * pezzo che rende possibile il resto — che quell'URL serva il PDF *inline*,
 * e solo a chi l'ha generato.
 */
class StampePdfNuovaSchedaTest extends TestCase
{
    use RefreshDatabase;

    private function utente(string $email = 'a@alex.it'): User
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'alex'],
            ['name' => 'Alex', 'is_master' => true],
        );

        return User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tizio',
            'email' => $email, 'password' => bcrypt('x'),
        ]);
    }

    public function test_la_stampa_si_apre_inline_non_come_allegato(): void
    {
        $this->actingAs($this->utente());

        $url = StampaTemporanea::parcheggia('%PDF-1.4 finto', 'riepilogo-macchine.pdf');

        $risposta = $this->get($url);

        $risposta->assertOk();
        $risposta->assertHeader('Content-Type', 'application/pdf');
        // "inline" e' tutto il punto: con "attachment" il browser scarica
        // invece di aprire.
        $this->assertStringStartsWith('inline;', $risposta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('riepilogo-macchine.pdf', $risposta->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 finto', $risposta->getContent());
    }

    /**
     * La chiave e' un uuid, non indovinabile, ma un riepilogo scaduto clienti
     * non deve poter essere letto da un altro utente nemmeno con il link in
     * mano (cronologia condivisa, link incollato in chat).
     */
    public function test_la_stampa_di_un_altro_utente_non_si_apre(): void
    {
        $this->actingAs($this->utente('primo@alex.it'));
        $url = StampaTemporanea::parcheggia('%PDF-1.4 riservato', 'scaduto-clienti.pdf');

        $this->actingAs($this->utente('secondo@alex.it'));

        $this->get($url)->assertNotFound();
    }

    public function test_una_stampa_scaduta_non_si_apre(): void
    {
        $this->actingAs($this->utente());
        $url = StampaTemporanea::parcheggia('%PDF-1.4 vecchio', 'vecchio.pdf');

        $this->travel(11)->minutes();

        $this->get($url)->assertNotFound();
    }

    public function test_senza_login_non_si_arriva_alla_stampa(): void
    {
        $this->actingAs($this->utente());
        $url = StampaTemporanea::parcheggia('%PDF-1.4 finto', 'x.pdf');

        auth()->logout();

        $this->get($url)->assertRedirect();
    }
}
