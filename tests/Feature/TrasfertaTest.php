<?php

namespace Tests\Feature;

use App\Filament\Pages\RiepilogoOre;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Presenze\GiornataLavorativa;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regola dell'ufficio (21/09/2026): nel giorno di trasferta la prima ora oltre
 * il contratto la paga gia' l'indennita', che il dipendente la faccia o no.
 * Lo straordinario parte dall'ora dopo.
 */
class TrasfertaTest extends TestCase
{
    use RefreshDatabase;

    /** La tabella che l'ufficio ha scritto, riga per riga. */
    public function test_la_regola_sui_casi_dell_ufficio(): void
    {
        // [ore lavorate, straordinario atteso]
        foreach ([[8, 0], [9, 0], [10, 1], [11, 2]] as [$lavorate, $straordinario]) {
            $giornata = GiornataLavorativa::ripartisci($lavorate, 8, trasferta: true);

            $this->assertEquals($straordinario, $giornata->straordinario, "{$lavorate} ore in trasferta");
        }
    }

    /** Senza trasferta niente cambia: dalla nona ora e' straordinario come prima. */
    public function test_senza_trasferta_il_conto_e_quello_di_sempre(): void
    {
        $giornata = GiornataLavorativa::ripartisci(9, 8, trasferta: false);

        $this->assertEquals(8, $giornata->ordinarie);
        $this->assertEquals(0, $giornata->coperteDaTrasferta);
        $this->assertEquals(1, $giornata->straordinario);
    }

    /**
     * L'ora compresa non e' ordinaria: se lo fosse, rientrerebbe come
     * straordinario dal conteggio settimanale, che somma le ordinarie.
     */
    public function test_l_ora_compresa_non_e_ne_ordinaria_ne_straordinaria(): void
    {
        $giornata = GiornataLavorativa::ripartisci(9, 8, trasferta: true);

        $this->assertEquals(8, $giornata->ordinarie);
        $this->assertEquals(1, $giornata->coperteDaTrasferta);
        $this->assertEquals(0, $giornata->straordinario);
    }

    public function test_le_ore_comprese_vengono_dalla_configurazione(): void
    {
        config(['presenze.trasferta_ore_incluse' => 2]);

        $this->assertEquals(0, GiornataLavorativa::ripartisci(10, 8, trasferta: true)->straordinario);
        $this->assertEquals(1, GiornataLavorativa::ripartisci(11, 8, trasferta: true)->straordinario);
    }

    public function test_il_riepilogo_mensile_conta_giorni_e_straordinario(): void
    {
        [$tenant, $mario, $giorno] = $this->scenario();

        // 10 ore in trasferta: 1 di straordinario invece di 2.
        $this->turno($tenant, $mario, $giorno, 8, 18, 'Cortina');
        // Un giorno normale da 10 ore: 2 di straordinario, come sempre.
        $this->turno($tenant, $mario, $giorno->copy()->addDay(), 8, 18);

        $riga = $this->pagina($giorno)->getRows()->firstWhere('user', 'Mario Rossi');

        $this->assertEquals(16.0, $riga['ordinarie']);
        $this->assertEquals(3.0, $riga['straordinario']);
        $this->assertSame(1, $riga['trasferta_giorni']);
    }

    /** La trasferta vale per la giornata: basta segnarla su uno dei due turni. */
    public function test_basta_un_turno_in_trasferta_per_tutta_la_giornata(): void
    {
        [$tenant, $mario, $giorno] = $this->scenario();

        $this->turno($tenant, $mario, $giorno, 8, 12, 'Cortina'); // mattina, segnata
        $this->turno($tenant, $mario, $giorno, 13, 19);           // pomeriggio, no: 10 ore in tutto

        $riga = $this->pagina($giorno)->getDailyDetailRows()
            ->first(fn ($r) => $r['date']->isSameDay($giorno));

        $this->assertEquals(10.0, $riga['ore_lavorate']);
        $this->assertEquals(1.0, $riga['straordinario']);
        $this->assertSame('Cortina', $riga['trasferta']);
    }

    /** Il giorno in trasferta si conta una volta, anche con due turni segnati. */
    public function test_due_turni_in_trasferta_fanno_un_giorno(): void
    {
        [$tenant, $mario, $giorno] = $this->scenario();

        $this->turno($tenant, $mario, $giorno, 8, 12, 'Cortina');
        $this->turno($tenant, $mario, $giorno, 13, 17, 'Cortina');

        $riga = $this->pagina($giorno)->getRows()->firstWhere('user', 'Mario Rossi');

        $this->assertSame(1, $riga['trasferta_giorni']);
    }

    /** @return array{0: Tenant, 1: User, 2: Carbon} */
    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $mario = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@gifar.it',
            'password' => bcrypt('password'), 'daily_contract_hours' => 8,
        ]);

        $this->actingAs($mario);
        Filament::setTenant($tenant);

        // Un martedi' a inizio mese: lontano dal fine settimana e dal cambio
        // mese, cosi' il conteggio settimanale non si mette in mezzo.
        $giorno = now()->startOfMonth()->next(Carbon::TUESDAY);

        return [$tenant, $mario, $giorno];
    }

    private function turno(Tenant $tenant, User $user, Carbon $giorno, int $da, int $a, ?string $trasferta = null): void
    {
        TimeEntry::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'clock_in' => $giorno->copy()->setTime($da, 0),
            'clock_out' => $giorno->copy()->setTime($a, 0),
            'trasferta' => $trasferta !== null,
            'destinazione_trasferta' => $trasferta,
        ]);
    }

    private function pagina(Carbon $giorno): RiepilogoOre
    {
        $pagina = new RiepilogoOre();
        $pagina->mount();
        $pagina->month = $giorno->month;
        $pagina->year = $giorno->year;

        return $pagina;
    }
}
