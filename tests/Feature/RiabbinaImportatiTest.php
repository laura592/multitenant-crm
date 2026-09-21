<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportEmail;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\RiabbinaImportati;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dopo un import da Eureka, le schede che sono rapportini gia' fatti nel CRM
 * si uniscono a quelli, e i numeri restano progressivi (21/09/2026: di 58
 * rapportini recuperati, 35 erano doppioni del tecnico — Hotel Cambridge
 * RT-2026-0792 e RT-2026-0822).
 */
class RiabbinaImportatiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Customer $cambridge;

    private User $tecnico;

    private Material $chiamata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->cambridge = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Cambridge']);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Alessandro', 'email' => 'a@alex.it', 'password' => bcrypt('x')]);
        $this->chiamata = Material::create(['tenant_id' => $this->tenant->id, 'code' => 'CHIORD', 'category' => 'Eureka', 'type' => 'Chiamata', 'source' => Material::SOURCE_EUREKA]);
    }

    /** Un rapportino col suo numero; per le schede Eureka anche id e numero SL. */
    private function rapportino(string $numero, string $giorno, ?int $eureka = null, ?string $matricola = 'M-1', ?Customer $cliente = null, array $articoli = ['CHIORD'], ?string $impianto = null, string $tipo = ServiceReport::TYPE_RIPARAZIONE): ServiceReport
    {
        $r = new ServiceReport([
            'tenant_id' => $this->tenant->id, 'customer_id' => ($cliente ?? $this->cambridge)->id,
            'technician_id' => $this->tecnico->id, 'intervention_type' => $tipo,
            'intervention_date' => $giorno, 'status' => $eureka ? 'in_gestionale' : 'bozza',
            'machine_material_id' => $impianto ? $this->materiale($impianto)->id : null,
            'machine_serial_number' => $matricola,
            'source' => $eureka ? ServiceReport::SOURCE_EUREKA : ServiceReport::SOURCE_MANUALE,
        ]);
        $r->number = $numero;
        $r->forceFill(['eureka_service_report_id' => $eureka, 'gestionale_number' => $eureka ? "SL-{$eureka}/2026" : null])->save();

        foreach ($articoli as $codice) {
            DB::table('service_report_materials')->insert([
                'id' => (string) Str::uuid(), 'service_report_id' => $r->id, 'material_id' => $this->materiale($codice)->id,
                'quantity' => 1, 'unit_cost_snapshot' => 46.2, 'line_total_snapshot' => 46.2,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $r;
    }

    private function materiale(string $codice): Material
    {
        return Material::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'code' => $codice],
            ['category' => 'Eureka', 'type' => $codice, 'source' => Material::SOURCE_EUREKA],
        );
    }

    private function esegui(array $schede): array
    {
        return RiabbinaImportati::esegui(
            ServiceReport::withoutGlobalScopes()->whereIn('id', collect($schede)->pluck('id'))->with(['machineUnit', 'materialsUsed.material'])->get()
        );
    }

    private function numeri(): array
    {
        return ServiceReport::withTrashed()->orderBy('number')->pluck('number')->all();
    }

    /** Cambridge 11/09: il rapportino del tecnico prende la scheda, la copia sparisce. */
    public function test_il_doppione_si_unisce_al_rapportino_del_tecnico(): void
    {
        $tecnico = $this->rapportino('RT-2026-0792', '2026-09-11');
        $copia = $this->rapportino('RT-2026-0793', '2026-09-11', eureka: 752);

        $esito = $this->esegui([$copia]);

        $this->assertCount(1, $esito['uniti']);
        $tecnico->refresh();
        $this->assertSame(752, (int) $tecnico->eureka_service_report_id);
        $this->assertSame('SL-752/2026', $tecnico->gestionale_number);
        $this->assertSame('RT-2026-0792', $tecnico->number, 'Il rapportino del tecnico tiene il suo numero.');
        $this->assertNull(ServiceReport::withTrashed()->find($copia->id), 'La copia va via del tutto.');
        $this->assertSame(['RT-2026-0792'], $this->numeri());
    }

    /** Numeri progressivi: le schede nuove scendono a riempire il posto delle copie. */
    public function test_i_numeri_restano_progressivi(): void
    {
        $this->rapportino('RT-2026-0792', '2026-09-11');
        $copia = $this->rapportino('RT-2026-0793', '2026-09-11', eureka: 752);
        $altroCliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Solo su Eureka']);
        $nuova = $this->rapportino('RT-2026-0794', '2026-09-12', eureka: 753, cliente: $altroCliente);

        $esito = $this->esegui([$copia, $nuova]);

        $this->assertSame(1, $esito['nuovi']);
        $this->assertSame(['RT-2026-0794' => 'RT-2026-0793'], $esito['rinumerati']);
        $this->assertSame(['RT-2026-0792', 'RT-2026-0793'], $this->numeri());
        $this->assertSame('RT-2026-0793', $nuova->fresh()->number);
    }

    /**
     * Se dopo le schede importate c'e' gia' un altro rapportino, i numeri non si
     * toccano: la copia resta archiviata col suo numero invece di lasciare un
     * buco (regola dell'ufficio, caso del Vidi).
     */
    public function test_con_un_rapportino_dopo_i_numeri_non_si_toccano(): void
    {
        $tecnico = $this->rapportino('RT-2026-0792', '2026-09-11');
        $copia = $this->rapportino('RT-2026-0793', '2026-09-11', eureka: 752);
        $this->rapportino('RT-2026-0794', '2026-09-21', cliente: Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Oggi']));

        $esito = $this->esegui([$copia]);

        $this->assertStringContainsString('RT-2026-0794', $esito['rinumerazione']);
        $this->assertSame(752, (int) $tecnico->fresh()->eureka_service_report_id, 'L\'unione si fa comunque.');
        $this->assertNotNull(ServiceReport::withTrashed()->find($copia->id)?->deleted_at, 'La copia resta archiviata.');
        $this->assertSame(['RT-2026-0792', 'RT-2026-0793', 'RT-2026-0794'], $this->numeri(), 'Nessun buco.');
    }

    /**
     * Cambridge 06/09: un rapportino, e su Eureka due schede uguali. Eureka non
     * si tocca mai (indicazione dell'ufficio, 21/09/2026): il rapportino del
     * tecnico va sulla prima scheda, la seconda tiene il suo. Una scheda, un
     * rapportino.
     */
    public function test_due_schede_uguali_su_eureka_due_rapportini_nel_crm(): void
    {
        $tecnico = $this->rapportino('RT-2026-0778', '2026-09-06', matricola: null);
        $prima = $this->rapportino('RT-2026-0779', '2026-09-06', eureka: 747, matricola: null);
        $seconda = $this->rapportino('RT-2026-0780', '2026-09-06', eureka: 753, matricola: null);

        $esito = $this->esegui([$prima, $seconda]);

        $this->assertSame(747, (int) $tecnico->fresh()->eureka_service_report_id);
        $this->assertNull(ServiceReport::withTrashed()->find($prima->id), 'La copia della prima scheda va via.');
        $this->assertSame(753, (int) $seconda->fresh()->eureka_service_report_id, 'La seconda scheda ha il suo rapportino.');
        $this->assertSame(1, $esito['nuovi']);
        $this->assertSame(['RT-2026-0778', 'RT-2026-0779'], $this->numeri(), 'Progressivi: la seconda scende al posto della copia.');
        $this->assertStringNotContainsString('cancell', $esito['uniti'][0]['motivo'], 'Su Eureka non si cancella niente.');
    }

    /**
     * Strana Coppia 18/09: il tecnico ha messo acqua e spina in un rapportino
     * solo, indicando come macchina la spina; Eureka ha una scheda per
     * impianto. Il rapportino era sbagliato (indicazione dell'ufficio): va
     * sulla scheda della spina, e l'acqua tiene il suo. Il CRM si sdoppia come
     * Eureka, non il contrario.
     */
    public function test_visita_divisa_per_impianto(): void
    {
        $cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Strana Coppia']);
        $tecnico = $this->rapportino('RT-2026-0804', '2026-09-18', matricola: null, cliente: $cliente,
            articoli: ['SANIFICAZIONE', 'CARTUCCAI MC2', 'LAV2', 'ULTVIA'], tipo: ServiceReport::TYPE_SANIFICAZIONE);
        $impiantoSpina = \App\Models\MachineUnit::create([
            'tenant_id' => $this->tenant->id, 'current_customer_id' => $cliente->id,
            'serial_number' => 'IMP-SPINA-014', 'model_name' => 'Impianto Spina (birra+vino+selz+bibite)',
        ]);
        $tecnico->forceFill(['machine_unit_id' => $impiantoSpina->id])->saveQuietly();
        $spina = $this->rapportino('RT-2026-0805', '2026-09-18', eureka: 766, matricola: null, cliente: $cliente,
            articoli: ['LAV2', 'ULTVIA'], impianto: 'SPINA 5 VIE');
        $acqua = $this->rapportino('RT-2026-0806', '2026-09-18', eureka: 767, matricola: null, cliente: $cliente,
            articoli: ['SANIFICAZIONE', 'CARTUCCAI', 'CARTUCCAI MC2'], impianto: 'IMPIANTOACQUA', tipo: ServiceReport::TYPE_SANIFICAZIONE);

        $this->esegui([$spina, $acqua]);

        $tecnico->refresh();
        $this->assertSame(766, (int) $tecnico->eureka_service_report_id, 'Sulla scheda della spina, la sua macchina.');
        $this->assertSame(
            ['LAV2', 'ULTVIA'],
            $tecnico->materialsUsed()->with('material')->get()->pluck('material.code')->sort()->values()->all(),
            'Con gli articoli della spina: quelli dell\'acqua sono sull\'altro rapportino.',
        );
        $this->assertSame(767, (int) $acqua->fresh()->eureka_service_report_id, 'L\'acqua tiene il suo rapportino.');
        $this->assertSame(['RT-2026-0804', 'RT-2026-0805'], $this->numeri(), 'Progressivi.');
    }

    /** Due schede diverse sullo stesso impianto: non e' ne' divisa ne' doppia. Decide una persona. */
    public function test_davvero_ambiguo_decide_una_persona(): void
    {
        $tecnico = $this->rapportino('RT-2026-0778', '2026-09-06', matricola: null, articoli: ['CHIORD', 'ORE']);
        $a = $this->rapportino('RT-2026-0779', '2026-09-06', eureka: 747, matricola: null, articoli: ['CHIORD'], impianto: 'F2');
        $b = $this->rapportino('RT-2026-0780', '2026-09-06', eureka: 753, matricola: null, articoli: ['ORE'], impianto: 'F2');

        $esito = $this->esegui([$a, $b]);

        $this->assertCount(2, $esito['ambigui']);
        $this->assertNull($tecnico->fresh()->eureka_service_report_id);
        $this->assertNotNull($tecnico->fresh()->duplicato_suggerito_id, 'Compare nel confronto.');
        $this->assertSame(['RT-2026-0778', 'RT-2026-0779', 'RT-2026-0780'], $this->numeri());
    }

    /** Macchine diverse lo stesso giorno: due interventi, non un doppione. */
    public function test_macchine_diverse_non_si_uniscono(): void
    {
        $tecnico = $this->rapportino('RT-2026-0792', '2026-09-11', matricola: 'M-1');
        $altra = $this->rapportino('RT-2026-0793', '2026-09-11', eureka: 752, matricola: 'M-2');

        $esito = $this->esegui([$altra]);

        $this->assertSame(1, $esito['nuovi']);
        $this->assertNull($tecnico->fresh()->eureka_service_report_id);
    }

    /** Una scheda gia' mandata per mail ha un numero che qualcuno ha visto: non si rinumera. */
    public function test_una_scheda_mandata_per_mail_non_si_rinumera(): void
    {
        $this->rapportino('RT-2026-0792', '2026-09-11');
        $copia = $this->rapportino('RT-2026-0793', '2026-09-11', eureka: 752);
        $nuova = $this->rapportino('RT-2026-0794', '2026-09-12', eureka: 753, cliente: Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'X']));
        ServiceReportEmail::create(['service_report_id' => $nuova->id, 'recipient_email' => 'x@x.it', 'subject' => 's', 'message' => 'm', 'status' => 'inviata']);

        $esito = $this->esegui([$copia, $nuova]);

        $this->assertStringContainsString('mail', $esito['rinumerazione']);
        $this->assertSame('RT-2026-0794', $nuova->fresh()->number);
    }
}
