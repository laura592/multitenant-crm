<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiepilogoGestionaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_riepilogo_gira_in_sola_lettura(): void
    {
        Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        $this->artisan('gestionale:riepilogo')
            ->expectsOutputToContain('RAPPORTINI NEL GESTIONALE: 0')
            ->expectsOutputToContain('VERIFICA SYNC GESTIONALE')
            ->assertSuccessful();
    }
}
