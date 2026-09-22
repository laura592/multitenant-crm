<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource\Pages\ViewMachineUnit;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Chi paga sta sul posizionamento (22/09/2026): dipende da dove e' la
 * macchina, e lo storico dice chi pagava quando. La macchina tiene la
 * copia della posizione attuale, che e' quella che leggono lavaggi e
 * rapportini.
 */
class PaganteMacchinaPosizionamentoTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    private Customer $majer;

    private Customer $grigliata;

    private Customer $dersut;

    private Customer $rtg;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $this->majer = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Majer S. Agostino', 'gestionale_code' => 1149]);
        $this->grigliata = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Ristorante alla Grigliata', 'gestionale_code' => 1493]);
        $this->dersut = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Dersut Caffe', 'gestionale_code' => 580]);
        $this->rtg = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'RTG SRL', 'gestionale_code' => 3100]);
    }

    private function macchina(): MachineUnit
    {
        $m = MachineUnit::create(['tenant_id' => $this->tenant->id, 'serial_number' => 'B20359', 'model_name' => 'Addolcitore LT 8']);
        $m->moveTo($this->majer, placedAt: Carbon::parse('2024-01-31'), pagante: $this->dersut, codicePaganteEureka: 580);

        return $m->fresh();
    }

    public function test_spostata_non_si_porta_dietro_il_pagante_di_prima(): void
    {
        $m = $this->macchina();
        $this->assertSame($this->dersut->id, $m->billing_customer_id);

        $m->moveTo($this->grigliata, placedAt: Carbon::parse('2026-01-14'));
        $m->refresh();

        $this->assertNull($m->billing_customer_id, 'Alla Grigliata non paga Dersut.');
        $storico = $m->placements()->reorder('placed_at')->get();
        $this->assertSame($this->dersut->id, $storico[0]->billing_customer_id, 'Lo storico ricorda chi pagava al Majer.');
        $this->assertNull($storico[1]->billing_customer_id);
    }

    public function test_cambiare_il_pagante_sulla_macchina_lo_cambia_sulla_posizione_attuale(): void
    {
        $m = $this->macchina();
        $m->update(['billing_customer_id' => $this->rtg->id]);

        $this->assertSame($this->rtg->id, $m->placements()->whereNull('removed_at')->sole()->billing_customer_id);
    }

    /** Il sync legge la macchina con poche colonne: non deve azzerare il pagante della posizione. */
    public function test_aggiornare_solo_il_codice_eureka_non_tocca_il_pagante(): void
    {
        $m = $this->macchina();
        $parziale = MachineUnit::query()->whereKey($m->id)->first(['id', 'serial_number', 'current_customer_id', 'eureka_billing_customer_code']);
        $parziale->eureka_billing_customer_code = 999;
        $parziale->save();

        $posizione = $m->placements()->whereNull('removed_at')->sole();
        $this->assertSame($this->dersut->id, $posizione->billing_customer_id);
        $this->assertSame(999, $posizione->eureka_billing_customer_code);
    }

    public function test_confermare_la_proposta_del_sync_porta_il_pagante_della_consegna(): void
    {
        $m = $this->macchina();

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(str_contains($request->url(), 'art_installati') ? match ((int) ($q['q'] ?? 0)) {
                1149 => [['id' => 1, 'matricola' => 'B20359', 'numero_doc_t23' => 189, 'data_documento' => '2024-01-31T00:00:00.000+01:00', 'id_intestatario_fattura_f15' => 580]],
                1493 => [['id' => 1, 'matricola' => 'B20359', 'numero_doc_t23' => 247, 'data_documento' => '2026-01-14T00:00:00.000+01:00', 'id_intestatario_fattura_f15' => 3100]],
                default => [],
            } : [], 200);
        });

        $this->artisan('gestionale:sync')->assertExitCode(0);
        $m->refresh();

        // Il pagante della Grigliata non finisce sulla macchina, che e' ancora al Majer.
        $this->assertSame(580, $m->eureka_billing_customer_code);
        $this->assertSame(3100, $m->spostamento_suggerito_pagante_code);

        $m->accettaSpostamento();
        $m->refresh();

        $this->assertSame($this->grigliata->id, $m->current_customer_id);
        $this->assertSame($this->rtg->id, $m->billing_customer_id);
        $this->assertSame($this->rtg->id, $m->placements()->whereNull('removed_at')->sole()->billing_customer_id);
    }

    public function test_sposta_dal_pannello_chiede_chi_paga(): void
    {
        $user = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'a@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $this->tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($this->tenant);

        $m = $this->macchina();

        Livewire::test(ViewMachineUnit::class, ['record' => $m->getRouteKey()])
            ->mountAction('sposta')
            ->setActionData(['customer_id' => $this->grigliata->id, 'data' => '2026-01-14', 'billing_customer_id' => $this->rtg->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $m->refresh();
        $this->assertSame($this->rtg->id, $m->billing_customer_id);
        $this->assertSame($this->rtg->id, $m->placements()->whereNull('removed_at')->sole()->billing_customer_id);
    }
}
