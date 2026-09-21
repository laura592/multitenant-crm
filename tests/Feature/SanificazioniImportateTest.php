<?php

namespace Tests\Feature;

use App\Console\Commands\ImportEurekaServiceReports;
use App\Models\Customer;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le sanificazioni impianto acqua che arrivano da Eureka (21/09/2026): prima
 * finivano tutte "riparazione" — 61 su 61 in produzione. Si riconoscono
 * dalla riga SANIFICAZIONE, non dal testo: "sanificazione" nei rapportini si
 * usa anche per il lavaggio birra, che e' un'altra cosa.
 */
class SanificazioniImportateTest extends TestCase
{
    use RefreshDatabase;

    private const EUREKA_ID = 17990;

    public function test_la_riga_sanificazione_basta(): void
    {
        $this->assertTrue(ImportEurekaServiceReports::haArticoloSanificazione(['dettaglio' => [
            ['codice' => 'CARTUCCIAAC'], ['codice' => ' sanificazione '],
        ]]));

        $this->assertFalse(ImportEurekaServiceReports::haArticoloSanificazione(['dettaglio' => [
            ['codice' => 'LAV2'], ['codice' => 'DISIN/RITIRO'],
        ]]), 'LAV2 e DISIN* (disinstallazione) non sono sanificazioni.');
    }

    public function test_una_scheda_nuova_con_la_sanificazione_entra_come_sanificazione(): void
    {
        $this->importa(lavorazione: '', righe: [['codice' => 'SANIFICAZIONE'], ['codice' => 'CARTUCCIAAC']]);

        $this->assertSame(ServiceReport::TYPE_SANIFICAZIONE, $this->rapportino()->intervention_type);
    }

    /** Se la scheda dice di essere altro, resta quello: si toglie solo il ripiego. */
    public function test_se_la_scheda_dice_installazione_resta_installazione(): void
    {
        $this->importa(lavorazione: 'Installazione depuratore', righe: [['codice' => 'SANIFICAZIONE']]);

        $this->assertSame(ServiceReport::TYPE_INSTALLAZIONE, $this->rapportino()->intervention_type);
    }

    /** Il lavaggio birra scritto "sanificazione" non diventa sanificazione. */
    public function test_la_parola_nel_testo_non_basta(): void
    {
        $this->importa(lavorazione: 'Sanificazione impianto spina 3 vie', righe: [['codice' => 'LAV2']]);

        $this->assertSame(ServiceReport::TYPE_RIPARAZIONE, $this->rapportino()->intervention_type);
    }

    public function test_il_comando_corregge_solo_le_riparazioni_con_la_riga(): void
    {
        [$tenant, $cliente, $tecnico] = $this->base();
        $sanif = Material::create(['tenant_id' => $tenant->id, 'code' => 'SANIFICAZIONE', 'category' => 'Eureka', 'type' => 'SANIFICAZIONE IMPIANTO ACQUA', 'source' => Material::SOURCE_EUREKA]);

        $daCorreggere = $this->rapportinoCon($tenant, $cliente, $tecnico, ServiceReport::TYPE_RIPARAZIONE, $sanif);
        $manutenzione = $this->rapportinoCon($tenant, $cliente, $tecnico, ServiceReport::TYPE_MANUTENZIONE_ORDINARIA, $sanif);
        $senzaRiga = $this->rapportinoCon($tenant, $cliente, $tecnico, ServiceReport::TYPE_RIPARAZIONE, null);

        $this->artisan('rapportini:correggi-sanificazioni', ['--tenant' => 'alex'])
            ->expectsConfirmation('Correggo 1 rapportini?', 'yes')
            ->assertSuccessful();

        $this->assertSame(ServiceReport::TYPE_SANIFICAZIONE, $daCorreggere->fresh()->intervention_type);
        $this->assertSame(ServiceReport::TYPE_MANUTENZIONE_ORDINARIA, $manutenzione->fresh()->intervention_type, 'Un tipo scelto non si tocca.');
        $this->assertSame(ServiceReport::TYPE_RIPARAZIONE, $senzaRiga->fresh()->intervention_type);
    }

    public function test_in_prova_non_scrive(): void
    {
        [$tenant, $cliente, $tecnico] = $this->base();
        $sanif = Material::create(['tenant_id' => $tenant->id, 'code' => 'SANIFICAZIONE', 'category' => 'Eureka', 'type' => 'SANIFICAZIONE IMPIANTO ACQUA', 'source' => Material::SOURCE_EUREKA]);
        $r = $this->rapportinoCon($tenant, $cliente, $tecnico, ServiceReport::TYPE_RIPARAZIONE, $sanif);

        $this->artisan('rapportini:correggi-sanificazioni', ['--tenant' => 'alex', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(ServiceReport::TYPE_RIPARAZIONE, $r->fresh()->intervention_type);
    }

    /** @return array{0: Tenant, 1: Customer, 2: User} */
    private function base(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $cliente = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Pizzeria la Strana Coppia', 'gestionale_code' => 399]);
        $tecnico = User::create(['tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 'tecnico@alex.it', 'password' => bcrypt('x')]);

        return [$tenant, $cliente, $tecnico];
    }

    private function rapportinoCon(Tenant $tenant, Customer $cliente, User $tecnico, string $tipo, ?Material $riga): ServiceReport
    {
        $r = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'technician_id' => $tecnico->id,
            'intervention_type' => $tipo, 'intervention_date' => '2026-09-18', 'status' => 'in_gestionale',
        ]);

        if ($riga) {
            DB::table('service_report_materials')->insert([
                'id' => (string) Str::uuid(), 'service_report_id' => $r->id, 'material_id' => $riga->id,
                'quantity' => 1, 'unit_cost_snapshot' => 110, 'line_total_snapshot' => 110,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $r;
    }

    private function importa(string $lavorazione, array $righe): void
    {
        [, , $tecnico] = $this->base();
        $id = self::EUREKA_ID;

        Http::fake(function ($request) use ($id, $lavorazione, $righe) {
            if (preg_match('#/schedelavoro/'.$id.'(\?|$)#', $request->url())) {
                return Http::response([
                    'id_eureka' => $id, 'numero' => 767, 'data' => '2026-09-18T00:00:00.000+02:00',
                    'id_intestatario' => 399, 'sl_articolo' => ['id_eureka' => 1, 'codice' => 'ACQUA', 'descr1' => 'IMPIANTO ACQUA'],
                    'sl_matricola' => '', 'sl_sintomo' => '', 'sl_lavorazione' => $lavorazione,
                    'stato_documento' => 10, 'note' => '',
                    'dettaglio' => array_map(fn ($r) => $r + ['descrizione' => $r['codice'], 'quantita' => 1, 'prezzo' => 0], $righe),
                ], 200);
            }

            if (str_contains($request->url(), '/schedelavoro/')) {
                return Http::response([['id' => $id, 'id_codice_f15' => 399, 'data_documento' => '2026-09-18T00:00:00.000+02:00']], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('eureka:import-service-reports', [
            '--tenant' => 'alex', '--technician' => $tecnico->email,
            '--from' => '2026-09-01', '--to' => '2026-09-30', '--with-detail' => true,
        ])->assertExitCode(0);
    }

    private function rapportino(): ServiceReport
    {
        return ServiceReport::where('eureka_service_report_id', self::EUREKA_ID)->firstOrFail();
    }
}
