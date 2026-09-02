<?php

namespace Tests\Feature;

use App\Filament\Widgets\Sezioni\SezioneAndamento;
use App\Filament\Widgets\Sezioni\SezioneAzioniRapide;
use App\Filament\Widgets\Sezioni\SezioneDaFare;
use App\Filament\Widgets\Sezioni\SezioneMagazzino;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La dashboard era una sequenza piatta di card e tabelle: i titoli di sezione
 * (App\Filament\Widgets\Sezioni) separano cio' che va lavorato adesso dai
 * numeri di andamento e dal magazzino.
 */
class DashboardSezioniTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_l_admin_vede_la_dashboard_divisa_per_argomenti(): void
    {
        [$tenant, $user] = $this->tenantWithRole('admin');

        $this->get("/admin/{$tenant->slug}")
            ->assertOk()
            ->assertSee('Azioni rapide')
            ->assertSee('Da fare adesso')
            ->assertSee('Andamento commerciale')
            ->assertSee('Consistenza del catalogo materiali');
    }

    /**
     * Un titolo senza niente sotto e' peggio del titolo che manca: i widget
     * sono gia' filtrati per ruolo, la sezione deve seguirli.
     */
    public function test_la_sezione_sparisce_se_il_ruolo_non_vede_nessuno_dei_suoi_widget(): void
    {
        [$tenant, $user] = $this->tenantWithRole('dipendente');

        // Preventivi e catalogo materiali non sono di sua competenza.
        $this->assertFalse(SezioneAndamento::canView());
        $this->assertFalse(SezioneMagazzino::canView());

        // Timbratura e richieste informazioni si': quei due titoli restano.
        $this->assertTrue(SezioneAzioniRapide::canView());
        $this->assertTrue(SezioneDaFare::canView());

        $this->get("/admin/{$tenant->slug}")
            ->assertOk()
            ->assertDontSee('Andamento commerciale')
            ->assertDontSee('Consistenza del catalogo materiali');
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithRole(string $role): array
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test '.$role,
            'email' => $role.'@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, $role);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        return [$tenant, $user];
    }
}
