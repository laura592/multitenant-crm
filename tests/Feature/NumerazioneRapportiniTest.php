<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un numero CRM non cambia mai significato.
 *
 * L'ufficio stampa il riepilogo degli interventi e quei numeri finiscono su
 * carta: RT-2026-0579 era "Hotel Vidi Miramare" sulla stampa del 02/09/2026.
 * Riassegnarlo a una scheda importata avrebbe reso quella carta bugiarda,
 * quindi si va sempre avanti e i buchi lasciati dalle unioni restano — anzi,
 * dicono che li' c'e' stata un'unione.
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
     * Un rapportino archiviato TIENE il suo numero, che sia stato unito o
     * cancellato: puo' essere ripescato, e un numero che cambia significato
     * rende bugiarda una stampa gia' consegnata.
     */
    public function test_un_archiviato_tiene_il_numero_e_nessuno_lo_riusa(): void
    {
        $anno = date('Y');

        $nostro = $this->rapportino();                                    // 0001
        $copia = $this->rapportino(ServiceReport::SOURCE_EUREKA, 17814);  // 0002
        $scartato = $this->rapportino();                                  // 0003

        $nostro->update(['duplicato_suggerito_id' => $copia->id]);
        $nostro->confermaDuplicato();
        $scartato->delete();

        // La copia unita resta con il suo numero, archiviata.
        $this->assertSame("RT-{$anno}-0002", $copia->fresh()->number);
        $this->assertSoftDeleted('service_reports', ['id' => $copia->id]);

        // E il prossimo va avanti: nessuno dei due buchi si riempie.
        $this->assertSame("RT-{$anno}-0004", $this->rapportino()->number);

        // Il rapportino del tecnico non si e' mosso.
        $this->assertSame("RT-{$anno}-0001", $nostro->fresh()->number);
    }

}
