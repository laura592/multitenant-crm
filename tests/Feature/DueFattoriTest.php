<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La 2FA del pannello (filament-breezy, TOTP) presa dall'inizio alla fine.
 *
 * Il giro era rotto e per questo ->enableTwoFactorAuthentication(force:)
 * in AdminPanelProvider e' rimasto a false: con force:true nessuno riusciva
 * ad attivarla, quindi nessuno riusciva piu' a entrare. Oggi il giro
 * funziona (verificato il 23/09/2026, dopo l'aggiornamento del pacchetto),
 * ma era un guasto dentro una dipendenza, cioe' il tipo di cosa che un
 * `composer update` puo' rimettere come prima senza dire niente.
 *
 * Questo test e' li' per quello: se la 2FA si rompe di nuovo si rompe qui,
 * non addosso a chi sta cercando di entrare.
 */
class DueFattoriTest extends TestCase
{
    use RefreshDatabase;

    private function utente(): User
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tizio', 'email' => 'tizio@alex.it', 'password' => bcrypt('x'),
        ]);

        $this->actingAs($utente);

        return $utente;
    }

    public function test_si_attiva_e_il_segreto_si_rilegge(): void
    {
        $utente = $this->utente();

        $this->assertFalse($utente->hasEnabledTwoFactor());

        $utente->enableTwoFactorAuthentication();
        $utente->refresh();

        $this->assertTrue($utente->hasEnabledTwoFactor());

        // Il punto esatto in cui si rompeva: BreezyCore::verify() legge
        // decrypt($user->two_factor_secret), che passa dall'accessor del
        // trait e quindi dalla riga in breezy_sessions.
        $this->assertNotSame('', decrypt($utente->two_factor_secret));
    }

    public function test_il_codice_giusto_passa_e_quello_sbagliato_no(): void
    {
        $utente = $this->utente();
        $utente->enableTwoFactorAuthentication();
        $utente->refresh();

        $breezy = filament('filament-breezy');
        $codice = $breezy->getEngine()->getCurrentOtp(decrypt($utente->two_factor_secret));

        $this->assertTrue($breezy->verify($codice, $utente));
        $this->assertFalse($breezy->verify('000000', $utente));
    }

    public function test_si_disattiva(): void
    {
        $utente = $this->utente();
        $utente->enableTwoFactorAuthentication();
        $utente->refresh();

        $utente->disableTwoFactorAuthentication();
        $utente->refresh();

        $this->assertFalse($utente->hasEnabledTwoFactor());
    }
}
