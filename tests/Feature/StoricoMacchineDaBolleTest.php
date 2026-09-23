<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Il macinadosatore 0819352 (23/09/2026) risultava sempre stato all'Hotel
 * Principe Palace, mentre la bolla 205 del 28/04/2025 lo dava all'Hotel
 * Venezia per un anno: il sync guarda solo l'ultima bolla, le altre non
 * diventano mai un periodo nello storico.
 */
class StoricoMacchineDaBolleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $principe;

    private Customer $venezia;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->principe = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Principe Palace', 'gestionale_code' => 916]);
        $this->venezia = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Venezia di Federa Maria', 'gestionale_code' => 3198]);
    }

    /** @param array<int, array<int, array<string, mixed>>> $perCodice */
    private function eurekaDice(array $perCodice): void
    {
        Http::fake(function (Request $request) use ($perCodice) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response($perCodice[(int) ($q['q'] ?? 0)] ?? [], 200);
        });
    }

    private function bolla(string $matricola, int $numero, string $data): array
    {
        return ['id' => 1, 'matricola' => $matricola, 'numero_doc_t23' => $numero, 'data_documento' => $data.'T00:00:00.000+02:00'];
    }

    private function macchina(): MachineUnit
    {
        $m = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => '0819352-013489', 'model_name' => 'MACINADOSATORE SUPER JOLLY']);
        $m->moveTo($this->principe, 'Importata da Eureka, bolla n. 94', Carbon::parse('2024-01-01'));
        $m->moveTo($this->principe, 'Da Eureka: bolla n. 267', Carbon::parse('2026-04-20'));

        return $m->fresh();
    }

    public function test_il_periodo_di_mezzo_torna_nello_storico(): void
    {
        $m = $this->macchina();
        $this->eurekaDice([
            916 => [$this->bolla('-0819352', 94, '2024-01-01'), $this->bolla('0819352-013489', 267, '2026-04-20')],
            3198 => [$this->bolla('0819352-013489', 205, '2025-04-28')],
        ]);

        $this->artisan('macchine:storico-da-bolle', ['--tenant' => 'alex', '--dry-run' => true])
            ->expectsOutputToContain('0819352-013489')
            ->assertSuccessful();
        $this->assertSame(2, $m->placements()->count(), 'In prova non scrive.');

        $this->artisan('macchine:storico-da-bolle', ['--tenant' => 'alex'])
            ->expectsConfirmation('Scrivo lo storico di 1 macchine?', 'yes')
            ->assertSuccessful();

        $periodi = $m->placements()->reorder('placed_at')->get();

        $this->assertCount(3, $periodi);
        $this->assertSame([
            ['2024-01-01', '2025-04-28', $this->principe->id],
            ['2025-04-28', '2026-04-20', $this->venezia->id],
            ['2026-04-20', null, $this->principe->id],
        ], $periodi->map(fn ($p) => [
            $p->placed_at->toDateString(),
            $p->removed_at?->toDateString(),
            $p->customer_id,
        ])->all());

        // Dov'e' adesso non si tocca.
        $this->assertSame($this->principe->id, $m->fresh()->current_customer_id);
    }

    public function test_la_macchina_mai_spostata_a_mano_recupera_tutta_la_storia(): void
    {
        // Il caso vero in produzione: un solo posizionamento, aperto, con la
        // data della prima consegna. Se ci si fermasse li' non si
        // recupererebbe niente proprio dove serve (23/09/2026).
        $m = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => '0819352-013489', 'model_name' => 'MACINADOSATORE SUPER JOLLY']);
        $m->moveTo($this->principe, 'Importata da Eureka, bolla n. 94', Carbon::parse('2024-01-01'));

        $this->eurekaDice([
            916 => [$this->bolla('-0819352', 94, '2024-01-01'), $this->bolla('0819352-013489', 267, '2026-04-20')],
            3198 => [$this->bolla('0819352-013489', 205, '2025-04-28')],
        ]);

        $this->artisan('macchine:storico-da-bolle', ['--tenant' => 'alex'])
            ->expectsConfirmation('Scrivo lo storico di 1 macchine?', 'yes')
            ->assertSuccessful();

        $periodi = $m->placements()->reorder('placed_at')->get();

        $this->assertSame([
            ['2024-01-01', '2025-04-28', $this->principe->id],
            ['2025-04-28', '2026-04-20', $this->venezia->id],
            ['2026-04-20', null, $this->principe->id],
        ], $periodi->map(fn ($p) => [
            $p->placed_at->toDateString(),
            $p->removed_at?->toDateString(),
            $p->customer_id,
        ])->all());

        $this->assertSame($this->principe->id, $m->fresh()->current_customer_id);
    }

    public function test_se_adesso_e_da_un_altro_cliente_lo_spostamento_resta_al_sync(): void
    {
        // Spostata a mano alla Nuvola: l'ultima bolla dice Principe, ma qui
        // non si sposta niente — la propone il sync.
        $nuvola = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Pizzeria la Nuvola', 'gestionale_code' => 1422]);
        $m = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => '0819352-013489', 'model_name' => 'MACINADOSATORE']);
        $m->moveTo($this->principe, 'bolla 94', Carbon::parse('2024-01-01'));
        $m->moveTo($nuvola, 'Spostata a mano', Carbon::parse('2026-06-01'));

        $this->eurekaDice([
            916 => [$this->bolla('-0819352', 94, '2024-01-01'), $this->bolla('0819352-013489', 267, '2026-04-20')],
            3198 => [$this->bolla('0819352-013489', 205, '2025-04-28')],
            1422 => [],
        ]);

        $this->artisan('macchine:storico-da-bolle', ['--tenant' => 'alex'])
            ->expectsConfirmation('Scrivo lo storico di 1 macchine?', 'yes')
            ->assertSuccessful();

        $m->refresh();
        $this->assertSame($nuvola->id, $m->current_customer_id, 'Dov\'e\' adesso non si tocca.');
        $this->assertSame('2026-06-01', $m->placements()->whereNull('removed_at')->sole()->placed_at->toDateString());
        $this->assertSame($this->venezia->id, $m->placements()->whereDate('placed_at', '2025-04-28')->sole()->customer_id);
    }

    public function test_due_consegne_lo_stesso_giorno_da_clienti_diversi_si_saltano(): void
    {
        $m = $this->macchina();
        $this->eurekaDice([
            916 => [$this->bolla('0819352-013489', 94, '2024-01-01'), $this->bolla('0819352-013489', 267, '2026-04-20')],
            3198 => [$this->bolla('0819352-013489', 205, '2024-01-01')],
        ]);

        $this->artisan('macchine:storico-da-bolle', ['--tenant' => 'alex'])
            ->expectsOutputToContain('non si sa in che ordine')
            ->assertSuccessful();

        $this->assertSame(2, $m->placements()->count());
    }

    public function test_senza_buchi_non_tocca_niente(): void
    {
        $this->macchina();
        $this->eurekaDice([
            916 => [$this->bolla('0819352-013489', 267, '2026-04-20')],
            3198 => [],
        ]);

        $this->artisan('macchine:storico-da-bolle', ['--tenant' => 'alex'])
            ->expectsOutputToContain('gia\' quello che dicono le bolle')
            ->assertSuccessful();
    }

    public function test_con_un_elenco_non_letto_non_scrive_niente(): void
    {
        $m = $this->macchina();

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return (int) ($q['q'] ?? 0) === 3198
                ? Http::response('errore interno', 500)
                : Http::response([$this->bolla('0819352-013489', 94, '2024-01-01')], 200);
        });

        $this->artisan('macchine:storico-da-bolle', ['--tenant' => 'alex'])
            ->expectsOutputToContain('Eureka non ha risposto')
            ->assertFailed();

        $this->assertSame(2, $m->placements()->count());
    }
}
