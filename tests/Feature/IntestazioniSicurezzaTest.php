<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Http\Middleware\SecurityHeaders. Globale, quindi vale anche sulle
 * rotte del pannello, che hanno una lista di middleware tutta loro.
 */
class IntestazioniSicurezzaTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_login_del_pannello_esce_con_le_intestazioni(): void
    {
        $risposta = $this->get('/admin/login');

        $risposta->assertOk();
        $risposta->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $risposta->assertHeader('X-Content-Type-Options', 'nosniff');
        $risposta->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $risposta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * Sulla pagina pubblica del preventivo il token del cliente sta DENTRO
     * la URL: li' NoIndex impone "no-referrer", piu' stretto del default, e
     * il middleware globale (che gira per ultimo) non deve sovrascriverlo.
     */
    public function test_sulla_pagina_del_cliente_resta_il_referrer_piu_stretto(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $preventivo = Quote::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $cliente->id,
            'date' => now(),
            'status' => 'inviato',
        ]);

        $risposta = $this->get(route('client.quote.show', ['token' => $preventivo->ensurePublicToken()]));

        $risposta->assertOk();
        $risposta->assertHeader('Referrer-Policy', 'no-referrer');
        $risposta->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        // Le altre continuano ad arrivare dal middleware globale.
        $risposta->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** In locale si lavora in http: un HSTS preso per sbaglio resta nel browser per mesi. */
    public function test_niente_hsts_fuori_da_https_in_produzione(): void
    {
        $this->get('/admin/login')->assertHeaderMissing('Strict-Transport-Security');
    }
}
