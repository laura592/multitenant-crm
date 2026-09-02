<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Amministrazione" era un cassetto misto: il lavoro d'ufficio (scaduto,
 * scadenzario, automezzi) accanto alla configurazione del sistema (utenti,
 * aziende partner, log). E "Impostazioni" stava sopra, pur essendo la voce
 * che si apre piu' di rado.
 */
class NavigazioneGruppiTest extends TestCase
{
    use RefreshDatabase;

    public function test_impostazioni_e_l_ultimo_gruppo_del_menu(): void
    {
        $gruppi = array_keys($this->navigazione());

        $this->assertSame('Impostazioni', end($gruppi));
    }

    public function test_amministrazione_tiene_solo_il_lavoro_d_ufficio(): void
    {
        $amministrazione = $this->navigazione()['Amministrazione'];

        // In coda al gruppo, dopo le pagine di contabilita' (Scaduto clienti,
        // Analisi contabili) dove sono attive: qui si guarda solo la parte
        // stabile, cosi' il test non dipende da quelle pagine.
        $this->assertSame(['Scadenzario', 'Automezzi'], array_slice($amministrazione, -2));

        foreach (['Utenti', 'Ruoli', 'Aziende partner', 'Log modifiche'] as $configurazione) {
            $this->assertNotContains($configurazione, $amministrazione);
        }
    }

    public function test_la_configurazione_del_sistema_sta_sotto_impostazioni(): void
    {
        $impostazioni = $this->navigazione()['Impostazioni'];

        // Niente "Sync Eureka": la pagina si registra solo se il tenant ha
        // le credenziali del gestionale (GestionaleSyncReview::shouldRegisterNavigation()).
        foreach (['Utenti', 'Ruoli', 'Aziende partner', 'Log modifiche', 'Metodi di pagamento', 'Notifiche'] as $voce) {
            $this->assertContains($voce, $impostazioni);
        }

        // Utenti e Ruoli sono la stessa cosa vista da due lati: vanno letti
        // di fila, non uno in cima al gruppo e l'altro in mezzo.
        $this->assertSame(['Utenti', 'Ruoli'], array_slice($impostazioni, 0, 2));
    }

    /** @return array<string, array<int, string>> */
    private function navigazione(): array
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Staff',
            'email' => 'staff@gifar.it',
            'password' => bcrypt('password'),
            'is_super_admin' => true,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);

        $navigazione = [];

        foreach (Filament::getNavigation() as $gruppo) {
            $etichetta = $gruppo->getLabel();

            if (blank($etichetta)) {
                continue;
            }

            $navigazione[$etichetta] = collect($gruppo->getItems())
                ->map(fn ($voce) => $voce->getLabel())
                ->values()
                ->all();
        }

        return $navigazione;
    }
}
