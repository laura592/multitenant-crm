<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardStatsWidget;
use App\Filament\Widgets\FailedGestionaleServiceReportsWidget;
use App\Filament\Widgets\LatestQuotesWidget;
use App\Filament\Widgets\PrioritaWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Models\Customer;
use App\Models\Deadline;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_dashboard_widgets_render_real_data(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Dashboard']);
        Quote::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => now()]);
        $deadline = $tenant->deadlines()->create([
            'tenant_id' => $tenant->id, 'type' => Deadline::TYPE_CONTRATTO, 'due_date' => now()->addDays(5),
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(DashboardStatsWidget::class)
            ->assertSee('Preventivi questo mese')
            ->assertSee('1'); // il preventivo appena creato

        Livewire::test(LatestQuotesWidget::class)
            ->assertSee('Bar Dashboard');

        Livewire::test(UpcomingDeadlinesWidget::class)
            ->assertSee('Contratto');

        $this->assertTrue($deadline->isUrgent());
    }

    /**
     * "Scadenze urgenti: 4" non dice cosa fare: la card deve dire anche quale
     * e' la prima e fra quanto scade, altrimenti per saperlo tocca aprire lo
     * scadenzario e riordinarlo a mano.
     */
    public function test_urgent_deadlines_stat_names_the_first_one_and_when_it_expires(): void
    {
        [$tenant, $user] = $this->tenantWithAdmin();

        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'plate' => 'AB123CD']);
        $vehicle->deadlines()->create([
            'tenant_id' => $tenant->id,
            'type' => Deadline::TYPE_REVISIONE,
            'due_date' => today()->addDays(3),
            'reminder_days_before' => 30,
        ]);

        Livewire::test(PrioritaWidget::class)
            ->assertSee('Scadenze urgenti')
            ->assertSee('AB123CD')
            ->assertSee('fra 3 giorni');
    }

    /**
     * Con zero scadenze urgenti la card era rossa comunque (->color('danger')
     * cablato) e diceva solo "Entro il periodo di preavviso": nessun segnale
     * che invece va tutto bene.
     */
    public function test_urgent_deadlines_stat_is_reassuring_when_there_is_nothing_urgent(): void
    {
        [$tenant, $user] = $this->tenantWithAdmin();

        Livewire::test(PrioritaWidget::class)
            ->assertSee('Nessuna scadenza entro il preavviso')
            ->assertDontSee('Prima:');
    }

    /**
     * La tabella mostrava tipo + data ("Assicurazione — 15/09"), senza dire di
     * quale mezzo si trattasse ne' quanto manca.
     */
    public function test_upcoming_deadlines_table_shows_what_the_deadline_refers_to(): void
    {
        [$tenant, $user] = $this->tenantWithAdmin();

        $vehicle = Vehicle::create(['tenant_id' => $tenant->id, 'plate' => 'ZZ999YY']);
        $vehicle->deadlines()->create([
            'tenant_id' => $tenant->id,
            'type' => Deadline::TYPE_ASSICURAZIONE,
            'due_date' => today()->addDays(10),
        ]);

        Livewire::test(UpcomingDeadlinesWidget::class)
            ->assertSee('Collegata a')
            ->assertSee('ZZ999YY')
            ->assertSee('fra 10 giorni');
    }

    /**
     * Gli invii Eureka falliti riguardano chi governa la sincronizzazione
     * (admin/amministratore, gli unici con page_GestionaleSyncReview): al
     * tecnico che compila i rapportini e al profilo amministrazione che li
     * integra mostravano un errore su cui non possono intervenire.
     */
    public function test_failed_eureka_widget_is_only_for_who_runs_the_sync(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);

        foreach (['dipendente' => false, 'amministrazione' => false, 'admin' => true] as $role => $shouldSee) {
            $user = User::create([
                'tenant_id' => $tenant->id, 'name' => $role, 'email' => $role.'@gifar.it', 'password' => bcrypt('password'),
            ]);
            $this->giveRole($user, $tenant, $role);
            $this->actingAs($user);
            Filament::setTenant($tenant);

            $this->assertSame(
                $shouldSee,
                FailedGestionaleServiceReportsWidget::canView(),
                "Il ruolo {$role} non ha la visibilita' attesa sugli invii Eureka falliti."
            );
        }
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithAdmin(): array
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $this->actingAs($user);
        Filament::setTenant($tenant);

        return [$tenant, $user];
    }

    public function test_the_dashboard_page_itself_loads_ok(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);

        $this->actingAs($user)->get("/admin/{$tenant->slug}")->assertOk();
    }
}
