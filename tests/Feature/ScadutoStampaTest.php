<?php

namespace Tests\Feature;

use App\Filament\Pages\ScadutoClienti;
use App\Models\EurekaPartitaAperta;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Lo scaduto e' la lista con cui si telefona, e al telefono si segna a penna:
 * la stampa esce con quello che si vede a schermo (stesso ordine, stessa
 * ricerca) e senza paginazione — stampare solo i primi 25 di una lista
 * ordinata per urgenza vorrebbe dire perdere proprio chi va richiamato.
 */
class ScadutoStampaTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_la_pagina_scaduto_apre_il_pdf_in_una_scheda_nuova(): void
    {
        $this->scenario();

        $componente = Livewire::test(ScadutoClienti::class)->callAction('stampa');

        // JSON_UNESCAPED_SLASHES: senza, l'URL nel JSON e' "stampe\/..." e il
        // confronto fallirebbe per le barre sfuggite, non per il codice.
        $effetti = json_encode($componente->effects, JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('window.open', $effetti, 'la stampa non deve scaricarsi');
        $this->assertStringContainsString('/stampe/', $effetti);
    }

    /**
     * La stampa segue la ricerca: se a schermo ho filtrato per un cliente,
     * sul foglio non devono comparire gli altri.
     */
    public function test_la_stampa_rispetta_la_ricerca_fatta_a_schermo(): void
    {
        $this->scenario();

        $pagina = Livewire::test(ScadutoClienti::class)->set('tableSearch', 'Marco Polo');

        $righe = $pagina->instance()->getFilteredSortedTableQuery()->get();

        $this->assertCount(1, $righe);
        $this->assertStringContainsString('Marco Polo', $righe->first()->ragione_sociale);
    }

    private function scenario(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        // Staff master: le pagine contabili non passano piu' dai ruoli,
        // sono riservate a is_super_admin (vedi il loro canAccess()).
        $user->update(['is_super_admin' => true]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 3033, 'ragione_sociale' => 'Hotel Marco Polo',
            'anno' => 2026, 'numero_fattura' => '99',
            'data_fattura' => '2026-05-01', 'data_scadenza' => '2026-05-31', 'saldo' => 300.00,
        ]);
        EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => 18, 'ragione_sociale' => 'A & A SNC',
            'anno' => 2026, 'numero_fattura' => '43',
            'data_fattura' => '2026-02-28', 'data_scadenza' => '2026-02-28', 'saldo' => 174.92,
        ]);
    }
}
