<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * "alex s.r.l." non trovava "Alex SRL" e restituiva mezzo elenco
 * (22/09/2026): la ricerca sui telefoni, senza cifre, cercava "%%".
 */
class RicercaClientiTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_la_ricerca_ignora_la_punteggiatura_e_non_trova_tutti(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $utente = User::create(['tenant_id' => $tenant->id, 'name' => 'U', 'email' => 'u@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($utente, $tenant, 'admin');

        $alex = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Alex SRL Prova', 'city' => 'Fossalta di Piave']);
        $barRoma = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Roma', 'city' => 'Treviso']);
        $altroBar = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Roma', 'city' => 'Jesolo']);

        $this->actingAs($utente);
        Filament::setTenant($tenant);

        Livewire::test(ListCustomers::class)
            ->searchTable('alex s.r.l.')
            ->assertCanSeeTableRecords([$alex])
            ->assertCanNotSeeTableRecords([$barRoma, $altroBar])
            ->searchTable('bar roma treviso')
            ->assertCanSeeTableRecords([$barRoma])
            ->assertCanNotSeeTableRecords([$alex, $altroBar]);
    }
}
