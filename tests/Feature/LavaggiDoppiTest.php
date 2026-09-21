<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "La Strana Coppia" (21/09/2026): la stessa visita di lavaggio compariva
 * ripetuta per birra, vino, bibite, piu' righe orfane senza piano.
 */
class LavaggiDoppiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    private MachineUnit $spina;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'La Strana Coppia']);
        $this->spina = MachineUnit::create([
            'tenant_id' => $this->tenant->id, 'current_customer_id' => $this->cliente->id,
            'serial_number' => 'IMP-SPINA-014', 'model_name' => 'Impianto spina',
        ]);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x')]);
    }

    private function piano(string $bevanda, int $vie = 2): MaintenanceSchedule
    {
        return MaintenanceSchedule::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'machine_unit_id' => $this->spina->id, 'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'beverage_type' => $bevanda, 'lines_count' => $vie, 'frequency_days' => 30,
            'status' => MaintenanceSchedule::STATUS_ATTIVO,
        ]);
    }

    private function lavaggio(array $dati): Lavaggio
    {
        return Lavaggio::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id,
            'data' => '2026-05-26', 'descrizione' => 'Lavaggio impianto', ...$dati,
        ]);
    }

    private function rapportino(): ServiceReport
    {
        return ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id, 'technician_id' => $this->tecnico->id,
            'intervention_type' => ServiceReport::TYPE_SANIFICAZIONE, 'intervention_date' => '2026-05-26',
            'status' => 'completato',
        ]);
    }

    public function test_lo_storico_mostra_una_riga_per_visita_e_impianto(): void
    {
        $birra = $this->piano(MaintenanceSchedule::BEVERAGE_BIRRA);
        $vino = $this->piano(MaintenanceSchedule::BEVERAGE_VINO);
        $bibite = $this->piano(MaintenanceSchedule::BEVERAGE_BIBITE, 1);

        $this->rapportino();

        $this->assertSame(3, $this->cliente->lavaggi()->count(), 'resta un lavaggio per piano: vie e scadenze sono per bevanda');

        $righe = $this->cliente->lavaggi()->perVisita()->get();
        $this->assertCount(1, $righe);
        $this->assertSame('Birra · Vino · Bibite', $righe->first()->visitLinesLabel());

        foreach ([$birra, $vino, $bibite] as $piano) {
            $this->assertNotNull($piano->fresh()->last_lavaggio_id, 'ogni piano ha la sua scadenza aggiornata');
        }
    }

    public function test_il_rapportino_toglie_le_righe_orfane_generiche_ma_non_le_note_vere(): void
    {
        $this->piano(MaintenanceSchedule::BEVERAGE_BIRRA);
        $rapportino = $this->rapportino();

        $orfana = $this->lavaggio(['service_report_id' => $rapportino->id, 'descrizione' => "Generato da rapportino {$rapportino->number}"]);
        $selz = $this->lavaggio(['service_report_id' => $rapportino->id, 'descrizione' => '5 Vie (Selz)']);

        $rapportino->save();

        $this->assertModelMissing($orfana);
        $this->assertModelExists($selz);
    }

    public function test_un_lavaggio_segnato_a_mano_lo_stesso_giorno_si_aggancia_al_rapportino(): void
    {
        $birra = $this->piano(MaintenanceSchedule::BEVERAGE_BIRRA);
        $amano = $this->lavaggio(['maintenance_schedule_id' => $birra->id, 'descrizione' => '2 vie + apertura']);

        $rapportino = $this->rapportino();

        $this->assertSame(1, $birra->lavaggi()->count(), 'niente gemello');
        $this->assertSame($rapportino->id, $amano->fresh()->service_report_id);
        $this->assertSame('2 Vie + Apertura', $amano->fresh()->descrizione);
    }

    public function test_il_comando_senza_esegui_non_cancella_e_con_esegui_si(): void
    {
        $birra = $this->piano(MaintenanceSchedule::BEVERAGE_BIRRA);
        $rapportino = $this->rapportino();
        $orfana = Lavaggio::withoutEvents(fn () => $this->lavaggio([
            'service_report_id' => $rapportino->id, 'descrizione' => "Generato da rapportino {$rapportino->number}",
        ]));
        $ripetuta = Lavaggio::withoutEvents(fn () => $this->lavaggio([
            'maintenance_schedule_id' => $birra->id, 'lines_washed' => 2, 'descrizione' => '2 vie',
        ]));

        $this->artisan('lavaggi:pulisci-doppi')->assertSuccessful();
        $this->assertModelExists($orfana);
        $this->assertModelExists($ripetuta);

        $this->artisan('lavaggi:pulisci-doppi --esegui')
            ->expectsConfirmation('Cancellare 2 lavaggi?', 'yes')
            ->assertSuccessful();

        $this->assertModelMissing($orfana);
        $this->assertModelMissing($ripetuta);

        $tenuta = $birra->lavaggi()->sole();
        $this->assertSame($rapportino->id, $tenuta->service_report_id);
        $this->assertSame(2, $tenuta->lines_washed, 'le vie della riga tolta passano a quella tenuta');
    }
}
