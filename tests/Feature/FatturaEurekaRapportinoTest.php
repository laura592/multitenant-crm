<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ViewServiceReport;
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
 * Dal rapportino alla sua fattura su Eureka: /show/q/sl_fattura per sapere
 * su quali fatture e' finita la scheda, /report/fattura/{id} per il PDF.
 * Documentate dal fornitore il 21/09/2026.
 */
class FatturaEurekaRapportinoTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private const EUREKA = 'https://eureka.test';

    private const PDF = "%PDF-1.7\nfattura finta";

    private Tenant $tenant;

    private ServiceReport $rapportino;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.eureka.base_url' => self::EUREKA,
            'services.eureka.username' => 'u',
            'services.eureka.password' => 'p',
        ]);

        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $cliente = Customer::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Hotel Margherita']);
        $tecnico = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Igor', 'email' => 'igor@alex.it', 'password' => bcrypt('x'),
        ]);

        $this->rapportino = ServiceReport::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $cliente->id,
            'technician_id' => $tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE,
            'intervention_date' => now(),
            'status' => 'in_gestionale',
            'eureka_service_report_id' => 16898,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $fatture */
    private function eurekaRisponde(array $fatture): void
    {
        Http::fake([
            self::EUREKA.'/show/q/sl_fattura*' => Http::response($fatture),
            self::EUREKA.'/report/fattura/*' => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    private function fatturaFt267(): array
    {
        return [
            'id_fattura' => 16953, 'tipo_doc' => 'FT', 'numero_fattura' => 267,
            'data_fattura' => '2026-06-30T00:00:00.000+02:00', 'id_bolla' => 16899, 'has_fe' => 1,
        ];
    }

    private function entraCome(string $ruolo): User
    {
        $utente = User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($ruolo),
            'email' => "{$ruolo}@alex.it", 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $this->tenant, $ruolo);

        $this->actingAs($utente);
        Filament::setTenant($this->tenant);

        return $utente;
    }

    public function test_elenca_la_fattura_con_il_suo_link(): void
    {
        $this->eurekaRisponde([$this->fatturaFt267()]);

        $esito = FattureRapportino::per($this->rapportino);

        $this->assertSame('ok', $esito['esito']);
        $this->assertSame('FT 267 del 30/06/2026', $esito['fatture'][0]['etichetta']);
        $this->assertTrue($esito['fatture'][0]['fe']);
        $this->assertStringEndsWith("/service-reports/{$this->rapportino->id}/fatture/16953", $esito['fatture'][0]['url']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/show/q/sl_fattura?q=16898'));
    }

    /** Lista vuota: non ancora fatturata. Il fornitore dice di riprovare piu' avanti. */
    public function test_non_ancora_fatturata(): void
    {
        $this->eurekaRisponde([]);

        $this->assertSame('vuoto', FattureRapportino::per($this->rapportino)['esito']);
    }

    /** Eureka giu' non e' "non ancora fatturata": a chi ha la fattura in mano sarebbe una bugia. */
    public function test_eureka_giu_non_si_confonde_con_non_fatturata(): void
    {
        Http::fake([self::EUREKA.'/*' => Http::response('errore', 500)]);

        $this->assertSame('errore', FattureRapportino::per($this->rapportino)['esito']);
    }

    /** La scheda non deve aspettare Eureka: si chiede solo aprendo il modale. */
    public function test_aprire_il_rapportino_non_chiama_eureka(): void
    {
        $this->eurekaRisponde([$this->fatturaFt267()]);
        $this->entraCome('admin');

        Livewire::test(ViewServiceReport::class, ['record' => $this->rapportino->getRouteKey()])
            ->assertActionVisible('fattura_eureka');

        Http::assertNothingSent();
    }

    public function test_aprendo_il_modale_si_vede_la_fattura(): void
    {
        $this->eurekaRisponde([$this->fatturaFt267()]);
        $this->entraCome('admin');

        Livewire::test(ViewServiceReport::class, ['record' => $this->rapportino->getRouteKey()])
            ->mountAction('fattura_eureka')
            ->assertSee('FT 267 del 30/06/2026');
    }

    /** Ha i prezzi, e spesso quelli di altri locali: i tecnici non la vedono. */
    public function test_i_tecnici_non_vedono_il_pulsante(): void
    {
        $this->entraCome('dipendente');

        Livewire::test(ViewServiceReport::class, ['record' => $this->rapportino->getRouteKey()])
            ->assertActionHidden('fattura_eureka');
    }

    public function test_un_rapportino_mai_andato_su_eureka_non_ha_il_pulsante(): void
    {
        $this->rapportino->update(['eureka_service_report_id' => null]);
        $this->entraCome('admin');

        Livewire::test(ViewServiceReport::class, ['record' => $this->rapportino->getRouteKey()])
            ->assertActionHidden('fattura_eureka');
    }

    public function test_il_pdf_passa_dal_crm(): void
    {
        $this->eurekaRisponde([$this->fatturaFt267()]);
        $this->entraCome('admin');

        $this->get(route('service-reports.fattura-eureka', [$this->rapportino, 16953]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="fattura-FT-267-2026.pdf"');
    }

    /** Il controllo che conta e' nel controller: l'indirizzo lo puo' scrivere chiunque. */
    public function test_un_tecnico_con_l_indirizzo_in_mano_non_la_scarica(): void
    {
        $this->eurekaRisponde([$this->fatturaFt267()]);
        $this->entraCome('dipendente');

        $this->get(route('service-reports.fattura-eureka', [$this->rapportino, 16953]))->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * Cambiando il numero nell'indirizzo non si apre un'altra fattura
     * dell'azienda: deve essere una di quelle della scheda.
     */
    public function test_non_si_apre_una_fattura_di_un_altra_scheda(): void
    {
        $this->eurekaRisponde([$this->fatturaFt267()]);
        $this->entraCome('admin');

        $this->get(route('service-reports.fattura-eureka', [$this->rapportino, 99999]))->assertNotFound();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/report/fattura/'));
    }

    /** Un errore HTML servito come fattura sarebbe peggio di nessuna fattura. */
    public function test_se_eureka_non_manda_un_pdf_non_si_serve_niente(): void
    {
        Http::fake([
            self::EUREKA.'/show/q/sl_fattura*' => Http::response([$this->fatturaFt267()]),
            self::EUREKA.'/report/fattura/*' => Http::response('<html>errore</html>', 200),
        ]);
        $this->entraCome('admin');

        $this->get(route('service-reports.fattura-eureka', [$this->rapportino, 16953]))->assertStatus(503);
    }
}
