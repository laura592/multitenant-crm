<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceReportResource\Pages\CreateServiceReport;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TariffeIntervento;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La manutenzione ordinaria non ha un codice fisso come chiamata e
 * manodopera: dipende dal MODELLO della macchina (Faema 3 gruppi -> F3,
 * Cimbali 2 -> C2, Dalla Corte A/2 -> DC2), che lo dichiara in
 * Material::maintenance_code, e dal pagante (F3 -> F3GOPPION).
 */
class ManutenzioneOrdinariaRapportinoTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private function materiale(Tenant $tenant, string $code, string $type, array $extra = []): Material
    {
        return Material::create([
            'tenant_id' => $tenant->id, 'code' => $code, 'category' => 'Eureka',
            'type' => $type, 'source' => Material::SOURCE_EUREKA, 'list_price' => 77.16,
            ...$extra,
        ]);
    }

    /** @return array{0: Tenant, 1: Customer, 2: MachineUnit, 3: User} */
    private function scenario(?string $codiceGestionalePagante = null): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Tecnico', 'email' => 't@alex.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $tenant, 'admin');

        $pagante = $codiceGestionalePagante ? Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Goppion Caffe SPA',
            'gestionale_code' => $codiceGestionalePagante,
        ]) : null;

        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale',
            'billing_customer_id' => $pagante?->id,
        ]);

        // Il modello a catalogo, con il suo codice manutenzione.
        $modello = $this->materiale($tenant, 'FAEMAE71-3G', 'FAEMA E71 3 GRUPPI', ['maintenance_code' => 'F3']);
        $this->materiale($tenant, 'F3', 'MANUTENZIONE ORDINARIA F3 COMPRESO MATER');

        $macchina = MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $cliente->id,
            'material_id' => $modello->id, 'serial_number' => 'SN-001', 'model_name' => 'Faema E71',
        ]);

        $this->actingAs($utente);
        Filament::setTenant($tenant);

        return [$tenant, $cliente, $macchina, $utente];
    }

    public function test_il_codice_arriva_dal_modello_della_macchina(): void
    {
        [, $cliente, $macchina] = $this->scenario();

        $this->assertSame('F3', TariffeIntervento::manutenzione($macchina, $cliente));
    }

    public function test_il_pagante_con_listino_prende_la_sua_variante(): void
    {
        [$tenant, $cliente, $macchina] = $this->scenario(codiceGestionalePagante: '782');
        $this->materiale($tenant, 'F3GOPPION', 'MANUTENZIONE ORDINARIA F3 COMPRESO MATER');

        $this->assertSame('F3GOPPION', TariffeIntervento::manutenzione($macchina, $cliente));
    }

    /**
     * F4GOPPION a catalogo non esiste (mentre F4HTS si'): si ricade sul
     * codice base invece di mettere in riga un articolo inventato, che su
     * Eureka non si aggancerebbe a niente.
     */
    public function test_senza_la_variante_del_pagante_si_ricade_sul_codice_base(): void
    {
        [, $cliente, $macchina] = $this->scenario(codiceGestionalePagante: '782');

        $this->assertSame('F3', TariffeIntervento::manutenzione($macchina, $cliente));
    }

    public function test_un_modello_senza_codice_non_da_niente(): void
    {
        [$tenant, $cliente] = $this->scenario();
        $modelloMuto = $this->materiale($tenant, 'MACCHINAX', 'MACCHINA SENZA CODICE');
        $altra = MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $cliente->id,
            'material_id' => $modelloMuto->id, 'serial_number' => 'SN-002',
        ]);

        $this->assertNull(TariffeIntervento::manutenzione($altra, $cliente));
    }

    public function test_il_toggle_mette_in_riga_la_manutenzione_del_modello(): void
    {
        [, $cliente, $macchina, $utente] = $this->scenario();

        $form = Livewire::test(CreateServiceReport::class)
            ->fillForm([
                'customer_id' => $cliente->id,
                'technician_id' => $utente->id,
                'intervention_type' => ServiceReport::TYPE_MANUTENZIONE_ORDINARIA,
                'machine_unit_id' => $macchina->id,
            ])
            ->set('data.add_manutenzione_material', true);

        $stato = $form->instance()->form->getRawState();
        $codici = collect($stato['materialsUsed'] ?? [])
            ->map(fn (array $riga) => Material::find($riga['material_id'] ?? null)?->code)
            ->filter()->values()->all();

        $this->assertContains('F3', $codici);

        // Spegnendolo la riga se ne va.
        $form->set('data.add_manutenzione_material', false);
        $codici = collect($form->instance()->form->getRawState()['materialsUsed'] ?? [])
            ->map(fn (array $riga) => Material::find($riga['material_id'] ?? null)?->code)
            ->filter()->values()->all();

        $this->assertNotContains('F3', $codici);
    }

    /**
     * Il codice si decide guardando la macchina: quello sulla macchina vince
     * su quello del suo modello, che resta il valore normale per tutte le
     * altre macchine dello stesso tipo.
     */
    public function test_il_codice_sulla_macchina_vince_su_quello_del_modello(): void
    {
        [$tenant, $cliente, $macchina] = $this->scenario();
        $this->materiale($tenant, 'F4', 'MANUTENZIONE ORDINARIA F4');

        $this->assertSame('F3', TariffeIntervento::manutenzione($macchina, $cliente));

        $macchina->update(['maintenance_code' => 'F4']);

        $this->assertSame('F4', TariffeIntervento::manutenzione($macchina->fresh(), $cliente));
    }

    /**
     * Una macchina puo' avere un pagante suo — Goppion su una sola macchina
     * di un bar che per il resto paga da se' — e in quel caso e' lui a
     * scegliere la variante di listino, non il pagante del cliente.
     */
    public function test_il_pagante_della_macchina_vince_su_quello_del_cliente(): void
    {
        [$tenant, $cliente, $macchina] = $this->scenario();
        $this->materiale($tenant, 'F3GOPPION', 'MANUTENZIONE ORDINARIA F3 COMPRESO MATER');

        // Il cliente non ha pagante: si resta sul codice base.
        $this->assertSame('F3', TariffeIntervento::manutenzione($macchina, $cliente));

        $goppion = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Goppion Caffe SPA', 'gestionale_code' => 782,
        ]);
        $macchina->update(['billing_customer_id' => $goppion->id]);

        $this->assertSame('F3GOPPION', TariffeIntervento::manutenzione($macchina->fresh(), $cliente));
    }
}
