<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * L'elenco dei rapportini e' diviso per anno: con lo storico importato da
 * Eureka sono migliaia di righe, e quasi sempre si cerca quest'anno.
 */
class RapportiniPerAnnoTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_si_parte_dall_anno_in_corso_e_le_altre_schede_filtrano(): void
    {
        Carbon::setTestNow('2026-09-21');
        $this->rapportini(['RT-2024-0001' => '2024-12-31', 'RT-2025-0001' => '2025-01-01', 'RT-2026-0001' => '2026-03-10']);

        $pagina = Livewire::test(ListServiceReports::class);

        // Gli anni che esistono, dal piu' recente, e "Tutti" in fondo.
        $this->assertSame(['2026', '2025', '2024', 'tutti'], array_map('strval', array_keys($pagina->instance()->getCachedTabs())));

        $this->assertSame(['RT-2026-0001'], $this->numeri($pagina));
        $this->assertSame(['RT-2025-0001'], $this->numeri($pagina->set('activeTab', '2025')));
        $this->assertSame(['RT-2024-0001'], $this->numeri($pagina->set('activeTab', '2024')));
        $this->assertCount(3, $this->numeri($pagina->set('activeTab', 'tutti')));
    }

    /** A gennaio, prima del primo rapportino, non si apre su una scheda vuota. */
    public function test_se_quest_anno_non_c_e_niente_apre_l_ultimo_anno(): void
    {
        Carbon::setTestNow('2027-01-02');
        $this->rapportini(['RT-2026-0001' => '2026-12-20']);

        $this->assertSame(['RT-2026-0001'], $this->numeri(Livewire::test(ListServiceReports::class)));
    }

    private function numeri($pagina): array
    {
        return $pagina->instance()->getTableRecords()->pluck('number')->all();
    }

    /** @param  array<string, string>  $righe  numero => data intervento */
    private function rapportini(array $righe): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($utente, $tenant, 'admin');
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Hotel Neps']);

        foreach ($righe as $numero => $data) {
            ServiceReport::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $cliente->id,
                'technician_id' => $utente->id,
                'number' => $numero,
                'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
                'intervention_date' => $data,
            ]);
        }

        $this->actingAs($utente);
        Filament::setTenant($tenant);
    }
}
