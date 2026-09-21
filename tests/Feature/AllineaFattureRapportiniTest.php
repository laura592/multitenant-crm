<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\FattureRapportino;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il giro notturno che annota su ogni rapportino la fattura Eureka della sua
 * scheda, e quello che ne vede l'ufficio nell'elenco (21/09/2026).
 */
class AllineaFattureRapportiniTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private const EUREKA = 'https://eureka.test';

    private Tenant $tenant;

    private Customer $cliente;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.eureka.base_url' => self::EUREKA,
            'services.eureka.username' => 'u',
            'services.eureka.password' => 'p',
        ]);

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Bar Centrale']);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Igor', 'email' => 'igor@alex.it', 'password' => bcrypt('x')]);
    }

    private function rapportino(?int $scheda, array $extra = []): ServiceReport
    {
        $r = ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->cliente->id, 'technician_id' => $this->tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => now(), 'status' => 'in_gestionale',
            'eureka_service_report_id' => $scheda,
        ]);

        // I campi delle fatture non sono fillable di proposito: li scrive solo
        // registraFattureEureka(). Qui servono per preparare lo scenario.
        if ($extra !== []) {
            $r->forceFill($extra)->saveQuietly();
        }

        return $r;
    }

    private function fattura(int $id, int $numero, string $data): array
    {
        return ['id_fattura' => $id, 'tipo_doc' => 'FT', 'numero_fattura' => $numero,
            'data_fattura' => "{$data}T00:00:00.000+02:00", 'id_bolla' => null, 'has_fe' => 1];
    }

    /** @param  array<int, array|int>  $perScheda  scheda => fatture, oppure scheda => codice HTTP */
    private function eureka(array $perScheda): void
    {
        Http::fake(function (Request $r) use ($perScheda) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $risposta = $perScheda[(int) ($q['q'] ?? 0)] ?? [];

            return is_int($risposta) ? Http::response('errore', $risposta) : Http::response($risposta);
        });
    }

    public function test_annota_fatturati_e_da_fatturare(): void
    {
        $fatturato = $this->rapportino(100);
        $daFatturare = $this->rapportino(200);
        $this->eureka([100 => [$this->fattura(9, 267, '2026-06-30')], 200 => []]);

        $this->artisan('eureka:allinea-fatture-rapportini', ['--tenant' => 'alex'])->assertSuccessful();

        $fatturato->refresh();
        $this->assertSame('2026-06-30', $fatturato->eureka_fatturato_il->toDateString());
        $this->assertSame('FT 267 del 30/06/2026', $fatturato->etichettaFatturaEureka());

        $daFatturare->refresh();
        $this->assertNull($daFatturare->eureka_fatturato_il);
        $this->assertNotNull($daFatturare->eureka_fatture_controllate_il, 'Controllato: non fatturato, non "mai chiesto".');
    }

    /** Una volta fatturato non si richiede piu': la notte dopo dura secondi, non minuti. */
    public function test_i_gia_fatturati_non_si_richiedono(): void
    {
        $this->rapportino(100, ['eureka_fatturato_il' => '2026-06-30']);
        $this->rapportino(200);
        $this->eureka([200 => []]);

        $this->artisan('eureka:allinea-fatture-rapportini', ['--tenant' => 'alex'])->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'q=200'));
    }

    public function test_con_tutti_ricontrolla_anche_i_fatturati(): void
    {
        $this->rapportino(100, ['eureka_fatturato_il' => '2026-06-30']);
        $this->eureka([100 => [$this->fattura(9, 267, '2026-06-30')]]);

        $this->artisan('eureka:allinea-fatture-rapportini', ['--tenant' => 'alex', '--tutti' => true])->assertSuccessful();

        Http::assertSentCount(1);
    }

    /**
     * Eureka giu' non e' "non fatturato": il rapportino resta com'era e si
     * riprova la notte dopo. I 500 a raffica di Eureka sono documentati.
     */
    public function test_se_eureka_non_risponde_non_si_tocca_niente(): void
    {
        $r = $this->rapportino(100);
        $this->eureka([100 => 500]);

        $this->artisan('eureka:allinea-fatture-rapportini', ['--tenant' => 'alex']);

        $r->refresh();
        $this->assertNull($r->eureka_fatture_controllate_il);
        $this->assertNull($r->eureka_fatturato_il);
    }

    public function test_un_rapportino_mai_andato_su_eureka_non_si_chiede(): void
    {
        $this->rapportino(null);
        $this->eureka([]);

        $this->artisan('eureka:allinea-fatture-rapportini', ['--tenant' => 'alex'])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_in_prova_non_scrive(): void
    {
        $r = $this->rapportino(100);
        $this->eureka([100 => [$this->fattura(9, 267, '2026-06-30')]]);

        $this->artisan('eureka:allinea-fatture-rapportini', ['--tenant' => 'alex', '--dry-run' => true])->assertSuccessful();

        $this->assertNull($r->refresh()->eureka_fatturato_il);
    }

    /**
     * E' una lettura dal gestionale su migliaia di righe: non deve finire nel
     * registro modifiche ne' cambiare "ultima modifica".
     */
    public function test_non_tocca_ultima_modifica(): void
    {
        $r = $this->rapportino(100);
        $prima = $r->fresh()->updated_at;
        $this->travel(1)->hour();
        $this->eureka([100 => [$this->fattura(9, 267, '2026-06-30')]]);

        $this->artisan('eureka:allinea-fatture-rapportini', ['--tenant' => 'alex'])->assertSuccessful();

        $this->assertEquals($prima, $r->fresh()->updated_at);
    }

    /** Aprendo il pulsante Fattura il dato si aggiorna subito, senza aspettare la notte. */
    public function test_il_modale_aggiorna_l_elenco(): void
    {
        $r = $this->rapportino(100);
        $this->eureka([100 => [$this->fattura(9, 267, '2026-06-30')]]);

        FattureRapportino::per($r);

        $this->assertSame('2026-06-30', $r->fresh()->eureka_fatturato_il->toDateString());
    }

    private function entraCome(string $ruolo): void
    {
        $utente = User::create(['tenant_id' => $this->tenant->id, 'name' => ucfirst($ruolo), 'email' => "{$ruolo}@alex.it", 'password' => bcrypt('x')]);
        $this->giveRole($utente, $this->tenant, $ruolo);
        $this->actingAs($utente);
        Filament::setTenant($this->tenant);
    }

    public function test_l_ufficio_filtra_i_non_fatturati(): void
    {
        $fatturato = $this->rapportino(100, ['eureka_fatturato_il' => '2026-06-30', 'eureka_fatture_controllate_il' => now()]);
        $daFatturare = $this->rapportino(200, ['eureka_fatture_controllate_il' => now()]);
        $maiControllato = $this->rapportino(300);
        $this->entraCome('admin');

        Livewire::test(ListServiceReports::class)
            ->filterTable('fatturazione', 'da_fatturare')
            ->assertCanSeeTableRecords([$daFatturare])
            ->assertCanNotSeeTableRecords([$fatturato, $maiControllato]);
    }

    /** Ai tecnici la fatturazione non serve: niente colonna, niente filtro. */
    public function test_i_tecnici_non_vedono_la_fatturazione(): void
    {
        $this->entraCome('dipendente');

        Livewire::test(ListServiceReports::class)
            ->assertTableColumnHidden('eureka_fatturato_il')
            ->assertTableFilterHidden('fatturazione');
    }
}
