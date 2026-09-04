<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\ListServiceReports;
use App\Mail\ServiceReportMail;
use App\Models\Customer;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Cosa esce dall'azione "Invia" cambia col ruolo (indicazione dell'ufficio,
 * 04/09/2026): il tecnico manda una copia sola, senza articoli, e solo a chi
 * ha ricevuto l'intervento; l'ufficio sceglie fra tre copie e puo' scrivere
 * anche al pagante.
 */
class RapportinoInvioCopieTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /** @return array{0: ServiceReport, 1: Tenant, 2: Customer} */
    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $pagante = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Dersut SPA',
            'emails' => ['amministrazione@dersut.it'],
        ]);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar del Porto',
            'emails' => ['bar@porto.it'], 'billing_customer_id' => $pagante->id,
        ]);
        $tecnico = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Igor', 'email' => 'igor@alex.it', 'password' => bcrypt('x'),
        ]);

        $report = ServiceReport::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'technician_id' => $tecnico->id,
            'intervention_type' => ServiceReport::TYPE_RIPARAZIONE, 'intervention_date' => now(),
            'work_performed' => 'Sostituita guarnizione', 'status' => 'firmato',
        ]);
        $materiale = Material::create([
            'tenant_id' => $tenant->id, 'code' => 'GUARN01', 'category' => 'Test', 'type' => 'Test',
            'source' => Material::SOURCE_MANUALE, 'list_price' => 12.5,
        ]);
        ServiceReportMaterial::create([
            'service_report_id' => $report->id, 'material_id' => $materiale->id, 'quantity' => 2,
        ]);

        return [$report, $tenant, $cliente];
    }

    private function comeRuolo(Tenant $tenant, string $ruolo): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => ucfirst($ruolo),
            'email' => $ruolo.'@alex.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($user, $tenant, $ruolo);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $user;
    }

    public function test_le_tre_copie_mostrano_cose_diverse(): void
    {
        [$report] = $this->scenario();
        $report->load(['customer', 'technician', 'materialsUsed.material', 'partsUsed.product', 'tenant']);

        $senzaArticoli = view('pdf.service-report', [
            'report' => $report, 'showPrices' => false, 'showArticoli' => false,
        ])->render();
        $conArticoli = view('pdf.service-report', [
            'report' => $report, 'showPrices' => false, 'showArticoli' => true,
        ])->render();
        $conPrezzi = view('pdf.service-report', [
            'report' => $report, 'showPrices' => true, 'showArticoli' => true,
        ])->render();

        $this->assertStringNotContainsString('Ricambi/materiali utilizzati', $senzaArticoli);
        // Il lavoro svolto resta: e' quello che il cliente firma.
        $this->assertStringContainsString('Sostituita guarnizione', $senzaArticoli);

        $this->assertStringContainsString('Ricambi/materiali utilizzati', $conArticoli);
        $this->assertStringNotContainsString('Prezzo unit.', $conArticoli);

        $this->assertStringContainsString('Ricambi/materiali utilizzati', $conPrezzi);
        $this->assertStringContainsString('Prezzo unit.', $conPrezzi);
    }

    public function test_il_tecnico_ha_una_copia_sola_l_ufficio_tre(): void
    {
        [$report, $tenant] = $this->scenario();
        $policy = app(\App\Policies\ServiceReportPolicy::class);

        $tecnico = $this->comeRuolo($tenant, 'dipendente');
        $this->assertSame(
            [\App\Policies\ServiceReportPolicy::COPIA_SENZA_ARTICOLI],
            $policy->copieEmailConsentite($tecnico, $report),
        );
        $this->assertFalse($policy->puoScrivereAlPagante($tecnico, $report));

        $ufficio = $this->comeRuolo($tenant, 'amministrazione');
        $this->assertCount(3, $policy->copieEmailConsentite($ufficio, $report));
        $this->assertTrue($policy->puoScrivereAlPagante($ufficio, $report));
    }

    public function test_il_tecnico_non_scrive_al_pagante(): void
    {
        [$report, $tenant] = $this->scenario();
        $this->comeRuolo($tenant, 'dipendente');
        Mail::fake();

        Livewire::test(ListServiceReports::class)
            ->callTableAction('send', $report, data: [
                'recipient_emails' => ['bar@porto.it', 'amministrazione@dersut.it'],
            ]);

        Mail::assertSent(ServiceReportMail::class, fn (ServiceReportMail $mail) => $mail->hasTo('bar@porto.it')
            && ! $mail->hasTo('amministrazione@dersut.it'));
    }

    public function test_se_resta_solo_il_pagante_non_parte_niente(): void
    {
        [$report, $tenant] = $this->scenario();
        $this->comeRuolo($tenant, 'dipendente');
        Mail::fake();

        Livewire::test(ListServiceReports::class)
            ->callTableAction('send', $report, data: [
                'recipient_emails' => ['amministrazione@dersut.it'],
            ]);

        Mail::assertNothingSent();
        $this->assertSame('firmato', $report->fresh()->status);
    }

    /**
     * Mandare a Eureka e mandare al cliente sono due gesti distinti, e si
     * possono fare in quest'ordine. Ma l'email non deve riportare indietro lo
     * stato: "in gestionale" e' terminale, ed e' l'unica traccia in elenco
     * che il documento su Eureka esiste.
     */
    public function test_l_email_dopo_il_gestionale_non_declassa_lo_stato(): void
    {
        [$report, $tenant] = $this->scenario();
        $report->update(['status' => 'in_gestionale', 'gestionale_sync_status' => 'sent']);
        $this->comeRuolo($tenant, 'amministrazione');
        Mail::fake();

        Livewire::test(ListServiceReports::class)
            ->callTableAction('send', $report, data: ['recipient_emails' => ['bar@porto.it']]);

        Mail::assertSent(ServiceReportMail::class);
        $this->assertSame('in_gestionale', $report->fresh()->status);
    }

    public function test_l_ufficio_scrive_al_pagante_e_sceglie_la_copia(): void
    {
        [$report, $tenant] = $this->scenario();
        $this->comeRuolo($tenant, 'amministrazione');
        Mail::fake();

        Livewire::test(ListServiceReports::class)
            ->callTableAction('send', $report, data: [
                'copia' => 'con_prezzi',
                'recipient_emails' => ['bar@porto.it', 'amministrazione@dersut.it'],
            ]);

        Mail::assertSent(ServiceReportMail::class, fn (ServiceReportMail $mail) => $mail->hasTo('bar@porto.it')
            && $mail->hasTo('amministrazione@dersut.it'));
    }
}
