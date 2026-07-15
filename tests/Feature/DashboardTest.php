<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardStatsWidget;
use App\Filament\Widgets\LatestQuotesWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Models\Customer;
use App\Models\Deadline;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_dashboard_page_itself_loads_ok(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);

        $this->actingAs($user)->get("/admin/{$tenant->slug}")->assertOk();
    }
}
