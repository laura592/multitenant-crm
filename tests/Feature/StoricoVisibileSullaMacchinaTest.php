<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource\Pages\ViewMachineUnit;
use App\Filament\Resources\MachineUnitResource\RelationManagers\PlacementsRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Lo storico dei posizionamenti si guarda dalla scheda della macchina: se
 * non compare li', non si vede da nessuna parte (23/09/2026).
 */
class StoricoVisibileSullaMacchinaTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_la_scheda_macchina_mostra_lo_storico_dei_posizionamenti(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $principe = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Hotel Principe Palace']);
        $venezia = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Hotel Venezia di Federa Maria']);

        $m = MachineUnit::create(['tenant_id' => $tenant->id, 'serial_number' => '0819352-013489', 'model_name' => 'MACINADOSATORE SUPER JOLLY']);
        $m->moveTo($principe, 'Importata da Eureka, bolla n. 94', Carbon::parse('2024-01-01'));
        $m->moveTo($venezia, 'Da Eureka: bolla n. 205', Carbon::parse('2025-04-28'));
        $m->moveTo($principe, 'Da Eureka: bolla n. 267', Carbon::parse('2026-04-20'));

        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        // La scheda deve registrare lo storico fra le sue tabelle (il
        // contenuto e' un componente a parte, che nei test non finisce
        // nell'HTML della pagina).
        $pagina = Livewire::test(ViewMachineUnit::class, ['record' => $m->getKey()])->assertSuccessful();

        $this->assertSame(
            [PlacementsRelationManager::class],
            array_values(array_map(
                fn ($manager) => is_string($manager) ? $manager : $manager->getRelationManager(),
                $pagina->instance()->getRelationManagers(),
            )),
        );

        // E la scheda racconta i periodi passati senza dover scorrere fino
        // alla tabella in fondo.
        $pagina
            ->assertSee('Prima di adesso')
            ->assertSee('28/04/2025 → 20/04/2026');

        Livewire::test(PlacementsRelationManager::class, ['ownerRecord' => $m->fresh(), 'pageClass' => ViewMachineUnit::class])
            ->assertSuccessful()
            ->assertSee('Hotel Venezia di Federa Maria')
            ->assertSee('Hotel Principe Palace')
            ->assertCountTableRecords(3);
    }
}
