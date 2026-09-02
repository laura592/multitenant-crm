<?php

namespace Tests\Feature;

use App\Filament\Widgets\Contabilita\ScadutoOverviewWidget;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Regressione: aprire /admin/{tenant}/scaduto rimbalzava subito su
 * /sessione-scaduta. Il primo render della pagina andava a buon fine, ma il
 * caricamento lazy di ScadutoOverviewWidget subito dopo tornava 419 —
 * "componente non trovato", che Livewire riporta con lo stesso status di un
 * CSRF scaduto, e resources/js/app.js su quel 419 manda alla pagina
 * "sessione scaduta". Causa: Filament registra con Livewire solo i widget di
 * Panel::widgets() e Resource::getWidgets(), mai quelli che stanno solo in
 * Page::getHeaderWidgets().
 */
class PartiteAperteHeaderWidgetTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function adminSuTenant(): array
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Admin',
            'email' => 'admin@gifar.it',
            'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        return [$tenant, $user];
    }

    /**
     * L'invariante da proteggere non e' "il widget e' registrato" ma "il
     * widget non richiede un secondo giro Livewire": se il suo contenuto e'
     * gia' dentro l'HTML della pagina, quel giro non avviene e il 419 non
     * puo' ripresentarsi. Cosi' la garanzia vale sia registrandolo nel
     * pannello sia — come si e' scelto qui — rendendolo sincrono
     * (ScadutoOverviewWidget::$isLazy = false), senza legare il test a una
     * delle due implementazioni.
     */
    public function test_il_widget_di_testata_non_richiede_un_secondo_giro_livewire(): void
    {
        [$tenant, $user] = $this->adminSuTenant();

        $this->actingAs($user)
            ->get("/admin/{$tenant->slug}/scaduto")
            ->assertOk()
            // Contenuto reso dal widget, non un segnaposto di caricamento.
            ->assertSee('Da incassare');
    }

    public function test_il_widget_di_testata_si_carica_davvero(): void
    {
        [$tenant, $user] = $this->adminSuTenant();

        $this->actingAs($user)->get("/admin/{$tenant->slug}/scaduto")->assertOk();

        Livewire::test(ScadutoOverviewWidget::class)
            ->assertOk()
            ->assertSee('Da incassare');
    }
}
