<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La serie dei numeri CRM non deve lasciare buchi.
 *
 * Unire un doppione archivia la copia importata, e finche' quella teneva il
 * suo numero la serie si bucava: 63 buchi in due giorni di lavoro. Non si
 * rinumera nulla — 19 rapportini erano gia' partiti per email con il numero
 * stampato sul PDF — si libera il numero e lo si riassegna.
 */
class NumerazioneRapportiniTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->tecnico = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'T', 'email' => 't@alex.it', 'password' => bcrypt('x'),
        ]);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
    }

    private function rapportino(string $source = ServiceReport::SOURCE_MANUALE, ?int $eurekaId = null): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'technician_id' => $this->tecnico->id, 'source' => $source,
            'eureka_service_report_id' => $eurekaId,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'intervention_date' => '2026-08-06',
        ]);
    }

    public function test_i_numeri_si_assegnano_di_seguito(): void
    {
        $anno = date('Y');

        $this->assertSame("RT-{$anno}-0001", $this->rapportino()->number);
        $this->assertSame("RT-{$anno}-0002", $this->rapportino()->number);
        $this->assertSame("RT-{$anno}-0003", $this->rapportino()->number);
    }

    /**
     * Il numero di un rapportino archiviato resta occupato: qualcuno puo'
     * ancora ripescarlo, e due rapportini con lo stesso numero sarebbero
     * peggio di un buco.
     */
    public function test_un_rapportino_archiviato_tiene_il_suo_numero(): void
    {
        $anno = date('Y');
        $this->rapportino();
        $secondo = $this->rapportino();
        $this->rapportino();

        $secondo->delete();

        $this->assertSame("RT-{$anno}-0004", $this->rapportino()->number);
    }

    /** Unire libera il numero della copia, e il prossimo lo riusa. */
    public function test_unire_libera_il_numero_e_il_prossimo_lo_riusa(): void
    {
        $anno = date('Y');

        $nostro = $this->rapportino();                                        // 0001
        $copia = $this->rapportino(ServiceReport::SOURCE_EUREKA, 17814);      // 0002
        $terzo = $this->rapportino();                                         // 0003

        $this->assertSame("RT-{$anno}-0002", $copia->number);

        $nostro->update(['duplicato_suggerito_id' => $copia->id]);
        $nostro->confermaDuplicato();

        // La copia non occupa piu' la serie, ma resta rintracciabile.
        $this->assertSame('UNITO-17814', $copia->fresh()->number);
        $this->assertSoftDeleted('service_reports', ['id' => $copia->id]);

        // Il buco si richiude da solo.
        $this->assertSame("RT-{$anno}-0002", $this->rapportino()->number);
        $this->assertSame("RT-{$anno}-0004", $this->rapportino()->number);

        // Il rapportino del tecnico e il terzo non si sono mossi: nessun
        // numero gia' uscito cambia.
        $this->assertSame("RT-{$anno}-0001", $nostro->fresh()->number);
        $this->assertSame("RT-{$anno}-0003", $terzo->fresh()->number);
    }

    /** Le etichette fuori serie non devono confondere il contatore. */
    public function test_le_etichette_unito_non_contano_come_numeri(): void
    {
        $anno = date('Y');
        $copia = $this->rapportino(ServiceReport::SOURCE_EUREKA, 999);
        $copia->liberaNumero();

        $this->assertSame('UNITO-999', $copia->fresh()->number);
        $this->assertSame("RT-{$anno}-0001", $this->rapportino()->number);
    }
    /**
     * Il comando una tantum per gli ambienti dove si e' unito PRIMA che
     * liberaNumero() esistesse: la produzione al 03/09/2026.
     */
    public function test_il_comando_libera_i_numeri_delle_copie_gia_unite(): void
    {
        $anno = date('Y');

        // Una copia unita "alla vecchia maniera": archiviata, ma il numero
        // ancora suo, e la sua scheda ormai su un rapportino vivo.
        $nostro = $this->rapportino();                                   // 0001
        $copia = $this->rapportino(ServiceReport::SOURCE_EUREKA, 17814); // 0002
        $nostro->update(['eureka_service_report_id' => 17814]);
        $copia->delete();

        // Un rapportino archiviato per altri motivi: il suo numero NON si
        // tocca, puo' essere ripescato.
        $scartato = $this->rapportino();                                 // 0003
        $scartato->delete();

        $this->artisan('rapportini:libera-numeri-uniti', ['--tenant' => 'alex', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('UNITO-17814', $copia->fresh()->number);
        $this->assertSame("RT-{$anno}-0003", $scartato->fresh()->number);
        // Il buco lasciato dalla copia si richiude, quello dello scartato no.
        $this->assertSame("RT-{$anno}-0002", $this->rapportino()->number);
    }

    /** In prova a vuoto non scrive niente. */
    public function test_il_comando_in_prova_a_vuoto_non_tocca_i_numeri(): void
    {
        $anno = date('Y');
        $nostro = $this->rapportino();
        $copia = $this->rapportino(ServiceReport::SOURCE_EUREKA, 17814);
        $nostro->update(['eureka_service_report_id' => 17814]);
        $copia->delete();

        $this->artisan('rapportini:libera-numeri-uniti', ['--tenant' => 'alex', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame("RT-{$anno}-0002", $copia->fresh()->number);
    }

}
