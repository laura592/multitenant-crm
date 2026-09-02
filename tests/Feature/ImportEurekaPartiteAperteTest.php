<?php

namespace Tests\Feature;

use App\Models\EurekaPartitaAperta;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le partite aperte sono una FOTOGRAFIA che si rifà da zero a ogni import:
 * si cancella e si riscrive, perché una fattura incassata sparisce da Eureka
 * e nessun ciclo sui dati nuovi la incontrerebbe mai.
 *
 * Il che rende la cancellazione la parte pericolosa. Da qui in poi la regola
 * è una sola: si cancella solo ciò che Eureka ha davvero riconfermato. Una
 * riga persa qui non è un buco visibile in una tabella — è un cliente che
 * scompare dall'elenco di chi va sollecitato, cioè una telefonata che non
 * viene fatta.
 */
class ImportEurekaPartiteAperteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * Se /contabilita/saldi va in errore mentre la lista fornitori risponde,
     * le partite clienti non si toccano.
     *
     * Prima le due liste finivano in un array unico con una sola DELETE: la
     * risposta dei fornitori bastava a rendere l'array non vuoto, la guardia
     * su "Eureka irraggiungibile" non scattava, e lo scaduto clienti si
     * svuotava di colpo senza un solo messaggio d'errore.
     */
    public function test_un_lato_in_errore_non_cancella_l_altro(): void
    {
        $tenant = $this->tenant();

        $preesistente = $this->partita($tenant, 3068, '513', EurekaPartitaAperta::TIPO_CLIENTE);

        Http::fake([
            '*contabilita/saldi/fornitori*' => Http::response([
                ['id_nominativo' => 900, 'codice' => '900', 'nominativo' => 'Fornitore', 'saldo' => -100],
            ], 200),
            '*contabilita/saldi*' => Http::response('errore interno', 500),
            '*partitaaperta/fornitore/900*' => Http::response([
                $this->partitaEureka('12', '2026-01-31', -100),
            ], 200),
        ]);

        $this->artisan('eureka:import-partite-aperte', ['--tenant' => 'alex'])->assertExitCode(1);

        $this->assertTrue(
            EurekaPartitaAperta::whereKey($preesistente->id)->exists(),
            'senza l\'elenco dei saldi clienti non si sa nemmeno chi ha una partita aperta: non si cancella niente'
        );
        $this->assertSame(1, EurekaPartitaAperta::where('tipo', EurekaPartitaAperta::TIPO_FORNITORE)->count());
    }

    /**
     * Il dettaglio si chiede un'anagrafica alla volta, e i 500 a raffica di
     * Eureka colpiscono una chiamata sì e una no. Quella fallita tornava
     * indistinguibile da "questo cliente non deve più niente": la sua riga
     * veniva cancellata e non riscritta, e il cliente spariva dall'elenco di
     * chi chiamare proprio mentre il debito era ancora lì.
     */
    public function test_l_anagrafica_col_dettaglio_fallito_conserva_le_righe_di_ieri(): void
    {
        $tenant = $this->tenant();

        $intatta = $this->partita($tenant, 3068, '513', EurekaPartitaAperta::TIPO_CLIENTE);
        $sostituita = $this->partita($tenant, 3070, 'vecchia', EurekaPartitaAperta::TIPO_CLIENTE);

        Http::fake([
            '*contabilita/saldi/fornitori*' => Http::response([], 200),
            '*contabilita/saldi*' => Http::response([
                ['id_nominativo' => 3068, 'codice' => '3068', 'nominativo' => 'Pasti Fabio', 'saldo' => 1658.83],
                ['id_nominativo' => 3070, 'codice' => '3070', 'nominativo' => 'Altro Cliente', 'saldo' => 200],
            ], 200),
            '*partitaaperta/3068*' => Http::response('errore interno', 500),
            '*partitaaperta/3070*' => Http::response([
                $this->partitaEureka('nuova', '2026-06-30', 200),
            ], 200),
        ]);

        $this->artisan('eureka:import-partite-aperte', ['--tenant' => 'alex'])->assertExitCode(0);

        $this->assertTrue(
            EurekaPartitaAperta::whereKey($intatta->id)->exists(),
            'il dettaglio non è arrivato: meglio la riga di ieri che nessuna riga'
        );
        $this->assertFalse(
            EurekaPartitaAperta::whereKey($sostituita->id)->exists(),
            'chi ha risposto viene riscritto da zero, come sempre'
        );
        $this->assertSame(
            'nuova',
            EurekaPartitaAperta::where('gestionale_code', 3070)->value('numero_fattura')
        );
    }

    /**
     * Una lista vuota è una risposta valida, non un errore: se non c'è più
     * niente di aperto la fotografia deve davvero svuotarsi, altrimenti si
     * continuerebbe a sollecitare chi ha già pagato.
     */
    public function test_una_risposta_vuota_svuota_davvero_la_fotografia(): void
    {
        $tenant = $this->tenant();
        $this->partita($tenant, 3068, '513', EurekaPartitaAperta::TIPO_CLIENTE);

        Http::fake([
            '*contabilita/saldi/fornitori*' => Http::response([], 200),
            '*contabilita/saldi*' => Http::response([], 200),
        ]);

        $this->artisan('eureka:import-partite-aperte', ['--tenant' => 'alex'])->assertExitCode(0);

        $this->assertSame(0, EurekaPartitaAperta::count());
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Alex', 'slug' => 'alex', 'is_master' => true,
            'notify_customer_gestionale_emails' => ['ufficio@alexcaffe.it'],
        ]);
    }

    private function partita(Tenant $tenant, int $codice, string $numero, string $tipo): EurekaPartitaAperta
    {
        return EurekaPartitaAperta::create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'gestionale_code' => $codice,
            'ragione_sociale' => 'Cliente '.$codice,
            'anno' => 2025,
            'numero_fattura' => $numero,
            'data_fattura' => '2025-12-15',
            'data_scadenza' => '2026-01-15',
            'saldo' => 1658.83,
        ]);
    }

    /** @return array<string, mixed> */
    private function partitaEureka(string $numero, string $scadenza, float $importo): array
    {
        return [
            'anno' => 2026,
            'numero_fattura' => $numero,
            'data_fattura' => '2026-01-02T00:00:00.000+01:00',
            'saldo_partita' => $importo,
            'movimenti' => [[
                'data_scadenza' => $scadenza.'T00:00:00.000+01:00',
                'dare' => $importo > 0 ? $importo : 0,
                'avere' => $importo < 0 ? -$importo : 0,
                'descrizione' => 'Fattura',
                'tipo_pagamento' => 'BONIFICO',
            ]],
        ];
    }
}
