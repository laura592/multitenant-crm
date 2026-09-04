<?php

namespace Tests\Feature;

use App\Filament\Resources\MachineUnitResource;
use App\Filament\Resources\MachineUnitResource\Pages\ListMachineUnits;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * La stampa del riepilogo segue l'elenco che si ha davanti: senza filtri
 * esce il parco completo, cercando un cliente escono le sue macchine.
 * Un'azione sola invece di due che fanno quasi la stessa cosa.
 */
class RiepilogoMacchinePdfTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /** @return array{0: Tenant, 1: Customer} */
    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@alex.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $tenant, 'admin');

        $modello = Material::create([
            'tenant_id' => $tenant->id, 'code' => 'EVOA2', 'category' => 'Eureka',
            'type' => 'MACCHINA CAFFE DALLA CORTE EVO A/2', 'source' => Material::SOURCE_EUREKA,
            'maintenance_code' => 'DC2',
        ]);

        $porto = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Bar del Porto', 'city' => 'Jesolo']);
        $centro = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Caffe Centrale', 'city' => 'Mestre']);

        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $porto->id, 'material_id' => $modello->id,
            'serial_number' => 'SN-PORTO-1', 'model_name' => 'Dalla Corte EVO A/2',
        ]);
        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $centro->id, 'material_id' => $modello->id,
            'serial_number' => 'SN-CENTRO-1', 'model_name' => 'Dalla Corte EVO A/2',
        ]);

        $this->actingAs($utente);
        Filament::setTenant($tenant);

        return [$tenant, $porto];
    }

    public function test_senza_filtri_stampa_tutto_il_parco(): void
    {
        $this->scenario();

        $componente = Livewire::test(ListMachineUnits::class)->callAction('stampa_riepilogo');

        // JSON_UNESCAPED_SLASHES: senza, l'URL nel JSON e' "stampe\/..." e il
        // confronto fallirebbe per le barre sfuggite, non per il codice.
        $effetti = json_encode($componente->effects, JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('window.open', $effetti, 'la stampa non deve scaricarsi');
        $this->assertStringContainsString('/stampe/', $effetti);
    }

    /**
     * Il contenuto lo si verifica sulla view, non sul PDF compilato: dompdf
     * comprime lo stream e cercarci dentro una matricola non direbbe niente.
     */
    public function test_il_foglio_mostra_matricola_codice_manutenzione_e_cliente(): void
    {
        [$tenant] = $this->scenario();

        $html = view('pdf.machine-units', [
            'macchine' => MachineUnit::with(['currentCustomer', 'material'])->get(),
            'tenant' => $tenant,
            'data' => now(),
            'titolo' => 'Parco macchine completo',
            'etichetteStato' => MachineUnitResource::statusLabels(),
            'etichetteCategoria' => MachineUnitResource::typeLabels(),
        ])->render();

        $this->assertStringContainsString('SN-PORTO-1', $html);
        $this->assertStringContainsString('SN-CENTRO-1', $html);
        // Il codice manutenzione del modello: e' il motivo per cui il
        // tecnico si porta dietro il foglio.
        $this->assertStringContainsString('DC2', $html);
        $this->assertStringContainsString('Bar del Porto', $html);
        $this->assertStringContainsString('2 macchine', $html);
    }

    /**
     * Il caso che ha fatto nascere la correzione (04/09/2026): sul foglio si
     * leggeva il codice grezzo del modello, quindi su un cliente Goppion
     * usciva F2 invece di F2GOPPION — la tariffa sbagliata in mano al
     * tecnico. La variante col suffisso deve arrivare gia' risolta.
     */
    public function test_il_codice_porta_il_suffisso_del_pagante(): void
    {
        [$tenant] = $this->scenario();

        // 782 e' il codice gestionale di Goppion in config/tariffe.php.
        $goppion = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Goppion Caffe', 'gestionale_code' => 782,
        ]);

        $bar = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Goppion', 'city' => 'Treviso',
            'billing_customer_id' => $goppion->id,
        ]);

        $modello = Material::create([
            'tenant_id' => $tenant->id, 'code' => 'EMBLEMA2', 'category' => 'Eureka',
            'type' => 'FAEMA EMBLEMA A/2', 'source' => Material::SOURCE_EUREKA,
            'maintenance_code' => 'F2',
        ]);

        // La variante deve esistere a catalogo, se no si ricade sul codice base.
        Material::create([
            'tenant_id' => $tenant->id, 'code' => 'F2GOPPION', 'category' => 'Eureka',
            'type' => 'manutenzione ordinaria', 'source' => Material::SOURCE_EUREKA,
        ]);

        MachineUnit::create([
            'tenant_id' => $tenant->id, 'current_customer_id' => $bar->id, 'material_id' => $modello->id,
            'serial_number' => 'SN-GOPPION-1', 'model_name' => 'Faema Emblema A/2',
        ]);

        $html = $this->foglio($tenant);

        $this->assertStringContainsString('F2GOPPION', $html);
        $this->assertMatchesRegularExpression('/>\s*F2GOPPION\s*</', $html, 'il codice deve stare in cella, non solo nella nota');
    }

    /** Una macchina col codice suo vince sul modello, anche in stampa. */
    public function test_il_codice_della_macchina_batte_quello_del_modello(): void
    {
        [$tenant, $porto] = $this->scenario();

        $macchina = MachineUnit::where('serial_number', 'SN-PORTO-1')->firstOrFail();
        $macchina->update(['maintenance_code' => 'MANACQUA']);

        $html = $this->foglio($tenant);

        $this->assertStringContainsString('MANACQUA', $html);
    }

    private function foglio(Tenant $tenant): string
    {
        return view('pdf.machine-units', [
            'macchine' => MachineUnit::with(['currentCustomer.billingCustomer', 'billingCustomer', 'material'])->get(),
            'tenant' => $tenant,
            'data' => now(),
            'titolo' => 'Parco macchine completo',
            'etichetteStato' => MachineUnitResource::statusLabels(),
            'etichetteCategoria' => MachineUnitResource::typeLabels(),
        ])->render();
    }

    public function test_cercando_un_cliente_escono_solo_le_sue(): void
    {
        $this->scenario();

        $componente = Livewire::test(ListMachineUnits::class)
            ->set('tableSearch', 'Bar del Porto');

        $matricole = $componente->instance()
            ->getFilteredSortedTableQuery()
            ->pluck('serial_number')
            ->all();

        $this->assertSame(['SN-PORTO-1'], $matricole);
    }
}
