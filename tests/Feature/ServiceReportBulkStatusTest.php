<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Chiudere i rapportini uno per uno su un elenco da 18 pagine e' il lavoro di
 * una mattina: l'azione di massa "Cambia stato" li porta tutti nello stesso
 * stato in un colpo, senza pero' forzare quelli che il CRM non puo' piu'
 * toccare (gia' passati in Eureka, vedi ServiceReport::isLocked()).
 */
class ServiceReportBulkStatusTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_cambia_lo_stato_di_tutti_i_rapportini_selezionati(): void
    {
        [$tenant, $user, $customer] = $this->scenario();

        $uno = $this->rapportino($tenant, $customer, $user, 'bozza');
        $due = $this->rapportino($tenant, $customer, $user, 'bozza');

        Livewire::test(ListServiceReports::class)
            ->callTableBulkAction('cambia_stato', [$uno, $due], data: ['status' => 'completato']);

        $this->assertSame('completato', $uno->refresh()->status);
        $this->assertSame('completato', $due->refresh()->status);
    }

    /**
     * Un rapportino gia' in Eureka non si corregge piu' da CRM: l'azione
     * singola lo nasconde (EditAction->visible), quella di massa deve
     * saltarlo invece di scavalcare la regola in silenzio.
     */
    public function test_non_tocca_i_rapportini_gia_passati_in_eureka(): void
    {
        [$tenant, $user, $customer] = $this->scenario();

        $modificabile = $this->rapportino($tenant, $customer, $user, 'bozza');
        $bloccato = $this->rapportino($tenant, $customer, $user, 'inviato');
        $bloccato->update(['gestionale_sync_status' => 'sent']);

        Livewire::test(ListServiceReports::class)
            ->callTableBulkAction('cambia_stato', [$modificabile, $bloccato], data: ['status' => 'completato']);

        $this->assertSame('completato', $modificabile->refresh()->status);
        $this->assertSame('inviato', $bloccato->refresh()->status);
    }

    /**
     * Un rapportino nel cestino va prima ripristinato: cambiargli stato in
     * blocco lo riporterebbe "vivo" nei conteggi senza che sia mai
     * ricomparso in elenco.
     */
    public function test_non_tocca_i_rapportini_nel_cestino(): void
    {
        [$tenant, $user, $customer] = $this->scenario();

        $cestinato = $this->rapportino($tenant, $customer, $user, 'bozza');
        $cestinato->delete();

        Livewire::test(ListServiceReports::class)
            ->callTableBulkAction('cambia_stato', [$cestinato], data: ['status' => 'completato']);

        $this->assertSame('bozza', $cestinato->fresh()->status);
    }

    /** @return array{0: Tenant, 1: User, 2: Customer} */
    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');
        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale']);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        return [$tenant, $user, $customer];
    }

    /**
     * Lo stato "In gestionale" e' un'etichetta dell'ufficio, non una
     * serratura: dal 03/09/2026 si imposta a mano e il rapportino resta
     * modificabile finche' il documento non esiste davvero su Eureka.
     */
    public function test_lo_stato_in_gestionale_non_blocca_da_solo(): void
    {
        [$tenant, $user, $customer] = $this->scenario();

        $etichettato = $this->rapportino($tenant, $customer, $user, 'in_gestionale');

        $this->assertFalse($etichettato->isSuEureka());
        $this->assertFalse($etichettato->isLocked());

        Livewire::test(ListServiceReports::class)
            ->callTableBulkAction('cambia_stato', [$etichettato], data: ['status' => 'completato']);

        $this->assertSame('completato', $etichettato->refresh()->status);
    }

    /**
     * L'altra meta': un rapportino nostro unito al suo doppione importato
     * vive su Eureka pur non essendo mai passato da un invio CRM->Eureka.
     * Lo blocca l'aggancio alla scheda, non lo stato.
     */
    public function test_un_rapportino_agganciato_a_una_scheda_eureka_resta_bloccato(): void
    {
        [$tenant, $user, $customer] = $this->scenario();

        $unito = $this->rapportino($tenant, $customer, $user, 'completato');
        $unito->update(['eureka_service_report_id' => 17713]);

        $this->assertTrue($unito->isSuEureka());
        $this->assertTrue($unito->isLocked());

        Livewire::test(ListServiceReports::class)
            ->callTableBulkAction('cambia_stato', [$unito], data: ['status' => 'bozza']);

        $this->assertSame('completato', $unito->refresh()->status);
    }

    private function rapportino(Tenant $tenant, Customer $customer, User $tech, string $status): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'technician_id' => $tech->id,
            'intervention_date' => now(),
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'work_performed' => 'Intervento',
            'status' => $status,
        ]);
    }
}
