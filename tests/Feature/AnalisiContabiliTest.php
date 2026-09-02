<?php

namespace Tests\Feature;

use App\Models\EurekaFattura;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Le pagine con tabelle vanno provate CON DELLE RIGHE DENTRO.
 *
 * Lo smoke test generale carica ogni pagina su database vuoto: le closure
 * delle colonne (formatStateUsing, color, url...) non vengono mai eseguite,
 * quindi un errore al loro interno passa inosservato e si manifesta solo
 * all'utente. E' successo davvero: una closure dichiarava `fn (?int $s)`
 * invece di `$state`, e Filament — che risolve gli argomenti per NOME —
 * falliva con "was unresolvable" appena c'era un acconto da mostrare.
 */
class AnalisiContabiliTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_la_pagina_si_apre_con_gli_acconti_in_elenco(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        EurekaFattura::create([
            'tenant_id' => $tenant->id,
            'tipo' => EurekaFattura::TIPO_CLIENTE,
            'id_eureka' => 9001,
            'gestionale_code' => 3068,
            'ragione_sociale' => 'Hotel Europa Lignano',
            'numero_doc' => '28',
            'data_doc' => now()->subMonths(18)->toDateString(),
            'totale_doc' => 9150.00,
            'imponibile' => 7500.00,
            'pagamento' => 'B001',
            'e_acconto' => true,
        ]);

        // Un acconto gia' saldato non deve comparire.
        EurekaFattura::create([
            'tenant_id' => $tenant->id,
            'tipo' => EurekaFattura::TIPO_CLIENTE,
            'id_eureka' => 9002,
            'gestionale_code' => 3070,
            'ragione_sociale' => 'Cliente Saldato',
            'numero_doc' => '99',
            'data_doc' => now()->subMonths(3)->toDateString(),
            'totale_doc' => 1000.00,
            'e_acconto' => false,
        ]);

        $this->actingAs($user)
            ->get("/admin/{$tenant->slug}/analisi-contabili")
            ->assertOk()
            ->assertSee('Hotel Europa Lignano')
            ->assertSee('18 mesi')
            ->assertDontSee('Cliente Saldato');
    }
}
