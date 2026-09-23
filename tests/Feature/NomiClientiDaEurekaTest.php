<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Cliente Eureka 2933" al posto del nome (23/09/2026): nasce quando
 * l'import dei rapportini trova una scheda intestata a un codice che nel
 * CRM non c'e', e l'elenco non porta la ragione sociale. Da li' non si
 * sistema piu' da solo — il sync cerca l'anagrafica per nome, e quel nome
 * su Eureka non esiste. Il nome vero sta nel dettaglio della scheda.
 */
class NomiClientiDaEurekaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
    }

    private function senzaNome(int $codice, int $scheda, int $intestatarioSullaScheda, string $nomeSullaScheda): Customer
    {
        $cliente = Customer::create([
            'tenant_id' => $this->tenant->id,
            'company_name' => 'Cliente Eureka '.$codice,
            'gestionale_code' => $codice,
        ]);

        $tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'T', 'email' => 't'.$codice.'@alex.it', 'password' => bcrypt('x')]);

        ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $cliente->id,
            'technician_id' => $tecnico->id,
            'source' => ServiceReport::SOURCE_EUREKA,
            'intervention_type' => 'riparazione',
            'intervention_date' => '2024-10-08',
            'eureka_service_report_id' => $scheda,
        ]);

        Http::fake([
            '*schedelavoro/'.$scheda => Http::response([
                'id_eureka' => $scheda,
                'intestatario' => ['id_eureka' => $intestatarioSullaScheda, 'rag_sociale' => $nomeSullaScheda],
            ], 200),
        ]);

        return $cliente;
    }

    public function test_il_nome_arriva_dalla_scheda(): void
    {
        $cliente = $this->senzaNome(2933, 9606, 2933, 'NARDIN VIA ADIGE (MARZIO)');

        $this->artisan('clienti:nomi-da-eureka', ['--tenant' => 'alex', '--dry-run' => true])
            ->expectsOutputToContain('NARDIN VIA ADIGE (MARZIO)')
            ->assertSuccessful();
        $this->assertSame('Cliente Eureka 2933', $cliente->fresh()->company_name, 'In prova non scrive.');

        $this->artisan('clienti:nomi-da-eureka', ['--tenant' => 'alex'])
            ->expectsConfirmation('Scrivo i 1 nomi?', 'yes')
            ->assertSuccessful();

        $this->assertSame('NARDIN VIA ADIGE (MARZIO)', $cliente->fresh()->company_name);

        $this->artisan('clienti:nomi-da-eureka', ['--tenant' => 'alex'])
            ->expectsOutputToContain('Nessun cliente senza nome')
            ->assertSuccessful();
    }

    public function test_una_scheda_intestata_a_un_altro_codice_non_rinomina_niente(): void
    {
        // Il rapportino e' attaccato al cliente sbagliato: rinominare
        // renderebbe l'errore invisibile.
        $cliente = $this->senzaNome(2933, 9606, 1198, 'TUTT\'ALTRA DITTA SRL');

        $this->artisan('clienti:nomi-da-eureka', ['--tenant' => 'alex'])
            ->expectsOutputToContain('Senza nome leggibile')
            ->assertSuccessful();

        $this->assertSame('Cliente Eureka 2933', $cliente->fresh()->company_name);
    }
}
