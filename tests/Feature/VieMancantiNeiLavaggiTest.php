<?php

namespace Tests\Feature;

use App\Console\Commands\VieMancantiNeiLavaggi;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Su 989 lavaggi, 293 non avevano il numero di vie — ed è il campo su cui si
 * fattura (24/09/2026). Il numero c'è quasi sempre, ma scritto altrove: nella
 * descrizione del tecnico, o nelle vie del piano.
 */
class VieMancantiNeiLavaggiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Camping Marina 2000']);
    }

    private function piano(int $vie): MaintenanceSchedule
    {
        return MaintenanceSchedule::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->cliente->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
            'status' => MaintenanceSchedule::STATUS_ATTIVO,
            'beverage_type' => 'birra',
            'lines_count' => $vie,
            'frequency' => 'mensile',
        ]);
    }

    private function lavaggio(?string $descrizione, ?MaintenanceSchedule $piano = null): Lavaggio
    {
        return Lavaggio::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->cliente->id,
            'maintenance_schedule_id' => $piano?->id,
            'data' => '2026-05-10',
            'descrizione' => $descrizione,
        ]);
    }

    public function test_le_vie_scritte_a_mano_si_leggono(): void
    {
        $this->assertSame(6, VieMancantiNeiLavaggi::vieDallaDescrizione('6 Vie + Chiusura'));
        $this->assertSame(1, VieMancantiNeiLavaggi::vieDallaDescrizione('1 Via'));
        $this->assertSame(2, VieMancantiNeiLavaggi::vieDallaDescrizione('2 Vie (Vino)'));
        $this->assertNull(VieMancantiNeiLavaggi::vieDallaDescrizione('Lavaggio Impianto'));
        $this->assertNull(VieMancantiNeiLavaggi::vieDallaDescrizione('Generato da rapportino RT-2026-0008'));
        $this->assertNull(VieMancantiNeiLavaggi::vieDallaDescrizione('40 Vie'), 'Oltre la dozzina e\' un errore di battitura.');
    }

    public function test_dalla_descrizione_e_dal_piano_in_quest_ordine(): void
    {
        $piano = $this->piano(4);
        $scritto = $this->lavaggio('6 Vie + Apertura', $piano);
        $dalPiano = $this->lavaggio('Generato da rapportino RT-2026-0008', $piano);
        $senzaNiente = $this->lavaggio('Lavaggio Impianto');

        $this->artisan('lavaggi:vie-mancanti', ['--tenant' => 'alex', '--dry-run' => true])
            ->expectsOutputToContain('Senza niente da cui ricavarle: 1')
            ->assertSuccessful();
        $this->assertNull($scritto->fresh()->lines_washed, 'In prova non scrive.');

        $this->artisan('lavaggi:vie-mancanti', ['--tenant' => 'alex'])
            ->expectsConfirmation('Scrivo le vie su 2 lavaggi?', 'yes')
            ->assertSuccessful();

        $this->assertSame(6, $scritto->fresh()->lines_washed, 'Vince quello che ha scritto il tecnico.');
        $this->assertSame(4, $dalPiano->fresh()->lines_washed, 'Se non l\'ha scritto, valgono le vie del piano.');
        $this->assertNull($senzaNiente->fresh()->lines_washed, 'Senza fonte non si inventa.');
    }

    public function test_con_solo_descrizione_il_piano_non_si_usa(): void
    {
        $piano = $this->piano(4);
        $dalPiano = $this->lavaggio('Generato da rapportino RT-2026-0008', $piano);

        $this->artisan('lavaggi:vie-mancanti', ['--tenant' => 'alex', '--solo-descrizione' => true, '--dry-run' => true])
            ->expectsOutputToContain('Da sistemare: 0')
            ->assertSuccessful();

        $this->assertNull($dalPiano->fresh()->lines_washed);
    }
}
