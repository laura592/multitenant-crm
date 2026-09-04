<?php

namespace Tests\Feature;

use App\Livewire\TourGuide;
use App\Models\Tenant;
use App\Models\TourView;
use App\Models\User;
use App\Support\HelpGuide\TourRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class TourGuideTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_registry_entries_have_a_title_and_text_on_every_step(): void
    {
        foreach (TourRegistry::entries() as $slug => $steps) {
            $this->assertNotEmpty($steps, "voce '{$slug}' senza passi");

            foreach ($steps as $i => $step) {
                $this->assertArrayHasKey('title', $step, "voce '{$slug}' passo {$i} senza titolo");
                $this->assertArrayHasKey('text', $step, "voce '{$slug}' passo {$i} senza testo");
            }
        }
    }

    /**
     * Il componente e' embeddato in OGNI pagina Filament via render hook
     * (AdminPanelProvider): deve reggere anche le pagine senza un tour
     * registrato, senza il fatal "missing root tag" di Livewire su un
     *
     * @if nudo senza else (successo davvero, vedi thread 2026-08-24).
     */
    public function test_renders_without_error_on_a_page_without_a_registered_tour(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password')]);
        $this->giveRole($user, $tenant, 'admin');

        $this->assertNull(TourRegistry::forSlug('pagina-inesistente'));

        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->get('/admin/'.$tenant->slug)
            ->assertOk()
            ->assertSeeLivewire(TourGuide::class);
    }

    /**
     * Il "non ripete due volte" dipende da TourView (una riga per
     * utente+pagina, unique su user_id+page_slug — vedi la migration):
     * verificato qui a livello di modello, la parte "parte da solo al
     * mount() se non ancora vista" e' gia' coperta end-to-end via
     * screenshot reali in browser durante lo sviluppo (Livewire::test()
     * isolato non ha il contesto di rotta necessario a
     * TourGuide::resolveSlugFromRoute(), non riproducibile qui in modo
     * significativo).
     */
    public function test_tour_view_is_recorded_once_per_user_and_page(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin2@gifar.it', 'password' => bcrypt('password')]);
        $this->giveRole($user, $tenant, 'admin');

        $this->assertFalse(
            TourView::where('user_id', $user->id)->where('page_slug', 'service-reports')->exists()
        );

        TourView::firstOrCreate(
            ['user_id' => $user->id, 'page_slug' => 'service-reports'],
            ['tenant_id' => $tenant->id, 'viewed_at' => now()],
        );
        TourView::firstOrCreate(
            ['user_id' => $user->id, 'page_slug' => 'service-reports'],
            ['tenant_id' => $tenant->id, 'viewed_at' => now()],
        );

        $this->assertSame(
            1,
            TourView::where('user_id', $user->id)->where('page_slug', 'service-reports')->count(),
        );
    }

    /**
     * Successo davvero in produzione 2026-08-25: un account super-admin ha
     * tenant_id NULL sul proprio User per design (vedi UserResource, Hidden
     * 'tenant_id' non dehydrated per is_super_admin) — markAsSeen() usava
     * Auth::user()->tenant_id per tour_views.tenant_id (NOT NULL, niente
     * default), quindi falliva con una violazione di integrita' ad ogni
     * chiusura del tour, su ogni pagina, per qualunque super-admin.
     */
    public function test_super_admin_closing_a_tour_does_not_violate_tenant_id_not_null(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $superAdmin = User::create([
            'tenant_id' => null,
            'is_super_admin' => true,
            'name' => 'Staff',
            'email' => 'staff@gifar.it',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($superAdmin);
        Filament::setTenant($tenant);

        Livewire::test(TourGuide::class)
            ->set('slug', 'service-reports')
            ->call('markAsSeen', 'service-reports');

        $view = TourView::where('user_id', $superAdmin->id)->where('page_slug', 'service-reports')->first();

        $this->assertNotNull($view);
        $this->assertSame($tenant->id, $view->tenant_id);
    }
}
