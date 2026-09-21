<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EurekaFattura;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\SenzaFatturaCollegata as S;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perche' un rapportino su Eureka non ha una fattura collegata. Le cinque
 * regole vengono dai 150 casi guardati a mano il 21/09/2026: 18 recenti, 22
 * senza importo, 31 doppioni, 62 fatturati senza collegare la scheda, 17 da
 * verificare.
 */
class SenzaFatturaCollegataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cliente;

    private User $tecnico;

    private Material $articolo;

    private Carbon $oggi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Luminance', 'gestionale_code' => 900]);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Igor', 'email' => 'igor@alex.it', 'password' => bcrypt('x')]);
        $this->articolo = Material::create(['tenant_id' => $this->tenant->id, 'code' => 'ORE', 'category' => 'Eureka', 'type' => 'Manodopera', 'source' => Material::SOURCE_EUREKA]);
        $this->oggi = Carbon::parse('2026-09-21');
    }

    /** Un rapportino controllato su Eureka, senza fattura collegata. */
    private function rapportino(string $data, float $importo, array $extra = []): ServiceReport
    {
        $r = ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $extra['customer_id'] ?? $this->cliente->id,
            'technician_id' => $this->tecnico->id, 'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'intervention_date' => $data, 'status' => 'in_gestionale', 'eureka_service_report_id' => random_int(1, 999999),
        ]);
        $r->forceFill([
            'gestionale_document_date' => $data,
            'eureka_fatture_controllate_il' => now(),
            ...array_diff_key($extra, ['customer_id' => 1]),
        ])->saveQuietly();

        if ($importo > 0) {
            DB::table('service_report_materials')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'service_report_id' => $r->id, 'material_id' => $this->articolo->id,
                'quantity' => 1, 'unit_cost_snapshot' => $importo, 'line_total_snapshot' => $importo,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $r->fresh();
    }

    private function fatturaA(?string $clienteId, ?string $codice, string $numero, string $data): void
    {
        EurekaFattura::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'tipo' => 'cliente', 'id_eureka' => random_int(1, 999999),
            'customer_id' => $clienteId, 'gestionale_code' => $codice, 'ragione_sociale' => 'X',
            'numero_doc' => $numero, 'data_doc' => $data, 'totale_doc' => 100, 'imponibile' => 82,
        ]);
    }

    private function classifica(ServiceReport $r): array
    {
        return S::classifica($r, $this->oggi);
    }

    public function test_recente_il_mese_in_corso_e_quello_prima(): void
    {
        $this->assertSame([S::RECENTE, null], $this->classifica($this->rapportino('2026-08-03', 100)));
        $this->assertNotSame(S::RECENTE, $this->classifica($this->rapportino('2026-07-30', 100))[0]);
    }

    public function test_senza_importo(): void
    {
        $this->assertSame([S::SENZA_IMPORTO, null], $this->classifica($this->rapportino('2025-03-10', 0)));
    }

    /** Luminance: SL-902 gemella di SL-907, fatturata con la FT 479. */
    public function test_doppione_di_una_scheda_fatturata(): void
    {
        $fatturata = $this->rapportino('2025-11-12', 1938.86, ['eureka_fatturato_il' => '2025-11-07']);
        $doppione = $this->rapportino('2025-11-10', 1938.86);

        $this->assertSame([S::DOPPIONE, $fatturata->number], $this->classifica($doppione));
    }

    /** Stesso importo ma cliente diverso, o lontano nel tempo: non e' un doppione. */
    public function test_non_e_doppione_se_cambia_cliente_o_e_lontano(): void
    {
        $altro = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Altro bar']);
        $this->rapportino('2025-11-12', 250, ['eureka_fatturato_il' => '2025-11-30', 'customer_id' => $altro->id]);
        $this->rapportino('2025-06-01', 250, ['eureka_fatturato_il' => '2025-06-30']);

        $this->assertSame(S::DA_VERIFICARE, $this->classifica($this->rapportino('2025-11-10', 250))[0]);
    }

    /** Una fattura fatta a mano, senza schede collegate: plausibile che dentro ci sia anche questa. */
    public function test_probabile_se_la_fattura_e_fatta_a_mano(): void
    {
        $this->fatturaA($this->cliente->id, null, '82', '2025-03-15');

        $this->assertSame([S::FATTURA_NON_COLLEGATA, 'FT 82 del 15/03/2025'], $this->classifica($this->rapportino('2025-03-10', 120)));
    }

    /**
     * Una fattura fatta dalle schede, e questa non c'e': non e' "probabilmente
     * dentro", e' da controllare. Villa Gentile SL-516/2026: la FT 267 di
     * Martellozzo raccoglie gli interventi di giugno, ma non quello.
     */
    public function test_da_controllare_se_la_fattura_e_fatta_dalle_schede(): void
    {
        $this->fatturaA($this->cliente->id, null, '267', '2026-06-30');
        $altra = $this->rapportino('2026-06-25', 50);
        $altra->registraFattureEureka([['id_fattura' => 16953, 'tipo_doc' => 'FT', 'numero_fattura' => 267, 'data_fattura' => '2026-06-30T00:00:00.000+02:00']]);

        $this->assertSame(
            [S::NON_NELLA_FATTURA, 'FT 267 del 30/06/2026'],
            S::classifica($this->rapportino('2026-06-26', 120), Carbon::parse('2026-09-21')),
        );
    }

    /** Art & Food SL-54: la FT 31 e' intestata al pagante indicato sulla scheda. */
    public function test_la_fattura_puo_essere_intestata_a_chi_paga(): void
    {
        $this->fatturaA(null, '3046', '31', '2026-02-16');

        $conPagante = $this->rapportino('2026-02-06', 250, ['eureka_destinazione_code' => '3046']);
        $senzaPagante = $this->rapportino('2026-02-06', 250);

        $this->assertSame([S::FATTURA_NON_COLLEGATA, 'FT 31 del 16/02/2026'], $this->classifica($conPagante), 'Nessuna scheda sulla FT 31: fatta a mano.');
        $this->assertSame(S::DA_VERIFICARE, $this->classifica($senzaPagante)[0], 'SL-53: senza pagante, la FT 31 non e\' sua.');
    }

    /** Una fattura di tre mesi dopo non spiega niente. */
    public function test_una_fattura_troppo_lontana_non_conta(): void
    {
        $this->fatturaA($this->cliente->id, null, '200', '2025-07-01');

        $this->assertSame(S::DA_VERIFICARE, $this->classifica($this->rapportino('2025-03-10', 120))[0]);
    }

    public function test_da_verificare(): void
    {
        $this->assertSame([S::DA_VERIFICARE, null], $this->classifica($this->rapportino('2023-07-07', 2600)));
    }

    /** Trovata la fattura collegata, il perche' non serve piu'. */
    public function test_con_la_fattura_il_motivo_sparisce(): void
    {
        $r = $this->rapportino('2023-07-07', 2600);
        S::aggiorna($r, $this->oggi);
        $this->assertSame(S::DA_VERIFICARE, $r->fresh()->eureka_fattura_motivo);

        $r->registraFattureEureka([['id_fattura' => 1, 'tipo_doc' => 'FT', 'numero_fattura' => 5, 'data_fattura' => '2023-07-31T00:00:00+02:00']]);

        $this->assertNull($r->fresh()->eureka_fattura_motivo);
    }

    public function test_la_colonna_dice_il_motivo_con_la_prova(): void
    {
        $this->assertSame('doppione di RT-2025-0892', S::descrizione(S::DOPPIONE, 'RT-2025-0892'));
        $this->assertSame('probabile FT 479 del 07/11/2025 (fatta a mano)', S::descrizione(S::FATTURA_NON_COLLEGATA, 'FT 479 del 07/11/2025'));
        $this->assertSame('da controllare nella FT 267 del 30/06/2026', S::descrizione(S::NON_NELLA_FATTURA, 'FT 267 del 30/06/2026'));
        $this->assertSame('da verificare', S::descrizione(S::DA_VERIFICARE, null));
    }
}
