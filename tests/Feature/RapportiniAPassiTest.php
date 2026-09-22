<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\RapportiniAPassi;
use App\Filament\Resources\ServiceReportResource\Pages\ViewServiceReport;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Rapportini\DividiPerMacchina;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Il rapportino a passi: si spuntano le macchine, un passo per macchina,
 * una firma, un rapportino per macchina. I due casi sono le visite vere da cui
 * e' nata (Hotel Olanda 21/09/2026, La Strana Coppia 18/09/2026).
 */
class RapportiniAPassiTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /** PNG 1x1 valido. */
    private const FIRMA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $this->tecnico = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Igor', 'email' => 'igor@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->tecnico, $this->tenant, 'admin');

        foreach (['MANX20' => 'MANUTENZIONE X20', 'LAV2' => 'LAVAGGIO 2 VIE', 'ULTVIA' => 'ULTERIORE VIA LAVATA', 'SANIFICAZIONE' => 'SANIFICAZIONE IMPIANTO ACQUA', 'CARTUCCAI MC2' => 'CARTUCCIA FILTRO MC2', 'CHIORD' => 'INTERVENTO ORDINARIO', 'ORE' => 'MANODOPERA'] as $code => $type) {
            Material::create(['code' => $code, 'type' => $type, 'category' => 'Eureka']);
        }

        $this->actingAs($this->tecnico);
        Filament::setTenant($this->tenant);
    }

    private function cliente(string $nome): Customer
    {
        return Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => $nome, 'city' => 'Jesolo']);
    }

    private function macchina(Customer $cliente, string $matricola, array $dati = []): MachineUnit
    {
        return MachineUnit::create(['tenant_id' => $this->tenant->id, 'current_customer_id' => $cliente->id, 'serial_number' => $matricola, ...$dati]);
    }

    private function piano(Customer $cliente, MachineUnit $macchina, string $bevanda, ?int $vie): MaintenanceSchedule
    {
        return MaintenanceSchedule::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $cliente->id, 'machine_unit_id' => $macchina->id,
            'type' => MaintenanceSchedule::TYPE_LAVAGGIO, 'status' => MaintenanceSchedule::STATUS_ATTIVO,
            'beverage_type' => $bevanda, 'lines_count' => $vie, 'frequency_days' => 30,
        ]);
    }

    /** @return array<string, float> codice => quantita' */
    private function voci(ServiceReport $r): array
    {
        return $r->materialsUsed()->with('material')->get()
            ->mapWithKeys(fn ($riga) => [$riga->material->code => (float) $riga->quantity])
            ->sortKeys()
            ->all();
    }

    public function test_hotel_olanda_due_x20_stesso_lavoro_due_rapportini_firmati(): void
    {
        $olanda = $this->cliente('Hotel Olanda');
        $x1 = $this->macchina($olanda, '1951793', ['model_name' => 'FAEMA X20 CP11H', 'maintenance_code' => 'MANX20']);
        $x2 = $this->macchina($olanda, '1951052', ['model_name' => 'FAEMA X20 CP11H', 'maintenance_code' => 'MANX20']);
        $torrefattore = $this->cliente('Torrefazione');
        ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $olanda->id, 'technician_id' => $this->tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => today()->subMonths(4),
            'work_performed' => 'Vecchio intervento', 'customer_signature_name' => 'Fahim',
        ]);

        Livewire::withQueryParams(['customer_id' => $olanda->id])
            ->test(RapportiniAPassi::class)
            // Chi ha firmato l'ultima volta e' gia' scritto.
            ->assertSet('data.customer_signature_name', 'Fahim')
            ->set('data.macchine', [$x1->id, $x2->id])
            // Il passo e' il modulo del rapportino: cliente e macchina gia'
            // scritti, e l'interruttore "Manutenzione ordinaria" mette il
            // codice del modello come sul modulo.
            ->assertSet("data.lavori.{$x1->id}.customer_id", $olanda->id)
            ->assertSet("data.lavori.{$x1->id}.machine_unit_id", $x1->id)
            ->set("data.lavori.{$x1->id}.intervention_type", ServiceReport::TYPE_MANUTENZIONE_ORDINARIA)
            ->set("data.lavori.{$x1->id}.add_manutenzione_material", true)
            ->set("data.lavori.{$x1->id}.add_chiamata_material", true)
            ->set("data.lavori.{$x1->id}.work_performed", 'Manutenzione X20. Fatto prove espresso e pulizia')
            // Sulla seconda: "Stesso lavoro di ...", solo perche' lo chiede.
            ->call('copiaLavoro', $x1->id, $x2->id)
            ->assertSet("data.lavori.{$x2->id}.work_performed", 'Manutenzione X20. Fatto prove espresso e pulizia')
            // "Fatturare a" resta del singolo rapportino, come sul modulo.
            ->set("data.lavori.{$x2->id}.billing_customer_id", $torrefattore->id)
            ->set('data.customer_signature_path', self::FIRMA)
            ->call('salva')
            ->assertHasNoFormErrors();

        $rapportini = ServiceReport::where('intervention_date', today())->orderBy('number')->get();

        $this->assertCount(2, $rapportini);
        $this->assertEqualsCanonicalizing([$x1->id, $x2->id], $rapportini->pluck('machine_unit_id')->all());

        // Una manutenzione per macchina, non MANX20 x2 su una sola; la
        // chiamata e' della visita e con "Stesso lavoro" non si raddoppia.
        $this->assertSame(['CHIORD' => 1.0, 'MANX20' => 1.0], $this->voci($rapportini->firstWhere('machine_unit_id', $x1->id)));
        $this->assertSame(['MANX20' => 1.0], $this->voci($rapportini->firstWhere('machine_unit_id', $x2->id)));
        $this->assertNull($rapportini->firstWhere('machine_unit_id', $x1->id)->billing_customer_id);
        $this->assertSame($torrefattore->id, $rapportini->firstWhere('machine_unit_id', $x2->id)->billing_customer_id);

        foreach ($rapportini as $r) {
            $this->assertSame('Fahim', $r->customer_signature_name);
            $this->assertNotNull($r->customer_signature_path);
            $this->assertNotNull($r->signed_at);
        }

        $this->assertSame($rapportini[0]->customer_signature_path, $rapportini[1]->customer_signature_path, 'la stessa firma su tutti e due');
    }

    public function test_la_strana_coppia_spina_e_acqua_due_passi_diversi(): void
    {
        $coppia = $this->cliente('La Strana Coppia');
        $spina = $this->macchina($coppia, 'IMP-SPINA-014', ['model_name' => 'Impianto Spina']);
        $acqua = $this->macchina($coppia, 'IMP-ACQUA', ['model_name' => 'Impianto Acqua']);
        $birra = $this->piano($coppia, $spina, MaintenanceSchedule::BEVERAGE_BIRRA, 2);
        $vino = $this->piano($coppia, $spina, MaintenanceSchedule::BEVERAGE_VINO, 2);
        $bibite = $this->piano($coppia, $spina, MaintenanceSchedule::BEVERAGE_BIBITE, 1);
        $pianoAcqua = $this->piano($coppia, $acqua, MaintenanceSchedule::BEVERAGE_ACQUA, null);
        $cartuccia = Material::where('code', 'CARTUCCAI MC2')->first();

        Livewire::withQueryParams(['customer_id' => $coppia->id])
            ->test(RapportiniAPassi::class)
            ->set('data.macchine', [$spina->id, $acqua->id])
            // Spina: gli impianti e le vie come sul modulo; le vie accendono
            // da sole "Lavaggio eseguito" e le sue righe.
            ->set("data.lavori.{$spina->id}.intervention_type", ServiceReport::TYPE_SANIFICAZIONE)
            ->set("data.lavori.{$spina->id}.lavaggio_impianti", [
                'birra' => ['maintenance_schedule_id' => $birra->id, 'lines_washed' => null],
                'vino' => ['maintenance_schedule_id' => $vino->id, 'lines_washed' => null],
                'bibite' => ['maintenance_schedule_id' => $bibite->id, 'lines_washed' => null],
            ])
            ->set("data.lavori.{$spina->id}.lavaggio_impianti.birra.lines_washed", 2)
            ->set("data.lavori.{$spina->id}.lavaggio_impianti.vino.lines_washed", 2)
            ->set("data.lavori.{$spina->id}.lavaggio_impianti.bibite.lines_washed", 1)
            ->set("data.lavori.{$spina->id}.work_performed", 'Sanificazione impianto spina')
            // Acqua: l'impianto acqua accende "Sanificazione acqua".
            ->set("data.lavori.{$acqua->id}.intervention_type", ServiceReport::TYPE_SANIFICAZIONE)
            ->set("data.lavori.{$acqua->id}.lavaggio_impianti", ['acqua' => ['maintenance_schedule_id' => null, 'lines_washed' => null]])
            ->set("data.lavori.{$acqua->id}.lavaggio_impianti.acqua.maintenance_schedule_id", $pianoAcqua->id)
            ->set("data.lavori.{$acqua->id}.add_chiamata_material", true)
            ->set("data.lavori.{$acqua->id}.materialsUsed.cartuccia", ['material_id' => $cartuccia->id, 'quantity' => 1])
            ->set("data.lavori.{$acqua->id}.work_performed", 'Sanificazione e cambio filtro acqua')
            ->set('data.customer_signature_name', 'Nada')
            ->set('data.customer_signature_path', self::FIRMA)
            ->call('salva')
            ->assertHasNoFormErrors();

        $rSpina = ServiceReport::where('machine_unit_id', $spina->id)->sole();
        $rAcqua = ServiceReport::where('machine_unit_id', $acqua->id)->sole();

        // 5 vie: LAVAGGIO 2 VIE + ULTERIORE VIA x3; la chiamata dove il
        // tecnico l'ha messa.
        $this->assertSame(['LAV2' => 1.0, 'ULTVIA' => 3.0], $this->voci($rSpina));
        $this->assertSame(5, $rSpina->lavaggio_vie_count);
        $this->assertSame(['CARTUCCAI MC2' => 1.0, 'CHIORD' => 1.0, 'SANIFICAZIONE' => 1.0], $this->voci($rAcqua));

        // Un lavaggio per piano, con le sue vie.
        $this->assertSame(2, Lavaggio::where('service_report_id', $rSpina->id)->where('maintenance_schedule_id', $birra->id)->value('lines_washed'));
        $this->assertSame(1, Lavaggio::where('service_report_id', $rSpina->id)->where('maintenance_schedule_id', $bibite->id)->value('lines_washed'));
        $this->assertTrue(Lavaggio::where('service_report_id', $rAcqua->id)->where('maintenance_schedule_id', $pianoAcqua->id)->exists());

        foreach ([$rSpina, $rAcqua] as $r) {
            $this->assertSame('Nada', $r->customer_signature_name);
            $this->assertNotNull($r->signed_at);
        }
    }

    public function test_senza_lavoro_svolto_non_salva_niente(): void
    {
        $cliente = $this->cliente('Bar Sport');
        $m = $this->macchina($cliente, 'SN-1', ['model_name' => 'E71']);

        Livewire::withQueryParams(['customer_id' => $cliente->id])
            ->test(RapportiniAPassi::class)
            ->set('data.macchine', [$m->id])
            ->set("data.lavori.{$m->id}.intervention_type", ServiceReport::TYPE_RIPARAZIONE)
            ->set('data.customer_signature_name', 'Mario')
            ->set('data.customer_signature_path', self::FIRMA)
            ->call('salva')
            ->assertHasFormErrors(["lavori.{$m->id}.work_performed" => 'required']);

        $this->assertSame(0, ServiceReport::count());
    }

    /**
     * RT-2026-0807 di Hotel Olanda: un rapportino per due X20 con
     * MANUTENZIONE X20 x2, firmato. Diviso, sono due, x1 ciascuno, firmati
     * tutti e due, della stessa visita.
     */
    private function olandaDaDividere(): array
    {
        $olanda = $this->cliente('Hotel Olanda');
        $x1 = $this->macchina($olanda, '1951793', ['model_name' => 'FAEMA X20 CP11H', 'maintenance_code' => 'MANX20']);
        $x2 = $this->macchina($olanda, '1951052', ['model_name' => 'FAEMA X20 CP11H', 'maintenance_code' => 'MANX20']);
        Storage::disk('public')->put('signatures/fahim.png', 'png');

        $r = ServiceReport::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $olanda->id, 'technician_id' => $this->tecnico->id,
            'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA, 'intervention_date' => today(),
            'machine_unit_id' => $x1->id, 'status' => 'completato',
            'work_performed' => 'Manutenzione x20 1951052', 'notes' => 'Fatto prove espresso e pulizia',
            'customer_signature_name' => 'Fahim', 'customer_signature_path' => 'signatures/fahim.png',
        ]);
        $r->materialsUsed()->create(['material_id' => Material::where('code', 'MANX20')->value('id'), 'quantity' => 2]);
        $r->materialsUsed()->create(['material_id' => Material::where('code', 'CHIORD')->value('id'), 'quantity' => 1]);

        return [$olanda, $x1, $x2, $r->fresh()];
    }

    public function test_dividi_per_macchina_come_rt_0807(): void
    {
        [, $x1, $x2, $r] = $this->olandaDaDividere();
        $manx20 = $r->materialsUsed()->whereHas('material', fn ($q) => $q->where('code', 'MANX20'))->first();

        Livewire::test(ViewServiceReport::class, ['record' => $r->getRouteKey()])
            // La proposta: di MANX20 x2 ne passa uno, la chiamata resta.
            ->mountAction('dividi_per_macchina')
            ->assertActionDataSet(["righe.{$manx20->id}" => 1])
            ->setActionData([
                'machine_unit_id' => $x2->id,
                'lavoro_originale' => 'Manutenzione X20',
                'lavoro_nuovo' => 'Manutenzione X20',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $nuovo = ServiceReport::where('machine_unit_id', $x2->id)->sole();
        $r->refresh();

        $this->assertSame(['CHIORD' => 1.0, 'MANX20' => 1.0], $this->voci($r));
        $this->assertSame(['MANX20' => 1.0], $this->voci($nuovo));
        $this->assertSame('Manutenzione X20', $r->work_performed);

        // La stessa firma, niente rapportino "mezzo senza firma".
        $this->assertSame('Fahim', $nuovo->customer_signature_name);
        $this->assertSame($r->customer_signature_path, $nuovo->customer_signature_path);
        $this->assertSame('completato', $nuovo->status);

        $this->assertNotNull($r->visita_id);
        $this->assertSame($r->visita_id, $nuovo->visita_id);
    }

    public function test_non_si_divide_un_rapportino_gia_su_eureka(): void
    {
        [, , $x2, $r] = $this->olandaDaDividere();
        $r->forceFill(['source' => ServiceReport::SOURCE_EUREKA])->save();

        Livewire::test(ViewServiceReport::class, ['record' => $r->getRouteKey()])
            ->assertActionHidden('dividi_per_macchina');

        $this->expectException(\InvalidArgumentException::class);
        DividiPerMacchina::esegui($r, $x2, []);
    }

    public function test_modifica_visita_corregge_tutti_e_aggiunge_una_macchina_con_la_stessa_firma(): void
    {
        [$olanda, $x1, $x2, $r] = $this->olandaDaDividere();
        $nuovo = DividiPerMacchina::esegui($r, $x2, [$r->materialsUsed()->whereHas('material', fn ($q) => $q->where('code', 'MANX20'))->value('id') => 1]);
        $r->refresh();
        $macinino = $this->macchina($olanda, 'MAC-1', ['model_name' => 'Macinino']);

        // "Modifica" apre solo quello; "Modifica visita" tutti, sul suo passo.
        Livewire::test(RapportiniAPassi::class, ['record' => $nuovo->getKey()])
            ->assertSet('esistenti', [$nuovo->id => $x2->id]);

        Livewire::withQueryParams(['tutta_la_visita' => 1])
            ->test(RapportiniAPassi::class, ['record' => $nuovo->getKey()])
            ->assertSet('passoIniziale', 3)
            // Ogni passo e' il rapportino com'e' salvato, ricambi compresi.
            ->assertSet("data.lavori.{$r->id}.work_performed", 'Manutenzione x20 1951052')
            ->assertSet("data.lavori.{$r->id}.add_chiamata_material", true)
            ->assertSet('data.customer_signature_name', 'Fahim')
            ->set("data.lavori.{$r->id}.work_performed", 'Manutenzione X20 1951793')
            // Una macchina in piu': rapportino nuovo della stessa visita.
            ->set('data.macchine', [$x1->id, $x2->id, $macinino->id])
            ->set("data.lavori.{$macinino->id}.intervention_type", ServiceReport::TYPE_RIPARAZIONE)
            ->set("data.lavori.{$macinino->id}.work_performed", 'Regolazione macinatura')
            ->call('salva')
            ->assertHasNoFormErrors();

        $visita = ServiceReport::where('visita_id', $r->visita_id)->get();
        $this->assertCount(3, $visita);

        $r->refresh();
        $this->assertSame('Manutenzione X20 1951793', $r->work_performed);
        // I ricambi salvati non si perdono ne' si raddoppiano.
        $this->assertSame(['CHIORD' => 1.0, 'MANX20' => 1.0], $this->voci($r));
        $this->assertSame(['MANX20' => 1.0], $this->voci($nuovo->fresh()));

        $terzo = $visita->firstWhere('machine_unit_id', $macinino->id);
        $this->assertSame('Regolazione macinatura', $terzo->work_performed);
        // Aggiunto dopo la firma: tiene quella che c'e'.
        $this->assertSame('Fahim', $terzo->customer_signature_name);
        $this->assertSame($r->customer_signature_path, $terzo->customer_signature_path);
    }

    public function test_modifica_da_solo_non_tocca_gli_altri_della_visita(): void
    {
        [, , $x2, $r] = $this->olandaDaDividere();
        $nuovo = DividiPerMacchina::esegui($r, $x2, []);

        Livewire::test(RapportiniAPassi::class, ['record' => $nuovo->getKey()])
            ->set("data.lavori.{$nuovo->id}.work_performed", 'Solo la seconda')
            ->set('data.status', 'bozza')
            ->call('salva')
            ->assertHasNoFormErrors();

        $this->assertSame('Solo la seconda', $nuovo->fresh()->work_performed);
        $this->assertSame('bozza', $nuovo->fresh()->status);
        $this->assertSame('Manutenzione x20 1951052', $r->fresh()->work_performed);
        $this->assertSame('completato', $r->fresh()->status);
        $this->assertSame($r->visita_id, $nuovo->fresh()->visita_id, 'resta nella sua visita');
    }

    public function test_dalla_matricola_si_trova_il_cliente(): void
    {
        $this->cliente('Bar Insegna');
        $gestore = $this->cliente('Gestioni Srl');
        $macchina = $this->macchina($gestore, 'MATR-777', ['model_name' => 'E71']);

        Livewire::test(RapportiniAPassi::class)
            ->set('data.cerca_matricola', $macchina->id)
            ->assertSet('data.customer_id', $gestore->id)
            ->assertSet('data.macchine', [$macchina->id])
            ->assertSet("data.lavori.{$macchina->id}.machine_unit_id", $macchina->id)
            ->assertSet('data.cerca_matricola', null);
    }

    public function test_una_matricola_in_magazzino_non_indovina_il_cliente(): void
    {
        $inMagazzino = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'MAG-1', 'model_name' => 'E71']);

        Livewire::test(RapportiniAPassi::class)
            ->set('data.cerca_matricola', $inMagazzino->id)
            ->assertSet('data.customer_id', null)
            ->assertNotified('La matricola MAG-1 non risulta presso nessun cliente');
    }
}
