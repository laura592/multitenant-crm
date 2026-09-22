<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Support\Gestionale\SpostamentiMacchine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Eureka non chiude la consegna vecchia: la stessa matricola compare presso
 * piu' clienti, ognuno con la sua bolla. Vale la piu' recente, e il sync
 * propone di spostare la macchina (22/09/2026, matricola 1863540: Agora'
 * Park Hotel dal 2024, Hotel Principe Palace dal 20/04/2026).
 */
class SpostamentiMacchineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $agora;

    private Customer $principe;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->agora = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => "Agora' Park Hotel", 'gestionale_code' => 4]);
        $this->principe = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Principe Palace', 'gestionale_code' => 916]);
    }

    private function macchina(string $matricola, Customer $presso, string $dal): MachineUnit
    {
        $m = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => $matricola, 'model_name' => 'FAEMA E98 UP']);
        $m->moveTo($presso, placedAt: Carbon::parse($dal));

        return $m->fresh();
    }

    private function consegna(Customer $c, ?string $data, int $bolla = 1): array
    {
        return ['cliente' => $c, 'data' => $data ? Carbon::parse($data) : null, 'bolla' => $bolla];
    }

    public function test_vale_la_bolla_piu_recente(): void
    {
        $m = $this->macchina('1863540', $this->agora, '2024-05-14');

        $p = SpostamentiMacchine::proposta($m, [$this->consegna($this->agora, '2024-05-14', 10), $this->consegna($this->principe, '2026-04-20', 57)], Carbon::parse('2024-05-14'));

        $this->assertSame($this->principe->id, $p['cliente']->id);
        $this->assertSame('2026-04-20', $p['data']->toDateString());
        $this->assertStringContainsString('bolla n. 57 del 20/04/2026', $p['motivo']);
    }

    public function test_niente_proposta_quando_non_si_puo_dire(): void
    {
        $m = $this->macchina('1523424', $this->agora, '2024-01-01');

        // Stesso giorno presso due clienti (il saldo iniziale): non si sa.
        $this->assertNull(SpostamentiMacchine::proposta($m, [$this->consegna($this->agora, '2024-01-01'), $this->consegna($this->principe, '2024-01-01')], Carbon::parse('2024-01-01')));
        // Eureka la da' dove la da' il CRM.
        $this->assertNull(SpostamentiMacchine::proposta($m, [$this->consegna($this->agora, '2025-01-01')], Carbon::parse('2024-01-01')));
        // Stesso giorno della posizione nel CRM ma cliente diverso: si propone.
        $this->assertNotNull(SpostamentiMacchine::proposta($m, [$this->consegna($this->agora, '2023-01-01'), $this->consegna($this->principe, '2024-01-01')], Carbon::parse('2024-01-01')));
        // Il CRM sa qualcosa di piu' recente (uno "Sposta" fatto a mano).
        $this->assertNull(SpostamentiMacchine::proposta($m, [$this->consegna($this->principe, '2023-06-01')], Carbon::parse('2024-01-01')));
    }

    public function test_il_sync_propone_e_la_conferma_sposta_con_la_data_della_bolla(): void
    {
        $m = $this->macchina('1863540', $this->agora, '2024-05-14');

        Http::fake(function (Request $request) {
            if (! str_contains($request->url(), 'art_installati')) {
                return Http::response([], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(match ((int) ($q['q'] ?? 0)) {
                4 => [['id' => 1, 'matricola' => '1863540', 'numero_doc_t23' => 10, 'data_documento' => '2024-05-14T00:00:00.000+02:00']],
                916 => [['id' => 1, 'matricola' => '1863540', 'numero_doc_t23' => 57, 'data_documento' => '2026-04-20T00:00:00.000+02:00']],
                default => [],
            }, 200);
        });

        $this->artisan('gestionale:sync')->assertExitCode(0);

        $m->refresh();
        $this->assertSame($this->principe->id, $m->spostamento_suggerito_customer_id);
        $this->assertSame($this->agora->id, $m->current_customer_id, 'Si propone e basta.');

        $this->assertTrue($m->accettaSpostamento());
        $m->refresh();
        $this->assertSame($this->principe->id, $m->current_customer_id);
        $this->assertSame('2026-04-20', $m->placements()->whereNull('removed_at')->sole()->placed_at->toDateString());
        $this->assertSame('2026-04-20', $m->placements()->where('customer_id', $this->agora->id)->sole()->removed_at->toDateString());
        $this->assertNull($m->spostamento_suggerito_customer_id);

        // Al sync dopo non c'e' piu' niente da proporre.
        $this->artisan('gestionale:sync')->assertExitCode(0);
        $this->assertNull($m->fresh()->spostamento_suggerito_customer_id);
    }

    public function test_scartata_non_ritorna(): void
    {
        $m = $this->macchina('1863540', $this->agora, '2024-05-14');
        $consegne = [$this->consegna($this->principe, '2026-04-20', 57)];
        $p = SpostamentiMacchine::proposta($m, $consegne, Carbon::parse('2024-05-14'));
        $m->update(['spostamento_suggerito_customer_id' => $p['cliente']->id, 'spostamento_suggerito_il' => $p['data'], 'spostamento_suggerito_motivo' => $p['motivo']]);

        $m->scartaSpostamento();

        $this->assertNull(SpostamentiMacchine::proposta($m->fresh(), $consegne, Carbon::parse('2024-05-14')));
        // Una bolla nuova si', quella torna a proporsi.
        $this->assertNotNull(SpostamentiMacchine::proposta($m->fresh(), [$this->consegna($this->principe, '2026-07-01', 80)], Carbon::parse('2024-05-14')));
    }
}
