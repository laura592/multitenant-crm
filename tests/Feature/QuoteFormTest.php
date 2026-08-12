<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il form di creazione preventivo deve restare minimale: le righe prodotto
 * sono un dato da compilare DOPO (via wizard "Configura macchina"), non un
 * campo da vedere/riempire mentre si sta ancora creando il preventivo.
 */
class QuoteFormTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_create_page_hides_line_items_section(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'partner');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $response = $this->get(QuoteResource::getUrl('create', tenant: $tenant));

        $response->assertOk();
        $response->assertDontSee('Righe preventivo');
    }
}
