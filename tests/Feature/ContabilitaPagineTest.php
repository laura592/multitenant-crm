<?php

namespace Tests\Feature;

use App\Filament\Pages\AnalisiContabili;
use App\Filament\Pages\CashFlow;
use App\Filament\Widgets\Contabilita\CashflowOverviewWidget;
use App\Filament\Widgets\Contabilita\FatturatoOverviewWidget;
use App\Filament\Widgets\Contabilita\RibaWidget;
use App\Filament\Widgets\Contabilita\SaldiDivergentiWidget;
use App\Models\EurekaCashflowMese;
use App\Models\EurekaCashflowVoce;
use App\Models\EurekaFattura;
use App\Models\EurekaFatturatoMese;
use App\Models\EurekaPartitaAperta;
use App\Models\EurekaSaldoAnagrafica;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Le pagine contabili vanno provate CON DEI DATI DENTRO: lo smoke test
 * generale le carica su database vuoto, dove nessuna closure di colonna e
 * nessun calcolo di riquadro viene mai eseguito.
 */
class ContabilitaPagineTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Amm', 'email' => 'amm@alex.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $this->tenant, 'admin');
        // Staff master: le pagine contabili non passano piu' dai ruoli,
        // sono riservate a is_super_admin (vedi il loro canAccess()).
        $user->update(['is_super_admin' => true]);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    /**
     * Il confronto anno su anno si ferma all'ultimo mese REGISTRATO, non al
     * mese di calendario: il 2026 al 2 settembre aveva sette mesi in
     * contabilità, e misurarli contro nove mesi del 2025 annunciava un calo
     * del 14% che era soltanto agosto mancante da una parte sola.
     */
    public function test_il_fatturato_confronta_periodi_della_stessa_lunghezza(): void
    {
        $anno = (int) now()->format('Y');

        // Quest'anno: due mesi da 100.
        foreach ([1, 2] as $mese) {
            $this->fatturato($anno, $mese, 100);
        }

        // L'anno scorso: gli stessi due mesi da 100, piu' altri dieci mesi.
        foreach (range(1, 12) as $mese) {
            $this->fatturato($anno - 1, $mese, 100);
        }

        $riquadro = Livewire::test(FatturatoOverviewWidget::class)->assertOk();

        // 200 contro 200: nessuna variazione. Contando tutto il 2025
        // (1.200) il confronto avrebbe detto -83%.
        $riquadro->assertSee('+0% sugli stessi mesi del '.($anno - 1));
        $riquadro->assertSee('gen–feb '.($anno - 1));
        $riquadro->assertSee('anno intero');
    }

    public function test_il_riquadro_riba_separa_cio_che_non_passa_mai_dallo_scaduto(): void
    {
        // Su RiBa: incassata dalla banca, non compare mai fra le partite.
        $this->fattura('10', 1220.00, 'R041');
        // Bonifico: e' questa che, se non paga, finisce nello scaduto.
        $this->fattura('11', 2440.00, 'B001');
        // Senza condizione: non si sa come si incassa.
        $this->fattura('12', 100.00, null);
        // Nota di credito: abbassa il fatturato ma non viaggia su nessun
        // canale, e contarla falserebbe la quota.
        $this->fattura('13', -500.00, 'B001');

        Livewire::test(RibaWidget::class)
            ->assertOk()
            ->assertSee('1.220,00')
            // 1220 su 3760 = 32%, calcolato senza la nota di credito.
            ->assertSee('32% del fatturato')
            ->assertSee('2.540,00')
            ->assertSee('fatture su cui non si sa come si incassa');
    }

    /**
     * Il caso che rende utile il controllo: Eureka dichiara un saldo e il
     * dettaglio non torna nessuna partita. Con una join interna questa riga
     * sparirebbe — ed è proprio quella da guardare.
     */
    public function test_i_saldi_divergenti_mostrano_anche_chi_non_ha_nessuna_partita(): void
    {
        $this->saldo(972, 'Impronta Snc', -1431.79);
        $this->saldo(1629, 'Spigola Srl', 1025.90);
        $this->partita(1629, '5', 105.41);
        // Coincidono: non deve comparire.
        $this->saldo(3033, 'Hotel Marco Polo', 300.00);
        $this->partita(3033, '7', 300.00);

        $righe = Livewire::test(SaldiDivergentiWidget::class)
            ->assertOk()
            ->assertSee('Impronta Snc')
            ->assertSee('Spigola Srl')
            ->assertDontSee('Hotel Marco Polo')
            ->instance()->getTableRecords();

        // Ordinate per scarto, il piu' grosso in cima.
        $this->assertSame([972, 1629], $righe->pluck('gestionale_code')->all());
        $this->assertEqualsWithDelta(-1431.79, (float) $righe->first()->scarto, 0.01);
    }

    /**
     * Il riquadro del cash flow conta SOLO il futuro: il periodo che Eureka
     * restituisce parte da gennaio, e sommare mesi già passati darebbe una
     * "previsione" che per metà è storia.
     */
    public function test_il_cash_flow_conta_solo_i_mesi_da_qui_in_avanti(): void
    {
        $passato = now()->subMonths(2);
        $futuro = now()->addMonth();

        $this->mese((int) $passato->format('Y'), (int) $passato->format('n'), entrate: 9999, uscite: 0);
        $this->mese((int) now()->format('Y'), (int) now()->format('n'), entrate: 1000, uscite: 400);
        $this->mese((int) $futuro->format('Y'), (int) $futuro->format('n'), entrate: 200, uscite: 900);

        Livewire::test(CashflowOverviewWidget::class)
            ->assertOk()
            // 1000 + 200, senza i 9999 di due mesi fa.
            ->assertSee('1.200,00')
            ->assertSee('1.300,00')
            // Il primo mese che chiude sotto zero e' quello prossimo.
            ->assertSee(ucfirst(mb_substr($futuro->translatedFormat('F'), 0, 3)).' '.$futuro->format('Y'));
    }

    public function test_le_pagine_si_aprono_con_i_dati_dentro(): void
    {
        $this->fatturato((int) now()->format('Y'), 1, 100);
        $this->fattura('10', 1220.00, 'R041');
        $this->saldo(972, 'Impronta Snc', -1431.79);
        $this->mese((int) now()->format('Y'), (int) now()->format('n'), 1000, 400);

        EurekaCashflowVoce::create([
            'tenant_id' => $this->tenant->id,
            'anno' => (int) now()->format('Y'), 'mese' => (int) now()->format('n'),
            'data_documento' => now()->subMonth(), 'data_scadenza' => now()->addWeek(),
            'numero' => '524/A', 'descrizione' => 'BEVCO SRL', 'tipo' => 'FTF',
            'importo_totale' => -982.69, 'importo' => -982.69,
        ]);

        // Una voce senza numero: la descrizione sotto il nome non deve
        // sbriciolarsi ne' mostrare "del —".
        EurekaCashflowVoce::create([
            'tenant_id' => $this->tenant->id,
            'anno' => (int) now()->format('Y'), 'mese' => (int) now()->format('n'),
            'data_documento' => null, 'data_scadenza' => now()->addWeeks(2),
            'numero' => null, 'descrizione' => 'FRANKE KAFFEEMASCHINEN AG', 'tipo' => 'FTF',
            'importo_totale' => -16587.89, 'importo' => -16587.89,
        ]);

        Livewire::test(AnalisiContabili::class)->assertOk();

        Livewire::test(CashFlow::class)
            ->assertOk()
            ->assertSee('BEVCO SRL')
            ->assertSee('Fattura fornitore')
            ->assertSee('n. 524/A')
            ->assertSee('FRANKE KAFFEEMASCHINEN AG');
    }

    private function fatturato(int $anno, int $mese, float $netto): void
    {
        EurekaFatturatoMese::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaFatturatoMese::TIPO_CLIENTE,
            'anno' => $anno, 'mese' => $mese, 'dare' => 0, 'avere' => 0, 'netto' => $netto,
        ]);
    }

    private function fattura(string $numero, float $totale, ?string $pagamento): void
    {
        EurekaFattura::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaFattura::TIPO_CLIENTE,
            'id_eureka' => (int) $numero, 'gestionale_code' => 3068,
            'ragione_sociale' => 'Cliente', 'numero_doc' => $numero,
            'data_doc' => Carbon::create((int) now()->format('Y'), 3, 1),
            'totale_doc' => $totale, 'imponibile' => $totale / 1.22, 'pagamento' => $pagamento,
        ]);
    }

    private function saldo(int $codice, string $nome, float $saldo): void
    {
        EurekaSaldoAnagrafica::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => $codice, 'ragione_sociale' => $nome, 'saldo' => $saldo,
        ]);
    }

    private function partita(int $codice, string $numero, float $saldo): void
    {
        EurekaPartitaAperta::create([
            'tenant_id' => $this->tenant->id, 'tipo' => EurekaPartitaAperta::TIPO_CLIENTE,
            'gestionale_code' => $codice, 'ragione_sociale' => 'Cliente '.$codice,
            'anno' => 2026, 'numero_fattura' => $numero,
            'data_fattura' => '2026-01-01', 'data_scadenza' => '2026-02-01', 'saldo' => $saldo,
        ]);
    }

    private function mese(int $anno, int $mese, float $entrate, float $uscite): void
    {
        EurekaCashflowMese::create([
            'tenant_id' => $this->tenant->id, 'anno' => $anno, 'mese' => $mese,
            'entrate' => $entrate, 'uscite' => $uscite,
            'entrate_ftc' => $entrate, 'uscite_ftf' => $uscite,
            'saldo_mese' => $entrate - $uscite, 'saldo_progressivo' => $entrate - $uscite,
        ]);
    }
}
